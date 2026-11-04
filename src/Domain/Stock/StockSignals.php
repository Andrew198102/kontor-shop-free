<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Stock;

/**
 * Was die Bestandsliste zu einem Artikel sagt: ausverkauft (auch nur eine
 * Groesse), knapp, negativ, wartende Kunden - und ob er deshalb unter
 * "Handlungsbedarf" gehoert. Reine Rechenlogik ohne WordPress, damit Liste,
 * Kennzahlen und Tests dieselben Regeln verwenden.
 */
final class StockSignals
{
    public const VIEW_ATTENTION = 'attention';
    public const VIEW_ALL       = 'all';
    public const VIEW_OUT       = 'out';
    public const VIEW_LOW       = 'low';
    public const VIEW_REQUESTS  = 'requests';
    public const VIEW_NO_MIN    = 'nomin';
    public const VIEW_HIDDEN    = 'hidden';

    /**
     * @param array<int, int> $variant_balances Bestand je AKTIVER Variante (leer = Artikel ohne Varianten)
     */
    public function __construct(
        public readonly int $balance,
        public readonly ?int $min_stock,
        public readonly array $variant_balances = [],
        public readonly int $bound = 0,
        public readonly int $requests = 0,
        public readonly bool $sellable = true,
        public readonly bool $discontinued = false,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public static function views(): array
    {
        return [self::VIEW_ATTENTION, self::VIEW_ALL, self::VIEW_OUT, self::VIEW_LOW, self::VIEW_REQUESTS, self::VIEW_NO_MIN, self::VIEW_HIDDEN];
    }

    public function status(): string
    {
        return StockStatus::classify($this->balance, $this->min_stock);
    }

    /** Anzahl aktiver Varianten ohne Bestand. */
    public function empty_variants(): int
    {
        return count(array_filter($this->variant_balances, static fn (int $b): bool => $b <= 0));
    }

    private function low_variants(): int
    {
        if ($this->min_stock === null || $this->min_stock <= 0) {
            return 0;
        }

        $min = $this->min_stock;

        return count(array_filter($this->variant_balances, static fn (int $b): bool => $b > 0 && $b <= $min));
    }

    /** Ganz ausverkauft oder mindestens eine Groesse leer. */
    public function is_out(): bool
    {
        return $this->status() === StockStatus::OUT || $this->empty_variants() > 0;
    }

    public function is_low(): bool
    {
        return !$this->is_out() && ($this->status() === StockStatus::LOW || $this->low_variants() > 0);
    }

    /** Negativer Bestand ist immer ein Buchungsfehler, der geklaert werden muss. */
    public function is_negative(): bool
    {
        return $this->balance < 0 || array_filter($this->variant_balances, static fn (int $b): bool => $b < 0) !== [];
    }

    /** Physisch im Regal: Bestand plus verkaufte, aber noch nicht versendete/abgeholte Ware. */
    public function on_shelf(): int
    {
        return $this->balance + $this->bound;
    }

    /**
     * Braucht der Artikel jetzt Aufmerksamkeit? Auslaufartikel werden nicht
     * nachbestellt - leer/knapp ist bei ihnen kein Handlungsbedarf.
     */
    public function needs_attention(): bool
    {
        if (!$this->sellable) {
            return false;
        }

        return $this->is_negative() || $this->requests > 0 || (!$this->discontinued && ($this->is_out() || $this->is_low()));
    }

    /** Sortierung "Handlungsbedarf": je kleiner, desto dringender. */
    public function severity(): int
    {
        return match (true) {
            $this->is_negative()                     => 0,
            $this->status() === StockStatus::OUT     => 1,
            $this->is_out()                          => 2,
            $this->is_low()                          => 3,
            $this->requests > 0                      => 4,
            default                                  => 5,
        };
    }

    public function matches(string $view): bool
    {
        return match ($view) {
            self::VIEW_ATTENTION => $this->needs_attention(),
            self::VIEW_OUT       => $this->sellable && $this->is_out(),
            self::VIEW_LOW       => $this->sellable && $this->is_low(),
            self::VIEW_REQUESTS  => $this->sellable && $this->requests > 0,
            self::VIEW_NO_MIN    => $this->sellable && ($this->min_stock === null || $this->min_stock <= 0),
            self::VIEW_HIDDEN    => !$this->sellable,
            default              => true,
        };
    }
}
