<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Catalog;

use Kontor\Shop\Core\Registerable;
use Kontor\Shop\Domain\Catalog\Category;
use Kontor\Shop\Domain\Catalog\Product;
use Kontor\Shop\Domain\Seo\Pagination;
use Kontor\Shop\Modules\Seo\ProductUrl;
use Kontor\Shop\Infrastructure\Catalog\CatalogScope;
use Kontor\Shop\Infrastructure\Catalog\StockSnapshot;
use Kontor\Shop\Infrastructure\Catalog\VariantRows;
use Kontor\Shop\Infrastructure\Checkout\ReservationStore;
use Kontor\Shop\Infrastructure\Repositories\CategoryRepository;
use Kontor\Shop\Infrastructure\Repositories\ProductRepository;
use Kontor\Shop\Infrastructure\Repositories\StockRepository;
use Kontor\Shop\Settings\Repository;
use Kontor\Shop\Support\Color;

/**
 * Frontend-Ausgabe des Artikelkatalogs (Abschnitt 3, Ziele 1/2/5) inkl.
 * Kategorie-Filter und Produktsuche (client-seitig, assets/frontend/catalog.js).
 * Shortcode `[kontor_shop_catalog]` zeigt den ganzen Katalog (mit Pro:
 * Shoptyp-Umschalter, mit `shop="<slug>"` nur einen Shoptyp in dessen Farbe,
 * siehe Infrastructure\Catalog\CatalogScope) - der Shortcode wird
 * in beliebige, vom Shop-Admin selbst angelegte Seiten gesetzt. Nur
 * Shortcode in v1 - ein Gutenberg-Block ist bewusst
 * kein Ziel dieser Phase (kein Build-Schritt, "kein Framework-Zwang",
 * Abschnitt 5.2). CSS/JS werden zentral von Modules\Cart\CartModule geladen
 * (Timing-Gruende, siehe dort).
 */
final class CatalogModule implements Registerable
{
    private Repository $settings;
    private ProductRepository $products;
    private CategoryRepository $categories;
    private StockRepository $stock;
    private ReservationStore $reservations;

    public function __construct(Repository $settings)
    {
        $this->settings   = $settings;
        $this->products   = new ProductRepository();
        $this->categories = new CategoryRepository();
        $this->stock      = new StockRepository();
        $this->reservations = new ReservationStore();
    }

