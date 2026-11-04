<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Catalog;

/**
 * Artikel-Kategorie (frei vom Shop-Admin angelegt, keine feste Liste im
 * Code - das Plugin bleibt projektunabhaengig) und einem Shoptyp
 * untergeordnet (Domain\Catalog\ShopType). accent_color ist optional:
 * gesetzt, ueberschreibt sie im Frontend die Farbe des Shoptyps bzw. die
 * Hauptfarbe des Shops (Settings\Schema, design.primary_color) fuer Artikel
 * dieser Kategorie.
 */
final class Category
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $accent_color,
        public readonly int $sort_order,
        public readonly bool $active,
        public readonly ?int $shop_type_id = null,
        public readonly ?int $parent_id = null,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function from_row(array $row): self
    {
        return new self(
            isset($row['id']) ? (int) $row['id'] : null,
            (string) $row['name'],
            (string) $row['slug'],
            isset($row['accent_color']) && $row['accent_color'] !== null && $row['accent_color'] !== ''
                ? (string) $row['accent_color']
                : null,
            (int) ($row['sort_order'] ?? 0),
            (bool) ($row['active'] ?? true),
            isset($row['shop_type_id']) && $row['shop_type_id'] !== null && $row['shop_type_id'] !== '' ? (int) $row['shop_type_id'] : null,
            isset($row['parent_id']) && $row['parent_id'] !== null && $row['parent_id'] !== '' && (int) $row['parent_id'] > 0 ? (int) $row['parent_id'] : null
        );
    }
}
