<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Catalog;

/**
 * Variantenauswahl im Shop (Katalogkarte und Artikelseite): je Merkmal ein
 * Auswahlfeld, dazu die Daten aller Varianten als JSON fuer
 * assets/frontend/product.js (Preis, Grundpreis, Verfuegbarkeit und Bild
 * wechseln mit der Auswahl). Ohne JavaScript bleibt die Auswahl sichtbar;
 * der Warenkorb verlangt dann eine Variante (Server prueft ohnehin).
 */
final class VariantPicker
{
    /**
     * @param array<int, array{name: string, values: array<int, string>}> $options Merkmale des Artikels
     * @param array<int, array<string, mixed>> $rows Infrastructure\Catalog\VariantRows::build()
     */
    public static function render(array $options, array $rows, string $id_prefix): string
    {
        if ($options === [] || $rows === []) {
            return '';
        }

        $single = count($rows) === 1 ? $rows[0] : null;
        $data   = array_map(static fn (array $row): array => [
            'id'  => (int) $row['id'],
            'o'   => array_values($row['options']),
            'p'   => number_format_i18n((float) $row['price'], 2) . ' €',
            'u'   => (string) $row['unit_price'],
            'ok'  => (bool) $row['available'],
            'img' => $row['image'],
        ], $rows);

        $html = '<div class="kontor-shop-variants" data-ks-variant-data="' . esc_attr((string) wp_json_encode($data)) . '">';

        foreach ($options as $index => $option) {
            // Nur Werte, die in einer aktiven Variante vorkommen (Reihenfolge wie im Admin).
            $used = [];

            foreach ($rows as $row) {
                $used[(string) ($row['options'][$index] ?? '')] = true;
            }

            $field_id = $id_prefix . '-o' . $index;

            $html .= '<div class="kontor-shop-variants__field"><label for="' . esc_attr($field_id) . '">' . esc_html($option['name']) . '</label>';
            $html .= '<select id="' . esc_attr($field_id) . '" data-ks-option="' . (int) $index . '">';
            $html .= '<option value="">' . esc_html__('Please select', 'kontor-shop') . '</option>';

            foreach ($option['values'] as $value) {
                if (!isset($used[$value])) {
                    continue;
                }

                $selected = $single !== null && ($single['options'][$index] ?? null) === $value;
                $html    .= '<option value="' . esc_attr($value) . '"' . ($selected ? ' selected' : '') . '>' . esc_html($value) . '</option>';
            }

            $html .= '</select></div>';
        }

        $html .= '<p class="kontor-shop-variants__status" data-ks-variant-status role="status" aria-live="polite"></p>';
        $html .= '</div>';
        $html .= '<input type="hidden" name="variant_id" value="' . esc_attr($single !== null ? (string) $single['id'] : '') . '" data-ks-variant-id />';

        return $html;
    }

    /**
     * Preisangabe vor der Auswahl: "19,90 €" oder bei unterschiedlichen
     * Variantenpreisen "ab 19,90 €".
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public static function price_text(array $rows, float $fallback): string
    {
        [$min, $max] = self::range($rows, $fallback);

        return ($max - $min > 0.0001 ? __('from', 'kontor-shop') . ' ' : '') . number_format_i18n($min, 2) . ' €';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{0: float, 1: float} [niedrigster, hoechster] Preis
     */
    public static function range(array $rows, float $fallback): array
    {
        if ($rows === []) {
            return [$fallback, $fallback];
        }

        $prices = array_map(static fn (array $row): float => (float) $row['price'], $rows);

        return [min($prices), max($prices)];
    }

    /**
     * Grundpreis vor der Auswahl - nur, wenn er fuer alle Varianten gleich ist.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public static function common_unit_price(array $rows): string
    {
        $texts = array_values(array_unique(array_map(static fn (array $row): string => (string) $row['unit_price'], $rows)));

        return count($texts) === 1 ? $texts[0] : '';
    }
}
