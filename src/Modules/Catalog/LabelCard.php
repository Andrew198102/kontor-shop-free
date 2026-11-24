<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Catalog;

/**
 * Artikelkarte im Stil "Etikett" (Einstellungen -> Design -> Artikelkarten):
 * feste Bildbuehne, Preis als Anhaenger am Foto, Kategorie als kleine Zeile,
 * Name hoechstens zwei Zeilen und eine immer gleich hohe Kaufzeile - so sind
 * alle Karten einer Reihe gleich hoch, egal wie lang Name oder Text sind.
 *
 * Kaufzeile:
 *  - ein Merkmal (z. B. Groesse): jede Auspraegung ist ein Knopf, ein Tipp
 *    legt genau diese Variante in den Warenkorb (cart.js). Was nicht in die
 *    Zeile passt, zeigt "+N" in einer Auswahl am Foto (product.js). Die
 *    zuletzt gewaehlte Groesse merkt sich der Browser und hebt sie auf allen
 *    Karten hervor.
 *  - mehrere Merkmale: "+" oeffnet die gewohnte Auswahl am Foto.
 *  - ohne Varianten: "+" legt den Artikel direkt in den Warenkorb.
 *
 * Ohne JavaScript schickt jeder Knopf ein normales Formular mit seiner
 * Variante ab; die Karte verlinkt ausserdem auf die Artikelseite.
 */
final class LabelCard
{
    /**
     * @param array{
     *   id: int,
     *   name: string,
     *   url: string,
     *   attributes: string,
     *   image_html: string,
     *   cutout: bool,
     *   category: string,
     *   price_text: string,
     *   fine_html: string,
     *   options: array<int, array{name: string, values: array<int, string>}>,
     *   rows: array<int, array<string, mixed>>,
     *   available: bool,
     *   discontinued: bool,
     *   notes_html: string,
     *   heading: string,
     * } $card attributes = fertige, escapte Attribute des <article>; notes_html/fine_html = escaptes HTML
     */
    public static function render(array $card): string
    {
        $id      = (int) $card['id'];
        $heading = in_array($card['heading'], ['h2', 'h3'], true) ? $card['heading'] : 'h3';

        $html = '<article class="kontor-shop-product kontor-shop-product--label" id="artikel-' . $id . '" data-product-id="' . $id . '"' . $card['attributes'] . '>';

        // Bildbuehne mit Preis-Anhaenger und Hinweisen
        $html .= '<div class="kontor-shop-product__media' . ($card['cutout'] ? ' is-cutout' : '') . '" data-ks-media>' . $card['image_html'];
        $html .= '<p class="kontor-shop-product__tag" data-ks-price data-product-id="' . $id . '"><span class="kontor-shop-product__price--normal">'
            . esc_html($card['price_text']) . '</span></p>';

        if ($card['discontinued']) {
            $html .= '<span class="kontor-shop-product__badge">' . esc_html($card['available'] ? __('Last units', 'kontor-shop') : __('Sold out', 'kontor-shop')) . '</span>';
        } elseif (!$card['available']) {
            $html .= '<span class="kontor-shop-product__badge">' . esc_html__('Sold out', 'kontor-shop') . '</span>';
        }

        $html .= '<p class="kontor-shop-product__added" data-ks-added role="status" aria-live="polite" hidden></p>';
        $html .= '</div>';

        // Name und Kategorie
        $html .= '<div class="kontor-shop-product__body">';

        if ($card['category'] !== '') {
            $html .= '<span class="kontor-shop-product__category">' . esc_html($card['category']) . '</span>';
        }

        $html .= '<' . $heading . ' class="kontor-shop-product__name">'
            . ($card['url'] !== '' ? '<a class="kontor-shop-product__link" href="' . esc_url($card['url']) . '">' . esc_html($card['name']) . '</a>' : esc_html($card['name']))
            . '</' . $heading . '>';
        $html .= $card['fine_html'];
        $html .= '</div>';

        // Kaufzeile
        $html .= '<div class="kontor-shop-product__buy">';
        $html .= $card['available'] ? self::buy($card) : $card['notes_html'];

        return $html . '</div></article>';
    }