    public function register(): void
    {
        add_shortcode('kontor_shop_catalog', [$this, 'render_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets(): void
    {
        wp_enqueue_script(
            'kontor-shop-catalog',
            KONTOR_SHOP_URL . 'assets/frontend/catalog.js',
            [],
            KONTOR_SHOP_VERSION,
            true
        );

        wp_enqueue_script(
            'kontor-shop-product',
            KONTOR_SHOP_URL . 'assets/frontend/product.js',
            [],
            KONTOR_SHOP_VERSION,
            true
        );
        wp_localize_script('kontor-shop-product', 'kontorShopProduct', [
            'choose'      => __('Please select', 'kontor-shop'),
            'soldOut'     => __('sold out', 'kontor-shop'),
            'variantOut'  => __('This variant is currently unavailable.', 'kontor-shop'),
            'available'   => __('In stock', 'kontor-shop'),
            'close'       => __('Close', 'kontor-shop'),
            'prev'        => __('Previous image', 'kontor-shop'),
            'next'        => __('Next image', 'kontor-shop'),
            'zoom'        => __('Enlarge image', 'kontor-shop'),
        ]);

    }

    /**
     * @param array<string, string>|string $atts
     */
    public function render_shortcode(array|string $atts = []): string
    {
        $atts = shortcode_atts(['shop' => '', 'per_page' => '0'], is_array($atts) ? $atts : [], 'kontor_shop_catalog');

        $active = [];

        foreach ($this->categories->active() as $category) {
            $active[(int) $category->id] = $category;
        }

        $scope = CatalogScope::resolve(sanitize_title((string) $atts['shop']), $active);

        if ($scope['html'] !== null) {
            return $scope['html'];
        }

        $categories    = $scope['categories'];
        $uncategorized = $scope['uncategorized'];

        $products = array_values(array_filter(
            $this->products->all(),
            static function (Product $product) use ($categories, $uncategorized): bool {
                if (!$product->is_sellable()) {
                    return false;
                }

                // Artikel in inaktiven (oder ausgeblendeten) Kategorien nie; ohne Kategorie je nach Ausschnitt.
                return $product->category_id === null ? $uncategorized : isset($categories[$product->category_id]);
            }
        ));

        // Optionale Seitenaufteilung (?kseite=2): 0 = alle Artikel auf einer Seite. Der Filter
        // und die Suche im Browser wirken dann nur auf die gerade angezeigte Seite.
        $per_page    = max(0, min(200, (int) $atts['per_page']));
        $total       = count($products);
        $total_pages = $per_page > 0 ? Pagination::total_pages($total, $per_page) : 1;
        $requested   = isset($_GET['kseite']) ? (int) $_GET['kseite'] : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige-Parameter
        $page        = $per_page > 0 ? max(1, min($total_pages, $requested)) : 1;

        if ($per_page > 0) {
            $products = array_slice($products, ($page - 1) * $per_page, $per_page);
        }

        $style = '';

        if ($scope['accent'] !== null) {
            $style = ' style="--kontor-shop-primary:' . esc_attr(Color::sanitize_hex($scope['accent'])) . ';"';
        }

        $show_search = (bool) $this->settings->get('catalog.search_enabled', true);

        $html = '<div class="kontor-shop-catalog"' . $style . '>';
        $html .= '<div class="kontor-shop-catalog__toolbar">';

        /**
         * Filter kontor_shop/catalog/toolbar: zusaetzliche Bedienelemente vor
         * Suche und Kategorie-Filter, z. B. Pro: Umschalter zwischen den
         * Shoptypen (Alle Shops / Jagd / ...). Fertiges, escaptes HTML.
         * Standard: ''.
         *
         * @param string               $html
         * @param array<int, Product>  $products   angezeigte Artikel (diese Seite)
         * @param array<int, Category> $categories sichtbare Kategorien, Schluessel = ID
         * @param array<string, mixed> $scope      Ausschnitt (kontor_shop/catalog/scope)
         */
        $html .= (string) apply_filters('kontor_shop/catalog/toolbar', '', $products, $categories, $scope);

        if ($show_search) {
            $html .= '<div class="kontor-shop-catalog__search">'
                . '<label class="screen-reader-text kontor-shop-sr" for="kontor-shop-search-' . esc_attr($scope['key']) . '">'
                . esc_html__('Search products', 'kontor-shop') . '</label>'
                . '<input type="search" id="kontor-shop-search-' . esc_attr($scope['key']) . '" data-catalog-search'
                . ' placeholder="' . esc_attr__('Search products …', 'kontor-shop') . '" autocomplete="off" enterkeyhint="search" />'
                . '</div>';
        }

        $html .= $this->render_filters($products, $categories);
        $html .= '</div>';
        $html .= '<div class="kontor-shop-catalog__grid">';

        // Frei = Bestand minus ALLE Reservierungen (nicht sitzungsabhaengig: die Seite bleibt fuer alle gleich und cachebar).
        $snapshot = StockSnapshot::load($products);

        foreach ($products as $product) {
            $category = $categories[$product->category_id ?? 0] ?? null;

            $html .= $this->render_product($product, $category, $snapshot);
        }

        $html .= '</div>';
        $html .= $this->render_pagination($page, $total_pages);
        $html .= '<p class="kontor-shop-catalog__empty" role="status"' . ($products === [] ? '' : ' hidden') . '>'
            . esc_html__('No products found.', 'kontor-shop') . '</p>';
        $html .= '</div>';

        return $html;
    }

    /**
     * @param array<int, Product> $products
     * @param array<int, Category> $categories
     */
    private function render_filters(array $products, array $categories): string
    {
        $used_category_ids = array_unique(array_filter(array_map(
            static fn (Product $product): ?int => $product->category_id,
            $products
        )));

        $used_categories = array_filter(
            $categories,
            static fn (Category $category): bool => in_array($category->id, $used_category_ids, true)
        );

        // Schon ab einer Kategorie anzeigen: der Besucher sieht so, welche
        // Kategorien es gibt, auch wenn die Leiste (noch) wenig filtert.
        if ($used_categories === []) {
            return '';
        }

        $html = '<div class="kontor-shop-catalog__filters" role="group" aria-label="' . esc_attr__('Categories', 'kontor-shop') . '">';
        $html .= '<button type="button" class="kontor-shop-catalog__filter is-active" aria-pressed="true" data-category-filter="">'
            . esc_html__('All', 'kontor-shop') . '</button>';

        foreach ($used_categories as $category) {
            /**
             * Filter kontor_shop/catalog/filter_attributes: weitere Attribute
             * eines Kategorie-Knopfs im Katalog, z. B. Pro: data-shop-type
             * (der Shoptyp-Umschalter blendet Kategorien anderer Shops aus).
             * Name => Wert, wird escaped. Standard: [].
             *
             * @param array<string, string> $attributes
             * @param Category              $category
             */
            $extra = (array) apply_filters('kontor_shop/catalog/filter_attributes', [], $category);

            $html .= '<button type="button" class="kontor-shop-catalog__filter" aria-pressed="false" data-category-filter="'
                . esc_attr($category->slug) . '"' . self::attributes($extra) . '>' . esc_html($category->name) . '</button>';
        }

        $html .= '</div>';

        return $html;
    }

    private function render_pagination(int $page, int $total_pages): string
    {
        if ($total_pages <= 1) {
            return '';
        }

        $base = (string) get_permalink();
        $html = '<nav class="kontor-shop-pagination" aria-label="' . esc_attr__('Pages', 'kontor-shop') . '"><ul>';

        foreach (Pagination::window($page, $total_pages) as $number) {
            if ($number === null) {
                $html .= '<li><span aria-hidden="true">…</span></li>';
            } elseif ($number === $page) {
                $html .= '<li><span aria-current="page">' . $number . '</span></li>';
            } else {
                $html .= '<li><a href="' . esc_url($number > 1 ? add_query_arg('kseite', $number, $base) : $base) . '">' . $number . '</a></li>';
            }
        }

        return $html . '</ul></nav>';
    }

    /**
     * Attribute (Name => Wert) als HTML; null/false fallen weg, true = ohne Wert.
     *
     * @param array<mixed> $attributes
     */
    private static function attributes(array $attributes): string
    {
        $html = '';

        foreach ($attributes as $name => $value) {
            $name = is_string($name) ? strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $name) ?? '') : '';

            if ($name === '' || $value === null || $value === false || is_array($value) || is_object($value)) {
                continue;
            }

            $html .= $value === true ? ' ' . $name : ' ' . $name . '="' . esc_attr((string) $value) . '"';
        }

        return $html;
    }

    private function render_product(Product $product, ?Category $category, StockSnapshot $snapshot): string
    {
        $stock        = $snapshot->free($product);
        $rows         = $product->has_variants()
            ? VariantRows::build($product, $snapshot->variants($product), static fn ($variant): bool => $snapshot->available($product, $variant))
            : [];
        $placeholder  = absint($this->settings->get('design.placeholder_image_id', 0));

        /**
         * Filter kontor_shop/catalog/card_attributes: Attribute einer
         * Artikelkarte im Katalog (Name => Wert, wird escaped; null = weg),
         * z. B. Pro: data-shop-type und die Farbe des Shoptyps, wenn die
         * Kategorie keine eigene hat. Standard: data-category und style mit
         * der Akzentfarbe der Kategorie.
         *
         * @param array<string, string|null> $attributes
         * @param Product                    $product
         * @param Category|null              $category Hauptkategorie (sichtbar) oder null
         */
        $card_attrs = (array) apply_filters('kontor_shop/catalog/card_attributes', [
            'data-category' => $category?->slug,
            'style'         => $category?->accent_color !== null ? '--ks-card-accent: ' . $category->accent_color . ';' : null,
        ], $product, $category);
        $style         = isset($card_attrs['style']) ? self::attributes(['style' => $card_attrs['style']]) : '';
        $data_category = self::attributes(array_diff_key($card_attrs, ['style' => true, 'id' => true, 'class' => true, 'data-product-id' => true, 'data-search' => true]));
        $search_text   = function_exists('mb_strtolower')
            ? mb_strtolower($product->name . ' ' . $product->sku . ' ' . $product->description . ' ' . ($category?->name ?? ''))
            : strtolower($product->name . ' ' . $product->sku . ' ' . $product->description . ' ' . ($category?->name ?? ''));

        // Stabile Anker-ID (#artikel-{id}) - dieselbe wie auf den Kategorieseiten.
        $html = '<article class="kontor-shop-product" id="artikel-' . (int) $product->id . '" data-product-id="' . esc_attr((string) $product->id) . '"'
            . $data_category . ' data-search="' . esc_attr($search_text) . '"' . $style . '>';

        $html .= '<div class="kontor-shop-product__media" data-ks-media>'
            . ProductImage::render($product->image_id, $product->name, 'medium_large', $placeholder, '', $product->image_alt)
            . '</div>';

        $html .= '<div class="kontor-shop-product__body">';

        if ($category !== null) {
            $html .= '<span class="kontor-shop-product__category">' . esc_html($category->name) . '</span>';
        }

        $detail_url = ProductUrl::of($product);
        $html .= '<h3 class="kontor-shop-product__name">'
            . ($detail_url !== '' ? '<a class="kontor-shop-product__link" href="' . esc_url($detail_url) . '">' . esc_html($product->name) . '</a>' : esc_html($product->name))
            . '</h3>';

        if ($product->description !== '') {
            $html .= '<p class="kontor-shop-product__description">' . esc_html($product->description) . '</p>';
        }

        // Immer nur der Normalpreis; ein Mitgliedspreis wird (mit Pro) per JS nachgeladen - data-ks-price ist der Anker.
        $price_text = $rows !== [] ? VariantPicker::price_text($rows, $product->price) : number_format_i18n($product->price, 2) . ' €';
        $unit_text  = $rows !== [] ? VariantPicker::common_unit_price($rows) : $product->unit_price_text($product->price);

        $html .= '<p class="kontor-shop-product__price" data-ks-price data-product-id="' . (int) $product->id . '">';
        $html .= '<span class="kontor-shop-product__price--normal">' . esc_html($price_text) . '</span>';
        $html .= '</p>';
        $html .= '<p class="kontor-shop-unit-price" data-ks-unit-price' . ($unit_text === '' ? ' hidden' : '') . '>' . esc_html($unit_text) . '</p>';

        if ($product->tax_rate !== null && $product->tax_rate > 0) {
            $html .= '<p class="kontor-shop-product__tax">' . esc_html(sprintf(
                /* translators: %s: tax rate in percent */
                __('incl. %s %% VAT', 'kontor-shop'),
                number_format_i18n($product->tax_rate, $product->tax_rate == floor($product->tax_rate) ? 0 : 1)
            )) . '</p>';
        }

        if ($stock > 0) {
            $html .= '<form class="kontor-shop-add-to-cart" method="post"' . ($rows !== [] ? ' data-ks-variant-form' : '') . '>';
            $html .= '<input type="hidden" name="product_id" value="' . esc_attr((string) $product->id) . '" />';
            $html .= VariantPicker::render($product->variant_options, $rows, 'kontor-shop-v' . (int) $product->id);
            $html .= '<label class="kontor-shop-sr" for="kontor-shop-qty-' . esc_attr((string) $product->id) . '">'
                . esc_html__('Quantity', 'kontor-shop') . '</label>';
            $html .= '<input type="number" id="kontor-shop-qty-' . esc_attr((string) $product->id) . '" name="quantity" value="1" min="1" max="'
                . esc_attr((string) $stock) . '" inputmode="numeric" />';
            $html .= '<button type="submit">' . esc_html__('Add to cart', 'kontor-shop') . '</button>';
            $html .= '</form>';
            $html .= $product->discontinued
                ? ProductNotes::discontinued(true)
                : ProductNotes::unavailable_extra((int) $product->id, $product->name, $rows, false, 'card');
        } elseif ($product->discontinued) {
            $html .= ProductNotes::discontinued(false);
        } else {
            $html .= ProductNotes::unavailable();
            $html .= ProductNotes::unavailable_extra((int) $product->id, $product->name, $rows, true, 'card');
        }

        $html .= '</div></article>';

        return $html;
    }
}
