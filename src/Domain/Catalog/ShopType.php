<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Catalog;

/**
 * Shoptyp (z. B. "Jagd", "Regulaer"): oberste Gliederungsebene des Katalogs.
 * Ein Shoptyp hat eine eigene Farbe und - optional - eine eigene
 * Frontend-Seite mit `[kontor_shop_catalog shop="<slug>"]`. Kategorien
 * (Domain\Catalog\Category) gehoeren genau einem Shoptyp, Artikel genau
 * einer Kategorie und erben darueber ihren Shoptyp. Frei vom Shop-Admin
 * angelegt, keine feste Liste im Code (Abschnitt 5.1).
 */
final class ShopType
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $accent_color,
        public readonly int $sort_order,
        public readonly bool $active,
        public readonly ?int $page_id,
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
            isset($row['page_id']) && $row['page_id'] !== null && $row['page_id'] !== '' ? (int) $row['page_id'] : null
        );
    }
}
