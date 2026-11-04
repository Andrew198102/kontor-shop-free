<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Seo;

use Kontor\Shop\Domain\Catalog\VariantMatrix;

/**
 * Wann aendert sich, was Suchmaschinen sehen? Nur dann darf
 * products.content_updated_at (= lastmod) steigen und der Meridian-Cache
 * geleert werden - nicht bei jedem verkauften Stueck.
 *
 * Relevant: Name, Beschreibung, Preis, Bild(-Alt), Kategorien, Aktiv-/Archiv-
 * Status, Auslaufartikel (Hinweis auf der Seite), Slug, SKU, Marke, GTIN, Steuersatz (steht als Text auf der Seite),
 * Merkmale/Grundpreis-Inhalt und der Wechsel der Verfuegbarkeit zwischen 0
 * und > 0. Aenderungen an Varianten und Galerie meldet ProductsPage separat. Nicht relevant:
 * Mitgliedsrabatt (personalisiert), Lager, Gewicht, Mindestbestand.
 */
final class ContentChange
{
    private const FIELDS = [
        'name', 'description', 'price', 'image_id', 'image_alt', 'category_id',
        'active', 'archived', 'slug', 'sku', 'brand', 'gtin', 'tax_rate',
        'variant_options', 'unit_amount', 'unit_type', 'discontinued',
    ];

    /**
     * @param array<string, mixed> $row  Artikelspalten (fehlende Schluessel zaehlen als leer)
     * @param array<int, int> $category_ids
     * @return array<string, string>
     */
    public static function snapshot(array $row, array $category_ids = []): array
    {
        $snapshot = [];

        foreach (self::FIELDS as $field) {
            $value = $row[$field] ?? null;

            $snapshot[$field] = match ($field) {
                'price'                   => number_format((float) $value, 2, '.', ''),
                'tax_rate'                => $value === null || $value === '' ? '' : (string) (float) $value,
                'image_id', 'category_id' => $value === null || $value === '' || (int) $value === 0 ? '' : (string) (int) $value,
                'active'                  => $value === null ? '0' : ((bool) $value ? '1' : '0'),
                'archived', 'discontinued' => (bool) $value ? '1' : '0',
                'unit_amount'             => $value === null || $value === '' ? '' : (string) (float) $value,
                'variant_options'         => (string) VariantMatrix::encode(is_array($value) ? $value : VariantMatrix::decode($value === null ? null : (string) $value)),
                default                   => trim((string) $value),
            };
        }

        $ids = array_values(array_unique(array_map('intval', $category_ids)));
        sort($ids);
        $snapshot['category_ids'] = implode(',', $ids);

        return $snapshot;
    }

    /**
     * @param array<string, string> $old Ergebnis von snapshot()
     * @param array<string, string> $new
     */
    public static function differs(array $old, array $new): bool
    {
        return $old !== $new;
    }

    /** Verfuegbar = Bestand abzueglich Reservierungen > 0. */
    public static function is_available(int $stock, int $reserved = 0): bool
    {
        return $stock - max(0, $reserved) > 0;
    }

    /**
     * Wechselt der Bestand durch diese Buchung zwischen 0 und > 0?
     * (Reservierungen zaehlen hier bewusst nicht: sie sind fluechtig, die
     * Verfuegbarkeit im JSON-LD wird beim Erzeugen live berechnet.)
     */
    public static function availability_flips(int $balance_before, int $delta): bool
    {
        return ($balance_before > 0) !== ($balance_before + $delta > 0);
    }
}
