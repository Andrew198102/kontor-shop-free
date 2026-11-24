<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Seo;

use Kontor\Shop\Domain\Seo\PublicProduct;
use Kontor\Shop\Modules\Catalog\LabelCard;
use Kontor\Shop\Modules\Catalog\ProductAccent;
use Kontor\Shop\Modules\Catalog\ProductImage;
use Kontor\Shop\Modules\Catalog\ProductNotes;
use Kontor\Shop\Modules\Catalog\ServiceInfo;
use Kontor\Shop\Modules\Catalog\VariantPicker;
use Kontor\Shop\Modules\Storefront\DesignModule;

/**
 * Serverseitig gerenderter Inhalt der Artikelseite - ohne JavaScript
 * lesbar (weitere virtuelle Seiten, z. B. Pro: Kategorieseiten, nutzen
 * breadcrumbs() und maybe_h1()). Die H1 (Artikelname) gibt standardmaessig der
 * Shop selbst im Kaufbereich aus; den Seitentitel des Themes blendet
 * RouteController dann aus, sodass es je Seite genau eine gibt. Einstellung
 * Design -> "Artikelname auf Artikelseiten" = Theme (oder Filter
 * kontor_shop/seo/render_h1 = false) ueberlaesst die H1 wieder dem Theme.
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
     * Artikelseite: Galerie links (Bildbuehne wie auf den Karten), rechts der
     * Kaufbereich (Kategorie, Name, Preis als Etikett, Beschreibung, Groessen
     * als Knoepfe, Menge, Knopf, Infozeilen), darunter "Mehr aus ...".
     *
     * @param array<int, array{name: string, url: string}> $breadcrumbs
     * @param array<int, PublicProduct>                    $related weitere Artikel derselben Kategorie
     */
    public function product(PublicProduct $product, array $breadcrumbs, array $related = []): string
    {
        $id       = (int) $product->id;
        $settings = $this->links->settings();

        // Farbe wie auf der Katalogkarte: Kategorie, sonst (Pro) Shoptyp, sonst Hauptfarbe.
        $accent = ProductAccent::of_category_id($product->category_id);
        $html   = '<div class="kontor-shop-detail kontor-shop-detail--page' . ($accent !== '' ? ' kontor-shop-detail--accent' : '') . '" id="artikel-' . $id . '" data-product-id="' . $id . '"'
            . ($accent !== '' ? ' style="' . esc_attr(ProductAccent::style($accent)) . '"' : '') . '>';
        $html .= $this->breadcrumbs($breadcrumbs);
        $html .= '<div class="kontor-shop-detail__main">';
        $html .= $this->gallery($product);
        $html .= '<div class="kontor-shop-detail__body">';

        // Kategorie und Name
        $category = $product->category_trail !== [] ? $product->category_trail[count($product->category_trail) - 1] : null;
        $head     = '';

        if ($category !== null) {
            $url   = $this->links->category_url(Links::slug_of((string) $category['path']));
            $head .= '<p class="kontor-shop-detail__category">'
                . ($url !== '' ? '<a href="' . esc_url($url) . '">' . esc_html($category['name']) . '</a>' : esc_html($category['name'])) . '</p>';
        }

        if (self::shows_h1()) {
            $head .= '<h1 class="kontor-shop-detail__title">' . esc_html($product->name) . '</h1>';
        }

        if ($head !== '') {
            $html .= '<div class="kontor-shop-detail__head">' . $head . '</div>';
        }

        // Preis als Etikett und Verfuegbarkeit in einer Zeile
        $price_text = $product->has_variants() ? VariantPicker::price_text($product->variants, $product->price) : number_format_i18n($product->price, 2) . ' €';

        $html .= '<div class="kontor-shop-detail__pricing">'
            . '<p class="kontor-shop-product__price kontor-shop-detail__tag" data-ks-price data-product-id="' . $id . '">'
            . '<span class="kontor-shop-product__price--normal">' . esc_html($price_text) . '</span></p>'
            . ($product->available ? '<p class="kontor-shop-product__availability kontor-shop-product__availability--in">' . esc_html__('In stock', 'kontor-shop') . '</p>' : '')
            . '</div>';
        $html .= '<p class="kontor-shop-unit-price" data-ks-unit-price' . ($product->unit_price === '' ? ' hidden' : '') . '>' . esc_html($product->unit_price) . '</p>';

        if ($product->tax_rate !== null && $product->tax_rate > 0) {
            $html .= '<p class="kontor-shop-product__tax">' . esc_html(sprintf(
                /* translators: %s: tax rate in percent */
                __('incl. %s %% VAT', 'kontor-shop'),
                number_format_i18n($product->tax_rate, $product->tax_rate == floor($product->tax_rate) ? 0 : 1)
            )) . '</p>';
        }

        if ($product->description !== '') {
            $html .= '<div class="kontor-shop-detail__description">';

            foreach (preg_split('/\R{2,}/', trim($product->description)) ?: [] as $paragraph) {
                $html .= '<p>' . nl2br(esc_html(trim($paragraph))) . '</p>';
            }

            $html .= '</div>';
        }

        if ($product->available) {
            $html .= $this->buy_form($product);
            $html .= $product->discontinued
                ? ProductNotes::discontinued(true)
                : ProductNotes::unavailable_extra($product->id, $product->name, $product->variants, false, 'detail');
            $html .= ServiceInfo::render($settings);
        } elseif ($product->discontinued) {
            $html .= ProductNotes::discontinued(false);
        } else {
            $html .= ProductNotes::unavailable();
            $html .= ProductNotes::unavailable_extra($product->id, $product->name, $product->variants, true, 'detail');
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

        $html .= '</div></div>';
        $html .= $this->related($related, $category, absint($settings->get('design.placeholder_image_id', 0)));

        return $html . '</div>';
    }

    /**
     * Kaufformular: bei genau einem Merkmal (z. B. Groesse) Knoepfe statt
     * Auswahlliste - die Liste bleibt darunter als Rueckfall ohne JavaScript
     * (product.js blendet sie aus und waehlt ueber sie). Menge mit - und +.
     */
    private function buy_form(PublicProduct $product): string
    {
        $id   = (int) $product->id;
        $html = '<form class="kontor-shop-add-to-cart kontor-shop-detail__form" method="post" action="' . esc_url($this->links->shop()) . '" data-ks-detail'
            . ($product->has_variants() ? ' data-ks-variant-form' : '') . '>'
            . '<input type="hidden" name="product_id" value="' . $id . '" />';

        if ($product->has_variants() && count($product->variant_options) === 1) {
            $html .= $this->size_buttons($product);
        }

        $html .= VariantPicker::render($product->variant_options, $product->variants, 'kontor-shop-dv' . $id);
        $html .= '<div class="kontor-shop-detail__buy">'
            . '<div class="kontor-shop-qty" data-ks-qty>'
            . '<button type="button" class="kontor-shop-qty__step" data-ks-qty-step="-1" aria-label="' . esc_attr__('One less', 'kontor-shop') . '" hidden>−</button>'
            . '<label class="kontor-shop-sr" for="kontor-shop-qty-' . $id . '">' . esc_html__('Quantity', 'kontor-shop') . '</label>'
            . '<input type="number" id="kontor-shop-qty-' . $id . '" name="quantity" value="1" min="1" inputmode="numeric" />'
            . '<button type="button" class="kontor-shop-qty__step" data-ks-qty-step="1" aria-label="' . esc_attr__('One more', 'kontor-shop') . '" hidden>+</button>'
            . '</div>'
            . '<button type="submit" class="kontor-shop-detail__submit">' . esc_html__('Add to cart', 'kontor-shop') . '</button>'
            . '</div></form>';

        return $html . '<p class="kontor-shop-detail__added" data-ks-detail-added role="status" aria-live="polite" hidden>'
            . '<span data-ks-added-text></span> <a href="' . esc_url($this->links->cart()) . '" data-ks-open-cart>' . esc_html__('Go to cart', 'kontor-shop') . ' →</a></p>'
            // Kaufleiste am Handy: product.js zeigt sie, solange der Knopf oben nicht zu sehen ist.
            . '<div class="kontor-shop-buybar" data-ks-buybar hidden><span class="kontor-shop-buybar__price" data-ks-buybar-price></span>'
            . '<button type="button" class="kontor-shop-btn kontor-shop-buybar__button" data-ks-buybar-button>' . esc_html__('Add to cart', 'kontor-shop') . '</button></div>';
    }

    /**
     * Groessen-Knoepfe (Reihenfolge wie im Admin). Ausverkaufte bleiben
     * waehlbar, aber durchgestrichen - dann erscheint "Benachrichtigen, wenn
     * verfuegbar". Ohne JavaScript bleiben sie verborgen.
     */
    private function size_buttons(PublicProduct $product): string
    {
        $option    = $product->variant_options[0];
        $available = [];

        foreach ($product->variants as $row) {
            $value = (string) ($row['options'][0] ?? '');

            if ($value !== '') {
                $available[$value] = ($available[$value] ?? false) || (bool) $row['available'];
            }
        }

        $label_id = 'kontor-shop-sizes-' . (int) $product->id;
        $html     = '<div class="kontor-shop-detail__sizes" data-ks-detail-sizes data-ks-size-key="' . esc_attr(LabelCard::size_key((string) $option['name'])) . '" hidden>'
            . '<p class="kontor-shop-detail__sizes-label" id="' . esc_attr($label_id) . '">' . esc_html((string) $option['name']) . '</p>'
            . '<div class="kontor-shop-sizes" role="group" aria-labelledby="' . esc_attr($label_id) . '">';

        foreach ($option['values'] as $value) {
            $value = (string) $value;

            if (!isset($available[$value])) {
                continue;
            }

            $html .= $available[$value]
                ? '<button type="button" class="kontor-shop-size" data-ks-pick="' . esc_attr($value) . '" aria-pressed="false">' . esc_html($value) . '</button>'
                : '<button type="button" class="kontor-shop-size is-out" data-ks-pick="' . esc_attr($value) . '" aria-pressed="false" aria-label="' . esc_attr(sprintf(
                    /* translators: %s: size or other option value */
                    __('%s – sold out', 'kontor-shop'),
                    $value
                )) . '"><s>' . esc_html($value) . '</s></button>';
        }

        return $html . '</div><p class="kontor-shop-detail__size-hint" data-ks-size-hint hidden></p></div>';
    }

    /**
     * "Mehr aus <Kategorie>": Etikett-Karten weiterer Artikel.
     *
     * @param array<int, PublicProduct>          $related
     * @param array{name: string, path: string}|null $category
     */
    private function related(array $related, ?array $category, int $placeholder_id): string
    {
        if ($related === []) {
            return '';
        }

        $url  = $category !== null ? $this->links->category_url(Links::slug_of((string) $category['path'])) : '';
        $html = '<section class="kontor-shop-detail__related" aria-labelledby="kontor-shop-related-title"><div class="kontor-shop-detail__related-head">'
            . '<h2 id="kontor-shop-related-title">' . esc_html($category !== null
                /* translators: %s: category name */
                ? sprintf(__('More from %s', 'kontor-shop'), $category['name'])
                : __('You might also like', 'kontor-shop')) . '</h2>'
            . ($url !== '' ? '<a href="' . esc_url($url) . '">' . esc_html__('Show all', 'kontor-shop') . ' →</a>' : '')
            . '</div><div class="kontor-shop-catalog__grid kontor-shop-detail__related-grid">';

        foreach ($related as $item) {
            $html .= LabelCard::from_public($item, $this->links->product($item), 'h3', $placeholder_id);
        }

        return $html . '</div></section>';
    }

    /**
     * Bildergalerie der Artikelseite: grosses Bild auf der Bildbuehne (Klick =
     * Vollbild), Vorschaubilder daneben. Freigestellte Fotos zoomen wie auf
     * den Karten aufs Produkt; Fotos mit Hintergrund werden ganz gezeigt.
     * Ohne JavaScript bleibt das Hauptbild stehen und die Vorschaubilder
     * verlinken auf die Bilddateien.
     */
    private function gallery(PublicProduct $product): string
    {
        $images = $product->images;

        if ($images === [] && $product->image_url !== null) {
            $images = [['id' => $product->image_id, 'url' => $product->image_url, 'thumb' => $product->image_url, 'full' => $product->image_url, 'srcset' => '', 'alt' => $product->image_alt]];
        }

        $html = '<div class="kontor-shop-detail__media kontor-shop-gallery' . (count($images) > 1 ? ' has-thumbs' : '') . '" data-ks-gallery>';

        if ($images === []) {
            return $html . '</div>';
        }

        $main            = $images[0];
        [$stage, $style] = self::stage((int) ($main['id'] ?? 0));

        $html .= '<a class="kontor-shop-gallery__main' . ($stage !== '' ? ' ' . $stage : '') . '" href="' . esc_url((string) $main['full']) . '"'
            . ($style !== '' ? ' style="' . esc_attr($style) . '"' : '')
            . ' data-ks-gallery-zoom aria-label="' . esc_attr__('Enlarge image', 'kontor-shop') . '">'
            . '<img class="kontor-shop-img" data-ks-gallery-image src="' . esc_url((string) $main['url']) . '"'
            // Gezoomte Freisteller werden etwa doppelt so breit gezeigt wie die Buehne.
            . ((string) $main['srcset'] !== '' ? ' srcset="' . esc_attr((string) $main['srcset']) . '" sizes="' . (str_contains($stage, 'has-box') ? '(max-width: 860px) 200vw, 100vw' : '(max-width: 860px) 100vw, 50vw') . '"' : '')
            . ' alt="' . esc_attr((string) $main['alt']) . '" decoding="async" />'
            . '<span class="kontor-shop-gallery__zoom" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20"><circle cx="11" cy="11" r="6.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M16 16l4.5 4.5M11 8v6M8 11h6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></span></a>';

        if (count($images) > 1) {
            $html .= '<ul class="kontor-shop-gallery__thumbs">';

            foreach ($images as $index => $image) {
                [$thumb_stage, $thumb_style] = self::stage((int) ($image['id'] ?? 0));

                $html .= '<li><a href="' . esc_url((string) $image['full']) . '" class="kontor-shop-gallery__thumb' . ($index === 0 ? ' is-active' : '') . '"'
                    . ' data-ks-gallery-thumb data-url="' . esc_attr((string) $image['url']) . '" data-srcset="' . esc_attr((string) $image['srcset']) . '"'
                    . ' data-full="' . esc_attr((string) $image['full']) . '" data-alt="' . esc_attr((string) $image['alt']) . '"'
                    . ' data-stage="' . esc_attr($thumb_stage) . '" data-box="' . esc_attr($thumb_style) . '"'
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
     * Darstellung eines Bildes auf der Bildbuehne: Klassen und CSS-Variablen
     * (Freisteller: is-cutout, mit Produktrahmen zusaetzlich has-box).
     *
     * @return array{0: string, 1: string}
     */
    private static function stage(int $image_id): array
    {
        if ($image_id <= 0 || !ProductImage::is_cutout($image_id)) {
            return ['', ''];
        }

        $style = ProductImage::box_style(ProductImage::product_box($image_id));

        return [$style !== '' ? 'is-cutout has-box' : 'is-cutout', $style];
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

    /**
     * Gibt der Shop den Namen als H1 aus? Standard: ja (Design -> "Artikelname
     * auf Artikelseiten"), dann blendet RouteController den Seitentitel des
     * Themes aus. Filter kontor_shop/seo/render_h1 entscheidet zuletzt.
     */
    public static function shows_h1(): bool
    {
        return (bool) apply_filters('kontor_shop/seo/render_h1', DesignModule::detail_title() === 'shop');
    }

    /** H1 im Inhalt (Kategorieseiten), wenn der Shop sie ausgibt - siehe shows_h1(). */
    public function maybe_h1(string $title): string
    {
        return self::shows_h1()
            ? '<h1 class="kontor-shop-detail__title">' . esc_html($title) . '</h1>'
            : '';
    }
}
