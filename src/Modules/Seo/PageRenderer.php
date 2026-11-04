<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Seo;

use Kontor\Shop\Domain\Seo\PublicProduct;
use Kontor\Shop\Modules\Catalog\ProductNotes;
use Kontor\Shop\Modules\Catalog\VariantPicker;

/**
 * Serverseitig gerenderter Inhalt der Artikelseite - ohne JavaScript
 * lesbar (weitere virtuelle Seiten, z. B. Pro: Kategorieseiten, nutzen
 * breadcrumbs() und maybe_h1()). Die H1 ist der Seitentitel (das Theme gibt ihn aus, siehe
 * RouteController::inject_post); der Inhalt selbst enthaelt keine H1, sodass
 * es je Seite genau eine gibt. Themes, die den Titel unterdruecken, koennen
 * per Filter kontor_shop/seo/render_h1 die H1 aus dem Inhalt anfordern.
 *
 * Preise: nur der Normalpreis. Der Mitgliedspreis wird nie serverseitig
 * ausgeliefert, sondern per JavaScript nur fuer erkannte Mitglieder nach
 * dem Laden eingesetzt (Pro, Mitgliedschaft; Anker data-ks-price) - die Antwort an
 * Crawler (und an Seiten-Caches) haengt so nie von einem Cookie ab.
 */
final class PageRenderer
{
    public function __construct(private readonly Links $links)
    {
    }

    /**
     * @param array<int, array{name: string, url: string}> $breadcrumbs
     */
    public function product(PublicProduct $product, array $breadcrumbs): string
    {
        $html = '<div class="kontor-shop-detail" id="artikel-' . (int) $product->id . '" data-product-id="' . (int) $product->id . '">';
        $html .= $this->breadcrumbs($breadcrumbs);
        $html .= $this->maybe_h1($product->name);

        $html .= $this->gallery($product);
        $html .= '<div class="kontor-shop-detail__body">';

        $price_text = $product->has_variants() ? VariantPicker::price_text($product->variants, $product->price) : number_format_i18n($product->price, 2) . ' €';

        $html .= '<p class="kontor-shop-product__price" data-ks-price data-product-id="' . (int) $product->id . '">'
            . '<span class="kontor-shop-product__price--normal">' . esc_html($price_text) . '</span></p>';
        $html .= '<p class="kontor-shop-unit-price" data-ks-unit-price' . ($product->unit_price === '' ? ' hidden' : '') . '>' . esc_html($product->unit_price) . '</p>';

        if ($product->tax_rate !== null && $product->tax_rate > 0) {
            $html .= '<p class="kontor-shop-product__tax">' . esc_html(sprintf(
                /* translators: %s: tax rate in percent */
                __('incl. %s %% VAT', 'kontor-shop'),
                number_format_i18n($product->tax_rate, $product->tax_rate == floor($product->tax_rate) ? 0 : 1)
            )) . '</p>';
        }

        if ($product->available) {
            $html .= '<p class="kontor-shop-product__availability kontor-shop-product__availability--in">' . esc_html__('In stock', 'kontor-shop') . '</p>';
            $html .= '<form class="kontor-shop-add-to-cart" method="post" action="' . esc_url($this->links->shop()) . '"' . ($product->has_variants() ? ' data-ks-variant-form' : '') . '>'
                . '<input type="hidden" name="product_id" value="' . (int) $product->id . '" />'
                . VariantPicker::render($product->variant_options, $product->variants, 'kontor-shop-dv' . (int) $product->id)
                . '<label class="kontor-shop-sr" for="kontor-shop-qty-' . (int) $product->id . '">' . esc_html__('Quantity', 'kontor-shop') . '</label>'
                . '<input type="number" id="kontor-shop-qty-' . (int) $product->id . '" name="quantity" value="1" min="1" inputmode="numeric" />'
                . '<button type="submit">' . esc_html__('Add to cart', 'kontor-shop') . '</button></form>';
            $html .= $product->discontinued
                ? ProductNotes::discontinued(true)
                : ProductNotes::unavailable_extra($product->id, $product->name, $product->variants, false, 'detail');
        } elseif ($product->discontinued) {
            $html .= ProductNotes::discontinued(false);
        } else {
            $html .= ProductNotes::unavailable();
            $html .= ProductNotes::unavailable_extra($product->id, $product->name, $product->variants, true, 'detail');
        }

        if ($product->description !== '') {
            $html .= '<div class="kontor-shop-detail__description">';

            foreach (preg_split('/\R{2,}/', trim($product->description)) ?: [] as $paragraph) {
                $html .= '<p>' . nl2br(esc_html(trim($paragraph))) . '</p>';
            }

            $html .= '</div>';
        }

        /**
         * Filter kontor_shop/product/facts: Zeilen der Faktenliste auf der
         * Artikelseite (Bezeichnung => Klartext, beides wird escaped; leere
         * Werte fallen weg), z. B. Pro: Rueckgabe, Gewaehrleistung und
         * Lieferzeit (nur freigegebene Angaben). Standard: Artikelnummer,
         * Marke und GTIN.
         *
         * @param array<string, string> $facts
         * @param PublicProduct         $product
         */
        $rows  = (array) apply_filters('kontor_shop/product/facts', [
            __('Product number', 'kontor-shop') => $product->sku,
            __('Brand', 'kontor-shop')         => $product->brand,
            __('GTIN', 'kontor-shop')          => $product->gtin,
        ], $product);
        $facts = '';

        foreach ($rows as $label => $value) {
            if (is_scalar($value) && (string) $value !== '') {
                $facts .= '<dt>' . esc_html((string) $label) . '</dt><dd>' . esc_html((string) $value) . '</dd>';
            }
        }

        if ($facts !== '') {
            $html .= '<dl class="kontor-shop-detail__facts">' . $facts . '</dl>';
        }

        if ($product->category_trail !== []) {
            $html .= '<p class="kontor-shop-detail__categories">' . esc_html__('Category:', 'kontor-shop') . ' ';
            $links = [];

            foreach ($product->category_trail as $crumb) {
                $url     = $this->links->category_url(Links::slug_of((string) $crumb['path']));
                $links[] = $url !== '' ? '<a href="' . esc_url($url) . '">' . esc_html($crumb['name']) . '</a>' : esc_html($crumb['name']);
            }

            $html .= implode(' › ', $links) . '</p>';
        }

        return $html . '</div></div>';
    }

