<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Catalog;

/**
 * Was tatsaechlich in Warenkorb und Bestellung landet: ein Artikel, bei
 * Artikeln mit Merkmalen zusammen mit genau einer seiner Varianten. Buendelt
 * Name, Preis, Bild und Grundpreis, damit Warenkorb, Kasse und Mails nicht
 * jeweils selbst zwischen Artikel und Variante unterscheiden muessen.
 */
final class SellableItem
{
    /** Trenner zwischen Artikelname und Variante ("Pullover – M / Rot"). */
    public const NAME_GLUE = ' – ';

    public function __construct(
        public readonly Product $product,
        public readonly ?Variant $variant = null,
    ) {
    }

    public function product_id(): int
    {
        return (int) $this->product->id;
    }

    /** 0 = Artikel ohne Varianten */
    public function variant_id(): int
    {
        return $this->variant !== null ? (int) $this->variant->id : 0;
    }

    public function variant_label(): string
    {
        return $this->variant?->label() ?? '';
    }

    /** Anzeigename, auch der Snapshot in der Bestellposition. */
    public function name(): string
    {
        $label = $this->variant_label();

        return $label !== '' ? $this->product->name . self::NAME_GLUE . $label : $this->product->name;
    }

    /**
     * Kaufbar: Artikel aktiv und nicht archiviert; bei Artikeln mit Merkmalen
     * nur mit einer aktiven Variante dieses Artikels, ohne Merkmale nur ohne
     * Variante (ein alter Warenkorb-Eintrag ohne Variante passt also nicht mehr,
     * sobald der Artikel Varianten bekommt).
     */
    public function is_sellable(): bool
    {
        if (!$this->product->is_sellable()) {
            return false;
        }

        if (!$this->product->has_variants()) {
            return $this->variant === null;
        }

        return $this->variant !== null && $this->variant->active && $this->variant->product_id === $this->product->id;
    }

    public function list_price(): float
    {
        return $this->product->list_price($this->variant);
    }

    public function price_for(bool $is_member): float
    {
        return $this->product->price_for($is_member, $this->variant);
    }

    /** Bild der Variante, sonst das Hauptbild des Artikels. */
    public function image_id(): ?int
    {
        return $this->variant?->image_id ?? $this->product->image_id;
    }

    public function sku(): string
    {
        return $this->variant !== null && $this->variant->sku !== '' ? $this->variant->sku : $this->product->sku;
    }

    public function weight(): ?float
    {
        return $this->variant?->weight ?? $this->product->weight;
    }

    /** Grundpreis zum angegebenen (ggf. Mitglieds-)Preis, sonst leer. */
    public function unit_price_text(float $price): string
    {
        return $this->product->unit_price_text($price, $this->variant);
    }
}
