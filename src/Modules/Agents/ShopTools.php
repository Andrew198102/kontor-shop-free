<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Agents;

use Kontor\Shop\Domain\Seo\PublicProduct;
use Kontor\Shop\Infrastructure\Checkout\ReservationStore;
use Kontor\Shop\Modules\Agents\Mcp\Args;
use Kontor\Shop\Modules\Agents\Mcp\Tool;
use Kontor\Shop\Modules\Agents\Mcp\ToolError;
use Kontor\Shop\Modules\Payments\PaymentOptions;
use Kontor\Shop\Settings\Repository;
use Kontor\Shop\Support\Countries;
use Kontor\Shop\Support\Translation;

// Fehlertexte gehen als JSON an KI-Assistenten (MCP), nie als HTML in eine Seite.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * Lesende Werkzeuge fuer die KI-Assistenten von Kunden (Free): Shop-Infos,
 * Kategorien, Suche, Artikel, Verfuegbarkeit. Alles hier ist auch ohne
 * Anmeldung auf der Website zu sehen - mehr gibt der Shop-Zugang nicht her.
 */
final class ShopTools
{
    private ?ShopCatalog $catalog = null;

    public function __construct(private readonly Repository $settings)
    {
    }

    /**
     * @return array<int, Tool>
     */
    public function tools(): array
    {
        return [
            new Tool(
                'shop_info',
                __('Shop information', 'kontor-shop'),
                'Basic facts about this shop: name, website, currency, payment methods, shipping costs and countries, self pickup, legal pages, and how ordering works. Call this for questions about paying, shipping, pickup, terms or how to order – not needed for finding products.',
                [],
                [],
                fn (): array => $this->info()
            ),
            new Tool(
                'shop_list_categories',
                __('List categories', 'kontor-shop'),
                'All visible product categories (id, name, slug, parent). Use the slug as "category" in shop_search_products.',
                [],
                [],
                fn (): array => $this->list_categories()
            ),
            new Tool(
                'shop_search_products',
                __('Search products', 'kontor-shop'),
                'Search the catalog. Matches words in name, brand, category, variants (e.g. size, colour), product number and description; umlauts and case do not matter. Without a query it lists products alphabetically. Prices are regular prices in EUR incl. VAT. If exactly one product matches, "product" holds its full details (as shop_get_product) – no need to call shop_get_product then.',
                [
                    'query'          => ['type' => 'string', 'maxLength' => 200, 'description' => 'Search words, e.g. "hoodie navy L".'],
                    'category'       => ['type' => 'string', 'maxLength' => 190, 'description' => 'Category slug or id (from shop_list_categories); includes subcategories.'],
                    'min_price'      => ['type' => 'number', 'minimum' => 0],
                    'max_price'      => ['type' => 'number', 'minimum' => 0],
                    'available_only' => ['type' => 'boolean', 'description' => 'Only products that are in stock (default false).'],
                    'limit'          => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'description' => 'Default 10.'],
                    'offset'         => ['type' => 'integer', 'minimum' => 0],
                ],
                [],
                fn (Args $args): array => $this->search($args)
            ),
            new Tool(
                'shop_get_product',
                __('Get product details', 'kontor-shop'),
                'Full details of one product: description, images, and all variants with variant_id, price and availability. Products with variants are always bought as one variant.',
                [
                    'product_id' => ['type' => 'integer', 'minimum' => 1],
                    'slug'       => ['type' => 'string', 'maxLength' => 190, 'description' => 'Alternative to product_id.'],
                ],
                [],
                fn (Args $args): array => $this->get_product($args)
            ),
            new Tool(
                'shop_check_availability',
                __('Check availability', 'kontor-shop'),
                'Whether a quantity of a product (or one of its variants) can be ordered right now. Stock is only held for the customer once they open the checkout.',
                [
                    'product_id' => ['type' => 'integer', 'minimum' => 1],
                    'variant_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Required for products with variants.'],
                    'quantity'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => 999, 'description' => 'Default 1.'],
                ],
                ['product_id'],
                fn (Args $args): array => $this->check_availability($args)
            ),
        ];
    }

    public static function instructions(Repository $settings): string
    {
        return (string) apply_filters(
            'kontor_shop/agents/shop_instructions',
            sprintf(
                'You are connected to the online shop "%s". Use shop_search_products and shop_get_product to find products; never invent products, prices or availability - only state what the tools return. Prices are regular prices in EUR incl. VAT. ',
                self::shop_name($settings)
            ) . self::ordering($settings)
        );
    }

    /**
     * Wie bestellt wird - Pro ersetzt das, sobald der Assistent einen
     * Warenkorb vorbereiten kann.
     */
    public static function ordering(Repository $settings): string
    {
        return (string) apply_filters(
            'kontor_shop/agents/shop_ordering',
            'Ordering through the assistant is not available in this shop: give the customer the product link (url); they add the product to the cart and order on the website themselves.',
            $settings
        );
    }

    public static function shop_name(Repository $settings): string
    {
        $name = trim((string) $settings->get('general.shop_name', ''));

        return $name !== '' ? $name : (string) get_bloginfo('name');
    }

    private function catalog(): ShopCatalog
    {
        return $this->catalog ??= new ShopCatalog($this->settings);
    }

    /**
     * @return array<string, mixed>
     */
    private function info(): array
    {
        $payment  = new PaymentOptions($this->settings);
        $methods  = [];

        if ($payment->prepayment_enabled()) {
            $methods[] = ['method' => 'prepayment', 'label' => __('Prepayment', 'kontor-shop'), 'note' => 'Bank transfer before shipping; bank details come with the order confirmation.'];
        }

        if ($payment->invoice_enabled()) {
            $methods[] = ['method' => 'invoice', 'label' => __('On invoice', 'kontor-shop'), 'note' => 'Pay by bank transfer after receiving the goods.'];
        }

        $free_from = (float) $this->settings->get('shipping.free_from_amount', 0);
        $countries = array_values(array_filter(array_map('strval', (array) $this->settings->get('checkout.countries', []))));
        $shop_id   = absint($this->settings->get('general.shop_page_id', 0));
        $legal     = [];

        foreach (['terms' => 'terms_page_id', 'withdrawal' => 'withdrawal_page_id', 'privacy' => 'privacy_page_id', 'imprint' => 'imprint_page_id'] as $key => $setting) {
            $page_id = Translation::post_id(absint($this->settings->get('legal.' . $setting, 0)));

            if ($page_id > 0 && get_post_status($page_id) === 'publish') {
                $legal[$key] = (string) get_permalink($page_id);
            }
        }

        $info = [
            'shop_name'       => self::shop_name($this->settings),
            'website'         => $shop_id > 0 && get_permalink($shop_id) ? (string) get_permalink($shop_id) : home_url('/'),
            'currency'        => 'EUR',
            'prices'          => 'Regular prices incl. VAT.',
            'payment_methods' => $methods,
            'shipping'        => [
                'flat_rate'         => round((float) $this->settings->get('shipping.flat_rate', 0), 2),
                'free_from_amount'  => $free_from > 0 ? round($free_from, 2) : null,
                'self_pickup'       => (bool) $this->settings->get('shipping.allow_self_pickup', true),
                'countries'         => array_map(static fn (string $code): array => ['code' => $code, 'name' => Countries::name($code)], $countries),
                'note'              => 'Products from different warehouses may ship separately; the checkout shows the exact amount.',
            ],
            'legal_pages'     => $legal,
            'ordering'        => self::ordering($this->settings),
        ];

        /**
         * Filter kontor_shop/agents/shop_info: Angaben des Werkzeugs shop_info fuer
         * KI-Assistenten ergaenzen, z. B. Pro: Hinweis auf Mitgliedspreise und
         * "Auf Rechnung nur fuer Mitglieder". Englischer Text fuer das Modell.
         * Standard: die Angaben oben.
         *
         * @param array<string, mixed> $info
         * @param Repository           $settings
         */
        return (array) apply_filters('kontor_shop/agents/shop_info', $info, $this->settings);
    }

    /**
     * @return array<string, mixed>
     */
    private function list_categories(): array
    {
        $out = [];

        foreach ($this->catalog()->categories() as $category) {
            $out[] = [
                'id'        => (int) $category->id,
                'name'      => $category->name,
                'slug'      => $category->slug,
                'parent_id' => $category->parent_id,
            ];
        }

        return ['categories' => $out];
    }

    /**
     * @return array<string, mixed>
     */
    private function search(Args $args): array
    {
        $products = $this->catalog()->all();

        if ($args->str('category') !== '') {
            $category = $this->catalog()->category($args->str('category'));

            if ($category === null) {
                throw new ToolError(__('Unknown category – see shop_list_categories.', 'kontor-shop'));
            }

            $ids      = $this->catalog()->ids_in($category);
            $products = array_values(array_filter($products, static fn (PublicProduct $p): bool => isset($ids[$p->id])));
        }

        $min = $args->nullable_float('min_price');
        $max = $args->nullable_float('max_price');

        $products = array_values(array_filter($products, static fn (PublicProduct $p): bool => ($min === null || $p->price_max() >= $min)
            && ($max === null || $p->price <= $max)
            && (!$args->bool('available_only') || $p->available)));

        $found  = ShopCatalog::search($products, $args->str('query'));
        $limit  = $args->int('limit', 10);
        $offset = $args->int('offset', 0);
        $page   = array_slice($found['items'], $offset, $limit);
        $out    = [
            'total'    => count($found['items']),
            'offset'   => $offset,
            'match'    => $found['match'],
            'products' => array_map(fn (PublicProduct $p): array => $this->catalog()->summary($p), $page),
        ];

        if ($found['match'] === 'any') {
            $out['hint'] = 'No product matched all words; these match some of them.';
        }

        // Genau ein Treffer: gleich die Details mitgeben - spart dem Assistenten den Aufruf von shop_get_product.
        if (count($found['items']) === 1) {
            $single = $this->catalog()->find($found['items'][0]->id);

            if ($single !== null) {
                $out['product']  = $this->catalog()->details($single);
                $out['ordering'] = self::ordering($this->settings);
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function get_product(Args $args): array
    {
        $product = $this->resolve($args);

        return ['product' => $this->catalog()->details($product), 'ordering' => self::ordering($this->settings)];
    }

    /**
     * @return array<string, mixed>
     */
    private function check_availability(Args $args): array
    {
        $product  = $this->resolve($args);
        $variant  = $args->int('variant_id');
        $quantity = $args->int('quantity', 1);

        if ($product->has_variants() && $variant <= 0) {
            throw new ToolError(__('This product has variants – please pass a variant_id (see shop_get_product).', 'kontor-shop'), [
                'variants' => array_map(static fn (array $v): array => ['variant_id' => (int) $v['id'], 'label' => (string) $v['label']], $product->variants),
            ]);
        }

        if ($variant > 0 && !in_array($variant, array_map(static fn (array $v): int => (int) $v['id'], $product->variants), true)) {
            throw new ToolError(__('This variant does not belong to the product or is not available.', 'kontor-shop'));
        }

        $free = (new ReservationStore())->free_for($product->id, null, $variant > 0 ? $variant : null);

        return [
            'product_id'   => $product->id,
            'variant_id'   => $variant > 0 ? $variant : null,
            'quantity'     => $quantity,
            'available'    => $free > 0,
            'enough_stock' => $free >= $quantity,
            'note'         => $free >= $quantity
                ? 'Can be ordered now. Stock is held only after the customer opens the checkout.'
                : ($free > 0 ? 'Not enough in stock for this quantity; a smaller quantity is possible.' : 'Currently sold out.'),
        ];
    }

    /**
     * @throws ToolError
     */
    private function resolve(Args $args): PublicProduct
    {
        $product = $args->int('product_id') > 0
            ? $this->catalog()->find($args->int('product_id'))
            : ($args->str('slug') !== '' ? $this->catalog()->find_by_slug($args->str('slug')) : null);

        if ($product === null) {
            throw new ToolError(__('Product not found (or not visible in the shop).', 'kontor-shop'));
        }

        return $product;
    }
}
