<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Catalog;

/**
 * Eine Variante eines Artikels = eine Kombination seiner Merkmale (z. B.
 * "M / Rot"). Hat eigenen Bestand (stock_movements.variant_id) und kann
 * Preis, Gewicht, Inhalt (Grundpreis) und Bild des Artikels ueberschreiben -
 * leer (null) heisst: Wert des Artikels gilt. Reines Datenobjekt.
 */
final class Variant
{
    /**
     * @param array<int, string> $options Werte in der Reihenfolge der Merkmale des Artikels
     */
    public function __construct(
        public readonly ?int $id,
        public readonly int $product_id,
        public readonly array $options,
        public readonly string $sku = '',
        public readonly string $gtin = '',
        public readonly ?float $price = null,
        public readonly ?float $weight = null,
        public readonly ?float $unit_amount = null,
        public readonly ?int $image_id = null,
        public readonly int $sort_order = 0,
        public readonly bool $active = true,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function from_row(array $row): self
    {
        $nullable_float = static fn (string $key): ?float => isset($row[$key]) && $row[$key] !== null && $row[$key] !== '' ? (float) $row[$key] : null;

        return new self(
            isset($row['id']) ? (int) $row['id'] : null,
            (int) $row['product_id'],
            array_values(array_filter(
                [(string) ($row['option1'] ?? ''), (string) ($row['option2'] ?? ''), (string) ($row['option3'] ?? '')],
                static fn (string $value): bool => $value !== ''
            )),
            (string) ($row['sku'] ?? ''),
            (string) ($row['gtin'] ?? ''),
            $nullable_float('price'),
            $nullable_float('weight'),
            $nullable_float('unit_amount'),
            isset($row['image_id']) && $row['image_id'] !== null && (int) $row['image_id'] > 0 ? (int) $row['image_id'] : null,
            (int) ($row['sort_order'] ?? 0),
            (bool) ($row['active'] ?? true)
        );
    }

    /** "M / Rot" */
    public function label(): string
    {
        return VariantMatrix::label($this->options);
    }

    public function key(): string
    {
        return VariantMatrix::key($this->options);
    }
}
