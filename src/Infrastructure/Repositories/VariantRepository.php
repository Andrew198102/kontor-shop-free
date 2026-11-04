<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- eigene Tabellen des Plugins, kein WP-Objektcache-Bezug

use Kontor\Shop\Domain\Catalog\ProductTools;
use Kontor\Shop\Domain\Catalog\Variant;
use Kontor\Shop\Domain\Catalog\VariantMatrix;

/**
 * wpdb-Zugriff auf wp_kontor_shop_product_variants. Varianten entstehen aus
 * den Merkmalen des Artikels (VariantMatrix::combinations()); sync() gleicht
 * die gespeicherten Varianten mit den Kombinationen ab. Zugeordnet wird ueber
 * die Werte (Gross/Klein egal), damit Bestand, Preise und Bilder erhalten
 * bleiben, wenn Merkmale ergaenzt, umsortiert oder anders geschrieben werden.
 *
 * Eine Variante mit Bestandsbewegungen, Reservierungen oder Bestellungen wird
 * nie geloescht (Bestandsprotokoll/Bestellhistorie verweisen auf sie), sondern
 * nur deaktiviert, wenn ihre Kombination wegfaellt.
 */
final class VariantRepository
{
    private function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kontor_shop_product_variants';
    }

    public function find(int $id): ?Variant
    {
        global $wpdb;

        if ($id <= 0) {
            return null;
        }

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $this->table(), $id), ARRAY_A);

        return $row ? Variant::from_row($row) : null;
    }

    /**
     * @return array<int, Variant> in Anzeigereihenfolge
     */
    public function for_product(int $product_id, bool $only_active = false): array
    {
        return $this->for_products([$product_id], $only_active)[$product_id] ?? [];
    }

    /**
     * @param array<int, int> $product_ids
     * @return array<int, array<int, Variant>> product_id => Varianten
     */
    public function for_products(array $product_ids, bool $only_active = false): array
    {
        global $wpdb;

        $ids = array_values(array_filter(array_map('intval', $product_ids)));
        $map = array_fill_keys($ids, []);

        if ($ids === []) {
            return $map;
        }

        $in   = implode(',', array_fill(0, count($ids), '%d'));
        $sql  = 'SELECT * FROM %i WHERE product_id IN (' . $in . ')' . ($only_active ? ' AND active = 1' : '') . ' ORDER BY sort_order ASC, id ASC';
        $rows = $wpdb->get_results($wpdb->prepare($sql, $this->table(), ...$ids), ARRAY_A) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- nur %d-Platzhalter und Literale

        foreach ($rows as $row) {
            $variant = Variant::from_row($row);
            $map[$variant->product_id][] = $variant;
        }

        return $map;
    }

    /**
     * Wird die Variante irgendwo referenziert (Bestand, Reservierung, Bestellung)?
     */
    public function in_use(int $variant_id): bool
    {
        global $wpdb;

        foreach (['stock_movements', 'stock_reservations', 'order_items'] as $table) {
            if ($wpdb->get_var($wpdb->prepare('SELECT 1 FROM %i WHERE variant_id = %d LIMIT 1', $wpdb->prefix . 'kontor_shop_' . $table, $variant_id)) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gleicht die Varianten eines Artikels mit seinen Merkmalen ab.
     *
     * @param array<int, array{name: string, values: array<int, string>}> $options bereinigte Merkmale (leer = keine Varianten)
     * @param array<int, array<string, mixed>> $rows Formularzeilen: values, sku, gtin, price, weight, unit_amount, image_id, active, initial_stock
     * @return array{created: array<int, array{id: int, initial_stock: int}>, before: string, after: string}
     */
    public function sync(int $product_id, array $options, array $rows): array
    {
        global $wpdb;

        $existing = [];
        $by_id    = [];
        $before   = $this->for_product($product_id);

        foreach ($before as $variant) {
            $existing[$variant->key()] ??= $variant;
            $by_id[(int) $variant->id] = $variant;
        }

        $submitted = [];

        foreach ($rows as $row) {
            $values = is_array($row['values'] ?? null) ? array_map('strval', $row['values']) : [];
            $submitted[VariantMatrix::key($values)] ??= $row;
        }

        $kept    = [];
        $created = [];

        $combos  = VariantMatrix::combinations($options);
        $wanted  = array_fill_keys(array_map([VariantMatrix::class, 'key'], $combos), true);

        foreach ($combos as $index => $values) {
            $key = VariantMatrix::key($values);
            $row = $submitted[$key] ?? [];

            // Umbenannter Wert ("Rot" -> "Dunkelrot"): die Zeile bringt die ID der
            // bisherigen Variante mit - dieselbe Variante (Bestand, Preis, Bild) bleibt.
            $renamed_id = (int) ($row['id'] ?? 0);

            if (!isset($existing[$key]) && isset($by_id[$renamed_id]) && !isset($kept[$renamed_id]) && !isset($wanted[$by_id[$renamed_id]->key()])) {
                $existing[$key] = $by_id[$renamed_id];
            }
            $data = [
                'product_id'  => $product_id,
                'option1'     => (string) ($values[0] ?? ''),
                'option2'     => (string) ($values[1] ?? ''),
                'option3'     => (string) ($values[2] ?? ''),
                'sku'         => substr((string) ($row['sku'] ?? ($existing[$key]->sku ?? '')), 0, 64),
                'gtin'        => substr((string) ($row['gtin'] ?? ($existing[$key]->gtin ?? '')), 0, 14),
                'price'       => $this->nullable_float($row, 'price', $existing[$key]->price ?? null),
                'weight'      => $this->nullable_float($row, 'weight', $existing[$key]->weight ?? null),
                'unit_amount' => $this->nullable_float($row, 'unit_amount', $existing[$key]->unit_amount ?? null),
                'image_id'    => array_key_exists('image_id', $row)
                    ? ((int) $row['image_id'] > 0 ? (int) $row['image_id'] : null)
                    : ($existing[$key]->image_id ?? null),
                'sort_order'  => $index,
                // Eine frueher weggefallene Kombination (sort_order >= 10000), die wiederkommt, ist wieder aktiv.
                'active'      => array_key_exists('active', $row)
                    ? (empty($row['active']) ? 0 : 1)
                    : (int) (($existing[$key]->active ?? true) || ($existing[$key]->sort_order ?? 0) >= 10000),
            ];

            if (isset($existing[$key])) {
                $id = (int) $existing[$key]->id;
                $wpdb->update($this->table(), $data, ['id' => $id]);
                $kept[$id] = true;

                continue;
            }

            if ($wpdb->insert($this->table(), $data) === false) {
                throw new \RuntimeException('Variante konnte nicht gespeichert werden: ' . $wpdb->last_error); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Meldung geht in die Fehlerbehandlung/das Protokoll, nicht als HTML
            }

            $id        = (int) $wpdb->insert_id;
            $kept[$id] = true;
            $created[] = ['id' => $id, 'initial_stock' => max(0, (int) ($row['initial_stock'] ?? 0))];
        }

        foreach ($before as $variant) {
            $id = (int) $variant->id;

            if (isset($kept[$id])) {
                continue;
            }

            if ($this->in_use($id)) {
                $wpdb->update($this->table(), ['active' => 0, 'sort_order' => 10000 + $variant->sort_order], ['id' => $id]);
            } else {
                $wpdb->delete($this->table(), ['id' => $id]);
            }
        }

        return [
            'created' => $created,
            'before'  => self::summary($before),
            'after'   => self::summary($this->for_product($product_id)),
        ];
    }

    /**
     * Alle Varianten eines geloeschten Artikels entfernen.
     */
    public function delete_for_product(int $product_id): void
    {
        global $wpdb;

        $wpdb->delete($this->table(), ['product_id' => $product_id]);
    }

    /**
     * Varianten beim Duplizieren mitkopieren - ohne Artikelnummer/GTIN
     * (je Artikel einmalig) und ohne Bestand.
     */
    public function copy(int $from_product_id, int $to_product_id): void
    {
        global $wpdb;

        foreach ($this->for_product($from_product_id) as $variant) {
            if (!$variant->active && $variant->sort_order >= 10000) {
                continue; // nicht mehr verwendete Kombination
            }

            $wpdb->insert($this->table(), [
                'product_id'  => $to_product_id,
                'option1'     => (string) ($variant->options[0] ?? ''),
                'option2'     => (string) ($variant->options[1] ?? ''),
                'option3'     => (string) ($variant->options[2] ?? ''),
                'price'       => $variant->price,
                'weight'      => $variant->weight,
                'unit_amount' => $variant->unit_amount,
                'image_id'    => $variant->image_id,
                'sort_order'  => $variant->sort_order,
                'active'      => $variant->active ? 1 : 0,
            ]);
        }
    }

    /**
     * Eigene Variantenpreise mitziehen, wenn Artikelpreise per Sammelaktion
     * um einen Prozentsatz geaendert werden.
     */
    public function adjust_prices(int $product_id, float $percent): void
    {
        global $wpdb;

        foreach ($this->for_product($product_id) as $variant) {
            if ($variant->price !== null) {
                $wpdb->update($this->table(), ['price' => ProductTools::adjust_price($variant->price, $percent)], ['id' => (int) $variant->id]);
            }
        }
    }

    /**
     * Kurzfassung fuer das Aenderungsprotokoll ("S 29.90, M, L [inaktiv]").
     *
     * @param array<int, Variant> $variants
     */
    public static function summary(array $variants): string
    {
        return implode('; ', array_map(static function (Variant $variant): string {
            $parts = [$variant->label()];

            if ($variant->price !== null) {
                $parts[] = number_format($variant->price, 2, '.', '');
            }

            if ($variant->sku !== '') {
                $parts[] = '#' . $variant->sku;
            }

            if ($variant->image_id !== null) {
                $parts[] = 'Bild ' . $variant->image_id;
            }

            return implode(' ', $parts) . ($variant->active ? '' : ' [inaktiv]');
        }, $variants));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function nullable_float(array $row, string $key, ?float $fallback): ?float
    {
        if (!array_key_exists($key, $row)) {
            return $fallback;
        }

        return $row[$key] === null || $row[$key] === '' ? null : max(0.0, (float) $row[$key]);
    }
}
