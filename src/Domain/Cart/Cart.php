<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Cart;

/**
 * Warenkorb (Abschnitt 5.2: "Session-/Cookie-basiert"). Reine Datenstruktur
 * ohne WordPress-Abhaengigkeit - Persistenz macht Infrastructure\Cart\
 * CookieCartStore, Bestandsobergrenzen prueft der aufrufende Modul-Code
 * (Abschnitt 3, Ziel 5: "maximal die tatsaechlich verfuegbare Menge").
 *
 * Positionen sind je Artikel UND Variante getrennt (Pullover M und Pullover L
 * sind zwei Positionen). Schluessel: "12" ohne Variante, "12:5" mit Variante 5 -
 * aeltere Cookies (nur Artikel-IDs) bleiben damit gueltig.
 */
final class Cart
{
    /** @var array<string, CartItem> Schluessel => CartItem */
    private array $items = [];

    public static function key(int $product_id, int $variant_id = 0): string
    {
        return $variant_id > 0 ? $product_id . ':' . $variant_id : (string) $product_id;
    }

    /**
     * @return array{0: int, 1: int} [product_id, variant_id]
     */
    public static function parse_key(string $key): array
    {
        $parts = explode(':', $key, 2);

        return [max(0, (int) $parts[0]), max(0, (int) ($parts[1] ?? 0))];
    }

    public function add(int $product_id, int $quantity, int $variant_id = 0): void
    {
        $quantity = max(0, $quantity);

        if ($quantity === 0) {
            return;
        }

        $this->set_quantity($product_id, $this->quantity_for($product_id, $variant_id) + $quantity, $variant_id);
    }

    public function set_quantity(int $product_id, int $quantity, int $variant_id = 0): void
    {
        $quantity = max(0, $quantity);
        $key      = self::key($product_id, $variant_id);

        if ($quantity === 0 || $product_id <= 0) {
            unset($this->items[$key]);

            return;
        }

        $this->items[$key] = new CartItem($product_id, $quantity, max(0, $variant_id));
    }

    public function remove(int $product_id, int $variant_id = 0): void
    {
        unset($this->items[self::key($product_id, $variant_id)]);
    }

    public function quantity_for(int $product_id, int $variant_id = 0): int
    {
        return $this->items[self::key($product_id, $variant_id)]->quantity ?? 0;
    }

    /**
     * @return array<int, CartItem>
     */
    public function items(): array
    {
        return array_values($this->items);
    }

    public function is_empty(): bool
    {
        return $this->items === [];
    }

    public function total_quantity(): int
    {
        $total = 0;

        foreach ($this->items as $item) {
            $total += $item->quantity;
        }

        return $total;
    }

    /**
     * @return array<int|string, int> Schluessel => quantity
     */
    public function to_array(): array
    {
        $out = [];

        foreach ($this->items as $key => $item) {
            $out[$key] = $item->quantity;
        }

        return $out;
    }

    /**
     * @param array<int|string, mixed> $data Schluessel ("12" oder "12:5") => quantity
     */
    public static function from_array(array $data): self
    {
        $cart = new self();

        foreach ($data as $key => $quantity) {
            [$product_id, $variant_id] = self::parse_key((string) $key);

            $cart->set_quantity($product_id, (int) $quantity, $variant_id);
        }

        return $cart;
    }
}