    /**
     * @param array<string, mixed> $card
     */
    private static function buy(array $card): string
    {
        $id      = (int) $card['id'];
        $name    = (string) $card['name'];
        /** @var array<int, array{name: string, values: array<int, string>}> $options */
        $options = $card['options'];
        /** @var array<int, array<string, mixed>> $rows */
        $rows    = $card['rows'];
        $sheet   = 'kontor-shop-sheet-' . $id;

        $html  = '<form class="kontor-shop-add-to-cart kontor-shop-quick" method="post" data-ks-quick>';
        $html .= '<input type="hidden" name="product_id" value="' . $id . '" />';
        $html .= '<input type="hidden" name="quantity" value="1" />';

        if ($rows === [] || $options === []) {
            $html .= '<span class="kontor-shop-quick__hint"></span>';
            $html .= '<button type="submit" class="kontor-shop-plus" aria-label="' . esc_attr(sprintf(
                /* translators: %s: product name */
                __('Add %s to cart', 'kontor-shop'),
                $name
            )) . '">' . self::icon_plus() . '</button>';

            return $html . '</form>';
        }

        if (count($options) === 1) {
            $chips = self::chips($options[0], $rows, $name);

            $html .= '<div class="kontor-shop-sizes" role="group" data-ks-sizes data-ks-size-key="' . esc_attr(self::size_key((string) $options[0]['name'])) . '" aria-label="'
                . esc_attr(sprintf(
                    /* translators: 1: product name, 2: option name such as "Size" */
                    __('%1$s: choose %2$s', 'kontor-shop'),
                    $name,
                    $options[0]['name']
                )) . '">' . $chips
                . '<button type="button" class="kontor-shop-size kontor-shop-size--more" data-ks-more aria-expanded="false" aria-controls="' . esc_attr($sheet) . '" hidden></button>'
                . '</div>';

            $html .= self::sheet($sheet, (string) $options[0]['name'], '<div class="kontor-shop-sizes kontor-shop-sizes--all">' . $chips . '</div>');

            return $html . '</form>';
        }

        // Mehrere Merkmale (z. B. Groesse und Farbe): gewohnte Auswahl am Foto.
        $html .= '<span class="kontor-shop-quick__hint">' . esc_html(implode(' · ', array_map(static fn (array $option): string => (string) $option['name'], $options))) . '</span>';
        $html .= '<button type="button" class="kontor-shop-plus" data-ks-more aria-expanded="false" aria-controls="' . esc_attr($sheet) . '" aria-label="'
            . esc_attr(sprintf(
                /* translators: %s: product name */
                __('Choose variant of %s', 'kontor-shop'),
                $name
            )) . '">' . self::icon_plus() . '</button>';
        $html .= self::sheet(
            $sheet,
            __('Choose variant', 'kontor-shop'),
            VariantPicker::render($options, $rows, 'kontor-shop-v' . $id)
                . '<button type="submit" class="kontor-shop-sheet__submit">' . esc_html__('Add to cart', 'kontor-shop') . '</button>'
        );

        return $html . '</form>';
    }

    /**
     * Ein Knopf je Auspraegung (Reihenfolge wie im Admin); ausverkaufte
     * durchgestrichen und nicht waehlbar.
     *
     * @param array{name: string, values: array<int, string>} $option
     * @param array<int, array<string, mixed>>                 $rows
     */
    private static function chips(array $option, array $rows, string $name): string
    {
        $by_value = [];

        foreach ($rows as $row) {
            $value = (string) ($row['options'][0] ?? '');

            if ($value !== '' && !isset($by_value[$value])) {
                $by_value[$value] = $row;
            }
        }

        $html = '';

        foreach ($option['values'] as $value) {
            $row = $by_value[$value] ?? null;

            if ($row === null) {
                continue;
            }

            if (!(bool) $row['available']) {
                $html .= '<button type="button" class="kontor-shop-size is-out" disabled aria-label="' . esc_attr(sprintf(
                    /* translators: %s: size or other option value */
                    __('%s – sold out', 'kontor-shop'),
                    $value
                )) . '"><s>' . esc_html($value) . '</s></button>';

                continue;
            }

            $html .= '<button type="submit" class="kontor-shop-size" name="variant_id" value="' . (int) $row['id'] . '" data-ks-size="' . esc_attr($value) . '"'
                . ' data-ks-price-label="' . esc_attr(self::price_label((float) $row['price'])) . '" aria-label="' . esc_attr(sprintf(
                    /* translators: 1: size or other option value, 2: product name */
                    __('Add %2$s in %1$s to cart', 'kontor-shop'),
                    $value,
                    $name
                )) . '">' . esc_html($value) . '</button>';
        }

        return $html;
    }

    private static function sheet(string $id, string $title, string $content): string
    {
        return '<div class="kontor-shop-product__sheet" id="' . esc_attr($id) . '" data-ks-sheet hidden>'
            . '<div class="kontor-shop-product__sheet-head"><span>' . esc_html($title) . '</span>'
            . '<button type="button" class="kontor-shop-product__sheet-close" data-ks-sheet-close aria-label="' . esc_attr__('Close', 'kontor-shop') . '">'
            . '<svg aria-hidden="true" viewBox="0 0 24 24" width="18" height="18"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button></div>'
            . $content . '</div>';
    }

    private static function icon_plus(): string
    {
        return '<svg aria-hidden="true" viewBox="0 0 24 24" width="20" height="20"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/></svg>';
    }

    /**
     * Preis fuer den Anhaenger: ganze Euro ohne ",00" ("140 €"), sonst mit
     * Cent ("19,90 €").
     */
    public static function price_label(float $price): string
    {
        $whole = abs($price - round($price)) < 0.005;

        return number_format_i18n($price, $whole ? 0 : 2) . ' €';
    }

    /**
     * Preis vor der Auswahl: "140 €" oder bei unterschiedlichen
     * Variantenpreisen "ab 19,90 €".
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public static function price_text(array $rows, float $fallback): string
    {
        [$min, $max] = VariantPicker::range($rows, $fallback);

        return ($max - $min > 0.0001 ? __('from', 'kontor-shop') . ' ' : '') . self::price_label($min);
    }

    /**
     * Schluessel, unter dem der Browser die gewaehlte Groesse merkt: Name des
     * Merkmals, kleingeschrieben ("groesse"), damit eine Farbe nie als Groesse
     * gilt. Zahlen- und Buchstabengroessen trennt product.js zusaetzlich.
     */
    public static function size_key(string $option_name): string
    {
        $key = function_exists('mb_strtolower') ? mb_strtolower(trim($option_name)) : strtolower(trim($option_name));

        return $key !== '' ? $key : 'option';
    }
}
