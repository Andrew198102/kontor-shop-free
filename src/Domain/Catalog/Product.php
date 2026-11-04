<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Catalog;

/**
 * Artikel-Entity (Abschnitt 5.3 des Umsetzungsplans). Reines Datenobjekt ohne
 * WordPress-Abhaengigkeit - Persistenz macht ProductRepository.
 */
final class Product
{
    public const DISCOUNT_NONE    = 'none';
    public const DISCOUNT_PERCENT = 'percent';
    public const DISCOUNT_FIXED   = 'fixed';

    public function __construct(
        public readonly ?int $id,
        public readonly int $warehouse_id,
        public readonly ?int $category_id,
        public readonly string $sku,
        public readonly string $name,
        public readonly string $description,
        public readonly float $price,
        public readonly string $member_discount_type,
        public readonly float $member_discount_value,
        public readonly ?int $image_id,
        public readonly bool $active,
        public readonly ?float $weight,
        public readonly ?float $tax_rate = null,
        public readonly bool $archived = false,
        public readonly ?int $min_stock = null,
        public readonly string $slug = '',
        public readonly string $gtin = '',
        public readonly string $brand = '',
        public readonly string $image_alt = '',
        public readonly ?string $content_updated_at = null,
        /** @var array<int, array{name: string, values: array<int, string>}> Merkmale (leer = Artikel ohne Varianten) */
        public readonly array $variant_options = [],
        /** Inhalt fuer den Grundpreis (z. B. 500 bei 500 g), gilt auch fuer Varianten ohne eigenen Inhalt */
        public readonly ?float $unit_amount = null,
        /** Einheit des Inhalts (UnitPrice::units()), leer = kein Grundpreis */
        public readonly string $unit_type = '',
        /** Auslaufartikel: wird nicht nachbestellt (keine Verfuegbarkeitsanfragen, Hinweis im Shop) */
        public readonly bool $discontinued = false,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function from_row(array $row): self
    {
        return new self(
            isset($row['id']) ? (int) $row['id'] : null,
            (int) $row['warehouse_id'],
            isset($row['category_id']) && $row['category_id'] !== null ? (int) $row['category_id'] : null,
            (string) ($row['sku'] ?? ''),
            (string) $row['name'],
            (string) ($row['description'] ?? ''),
            (float) $row['price'],
            (string) ($row['member_discount_type'] ?? self::DISCOUNT_NONE),
            (float) ($row['member_discount_value'] ?? 0),
            isset($row['image_id']) && $row['image_id'] !== null ? (int) $row['image_id'] : null,
            (bool) ($row['active'] ?? true),
            isset($row['weight']) && $row['weight'] !== null ? (float) $row['weight'] : null,
            isset($row['tax_rate']) && $row['tax_rate'] !== null && $row['tax_rate'] !== '' ? (float) $row['tax_rate'] : null,
            (bool) ($row['archived'] ?? false),
            isset($row['min_stock']) && $row['min_stock'] !== null && $row['min_stock'] !== '' ? (int) $row['min_stock'] : null,
            (string) ($row['slug'] ?? ''),
            (string) ($row['gtin'] ?? ''),
            (string) ($row['brand'] ?? ''),
            (string) ($row['image_alt'] ?? ''),
            isset($row['content_updated_at']) && $row['content_updated_at'] !== null && $row['content_updated_at'] !== '' ? (string) $row['content_updated_at'] : null,
            VariantMatrix::decode(isset($row['variant_options']) ? (string) $row['variant_options'] : null),
            isset($row['unit_amount']) && $row['unit_amount'] !== null && $row['unit_amount'] !== '' ? (float) $row['unit_amount'] : null,
            UnitPrice::is_unit((string) ($row['unit_type'] ?? '')) ? (string) $row['unit_type'] : '',
            (bool) ($row['discontinued'] ?? false)
        );
    }

    /**
     * Im Shop kaufbar: aktiv und nicht archiviert. Archivierte Artikel bleiben
     * (samt Bestandsprotokoll und Bestellhistorie) erhalten, sind aber
     * ueberall im Shop ausgeblendet.
     */
    public function is_sellable(): bool
    {
        return $this->active && !$this->archived;
    }

    /** Artikel mit Merkmalen: gekauft wird immer eine seiner Varianten. */
    public function has_variants(): bool
    {
        return $this->variant_options !== [];
    }

    /**
     * Normalpreis - der Variante, falls sie einen eigenen hat, sonst der des Artikels.
     */
    public function list_price(?Variant $variant = null): float
    {
        return $variant !== null && $variant->price !== null ? $variant->price : $this->price;
    }

    /**
     * Effektiver Verkaufspreis (Abschnitt 6.2): Der Mitgliedsrabatt wirkt nur,
     * wenn $is_member true ist; Prozentrabatt auf den Listenpreis, Fixbetrag
     * abgezogen - beides nie unter 0 Euro. Pro Artikel ist immer nur eine
     * Rabattart aktiv (member_discount_type); sie gilt fuer alle Varianten,
     * jeweils auf deren Preis.
     */
    public function price_for(bool $is_member, ?Variant $variant = null): float
    {
        $price = $this->list_price($variant);

        if (!$is_member) {
            return $price;
        }

        return match ($this->member_discount_type) {
            self::DISCOUNT_PERCENT => round(max(0.0, $price * (1 - $this->member_discount_value / 100)), 2),
            self::DISCOUNT_FIXED   => round(max(0.0, $price - $this->member_discount_value), 2),
            default                => $price,
        };
    }

    /**
     * Inhalt fuer den Grundpreis: eigener Inhalt der Variante, sonst aus ihrem
     * Namen gelesen ("0,5 l"), sonst der Inhalt des Artikels.
     */
    public function unit_amount_for(?Variant $variant = null): ?float
    {
        if ($variant === null) {
            return $this->unit_amount;
        }

        return $variant->unit_amount
            ?? ($this->unit_type !== '' ? UnitPrice::amount_from_values($variant->options, $this->unit_type) : null)
            ?? $this->unit_amount;
    }

    /** Grundpreis als Text ("12,90 € / kg") oder leer. */
    public function unit_price_text(float $price, ?Variant $variant = null): string
    {
        return $this->unit_type === '' ? '' : UnitPrice::text($price, $this->unit_amount_for($variant), $this->unit_type);
    }
}
