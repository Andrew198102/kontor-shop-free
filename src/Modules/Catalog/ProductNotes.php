<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Catalog;

/**
 * Verfuegbarkeits-Hinweise auf Katalogkarte und Artikelseite, die nicht vom
 * Besucher abhaengen (bleiben cachebar): Auslaufartikel und "derzeit nicht
 * lieferbar". Was ein nicht lieferbarer Artikel zusaetzlich zeigt (z. B. ein
 * Formular "Benachrichtigen, wenn verfuegbar"), liefern Erweiterungen ueber
 * den Filter kontor_shop/product/unavailable_html (siehe unavailable_extra()).
 */
final class ProductNotes
{
    /**
     * Hinweis bei Auslaufartikeln: solange Bestand da ist "nur solange der
     * Vorrat reicht", danach "wird nicht nachbestellt".
     */
    public static function discontinued(bool $available): string
    {
        return $available
            ? '<p class="kontor-shop-product__last-units">' . esc_html__('Discontinued product – while supplies last', 'kontor-shop') . '</p>'
            : '<p class="kontor-shop-product__sold-out">' . esc_html__('Sold out – won\'t be reordered', 'kontor-shop') . '</p>';
    }

    public static function unavailable(): string
    {
        return '<p class="kontor-shop-product__sold-out">' . esc_html__('Currently unavailable', 'kontor-shop') . '</p>';
    }

    /**
     * Zusatz unter einem (ganz oder teilweise) nicht lieferbaren Artikel, der
     * kein Auslaufartikel ist.
     *
     * @param array<int, array<string, mixed>> $variant_rows Infrastructure\Catalog\VariantRows::build() bzw. PublicProduct::$variants
     * @param bool   $sold_out true = ganzer Artikel ausverkauft, false = lieferbar, aber evtl. einzelne Varianten nicht
     * @param string $context  'card' (Katalogkarte) oder 'detail' (Artikelseite)
     */
    public static function unavailable_extra(int $product_id, string $name, array $variant_rows, bool $sold_out, string $context): string
    {
        /**
         * Filter kontor_shop/product/unavailable_html: HTML unter einem nicht
         * (oder nur teilweise) lieferbaren Artikel, z. B. das Formular
         * "Benachrichtigen, wenn verfuegbar". Laeuft nicht bei
         * Auslaufartikeln. Muss vom Besucher unabhaengig sein (Seiten-Cache).
         * Standard: '' (nur der Hinweis "Derzeit nicht lieferbar").
         *
         * @param string                           $html         bereits escaptes HTML
         * @param int                              $product_id
         * @param string                           $name         Artikelname
         * @param array<int, array<string, mixed>> $variant_rows Varianten mit id, label, available
         * @param bool                             $sold_out     ganzer Artikel ausverkauft
         * @param string                           $context      'card' oder 'detail'
         */
        return (string) apply_filters('kontor_shop/product/unavailable_html', '', $product_id, $name, $variant_rows, $sold_out, $context);
    }
}