    /**
     * Bildergalerie der Artikelseite: grosses Bild (Klick = Vollbild) und
     * darunter Vorschaubilder. Ohne JavaScript bleibt das Hauptbild stehen und
     * die Vorschaubilder verlinken auf die Bilddateien.
     */
    private function gallery(PublicProduct $product): string
    {
        $images = $product->images;

        if ($images === [] && $product->image_url !== null) {
            $images = [['url' => $product->image_url, 'thumb' => $product->image_url, 'full' => $product->image_url, 'srcset' => '', 'alt' => $product->image_alt]];
        }

        $html = '<div class="kontor-shop-detail__media kontor-shop-gallery" data-ks-gallery>';

        if ($images === []) {
            return $html . '</div>';
        }

        $main = $images[0];

        $html .= '<a class="kontor-shop-gallery__main" href="' . esc_url((string) $main['full']) . '" data-ks-gallery-zoom aria-label="' . esc_attr__('Enlarge image', 'kontor-shop') . '">'
            . '<img class="kontor-shop-img" data-ks-gallery-image src="' . esc_url((string) $main['url']) . '"'
            . ((string) $main['srcset'] !== '' ? ' srcset="' . esc_attr((string) $main['srcset']) . '" sizes="(max-width: 700px) 100vw, 50vw"' : '')
            . ' alt="' . esc_attr((string) $main['alt']) . '" decoding="async" /></a>';

        if (count($images) > 1) {
            $html .= '<ul class="kontor-shop-gallery__thumbs">';

            foreach ($images as $index => $image) {
                $html .= '<li><a href="' . esc_url((string) $image['full']) . '" class="kontor-shop-gallery__thumb' . ($index === 0 ? ' is-active' : '') . '"'
                    . ' data-ks-gallery-thumb data-url="' . esc_attr((string) $image['url']) . '" data-srcset="' . esc_attr((string) $image['srcset']) . '"'
                    . ' data-full="' . esc_attr((string) $image['full']) . '" data-alt="' . esc_attr((string) $image['alt']) . '"'
                    . ($index === 0 ? ' aria-current="true"' : '')
                    . ' aria-label="' . esc_attr(sprintf(
                        /* translators: 1: image number, 2: count */
                        __('Show image %1$d of %2$d', 'kontor-shop'),
                        $index + 1,
                        count($images)
                    )) . '"><img src="' . esc_url((string) $image['thumb']) . '" alt="" loading="lazy" decoding="async" /></a></li>';
            }

            $html .= '</ul>';
        }

        return $html . '</div>';
    }

    /**
     * Brotkrumen als <nav> (auch fuer weitere virtuelle Seiten, z. B. Pro: Kategorieseiten).
     *
     * @param array<int, array{name: string, url: string}> $crumbs
     */
    public function breadcrumbs(array $crumbs): string
    {
        $html = '<nav class="kontor-shop-breadcrumbs" aria-label="' . esc_attr__('Breadcrumbs', 'kontor-shop') . '"><ol>';
        $last = count($crumbs) - 1;

        foreach ($crumbs as $i => $crumb) {
            $html .= $i === $last
                ? '<li aria-current="page">' . esc_html($crumb['name']) . '</li>'
                : '<li><a href="' . esc_url($crumb['url']) . '">' . esc_html($crumb['name']) . '</a></li>';
        }

        return $html . '</ol></nav>';
    }

    /** H1 im Inhalt nur, wenn das Theme den Seitentitel nicht ausgibt (kontor_shop/seo/render_h1). */
    public function maybe_h1(string $title): string
    {
        return (bool) apply_filters('kontor_shop/seo/render_h1', false)
            ? '<h1 class="kontor-shop-detail__title">' . esc_html($title) . '</h1>'
            : '';
    }
}
