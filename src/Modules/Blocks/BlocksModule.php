<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Blocks;

use Kontor\Shop\Core\Registerable;

/**
 * Bloecke fuer den Block-Editor: "Shop", "Warenkorb", "Kasse" und
 * "Warenkorb-Symbol". Sie sind nur eine Huelle um die bestehenden
 * Shortcodes (gleiche Ausgabe, gleiche Optionen) - serverseitig gerendert,
 * ohne Build-Schritt: das Editor-Skript (assets/admin/blocks.js) zeigt im
 * Editor eine Platzhalter-Karte statt der echten Ausgabe, damit z. B. die
 * Kasse im Editor keine Reservierung anlegt.
 */
final class BlocksModule implements Registerable
{
    public const CATEGORY = 'kontor-shop';

    /** Block-Name => [Shortcode, Dashicon] */
    private const BLOCKS = [
        'kontor-shop/catalog'   => ['kontor_shop_catalog', 'store'],
        'kontor-shop/cart'      => ['kontor_shop_cart', 'cart'],
        'kontor-shop/checkout'  => ['kontor_shop_checkout', 'money-alt'],
        'kontor-shop/cart-icon' => ['kontor_shop_cart_icon', 'cart'],
    ];

    public function register(): void
    {
        add_action('init', [$this, 'register_blocks']);
        add_filter('block_categories_all', [$this, 'category']);
    }

    /**
     * @param array<int, array<string, mixed>> $categories
     * @return array<int, array<string, mixed>>
     */
    public function category(array $categories): array
    {
        foreach ($categories as $category) {
            if (($category['slug'] ?? '') === self::CATEGORY) {
                return $categories;
            }
        }

        $categories[] = ['slug' => self::CATEGORY, 'title' => __('Kontor Shop', 'kontor-shop'), 'icon' => 'store'];

        return $categories;
    }

    public function register_blocks(): void
    {
        if (!function_exists('register_block_type')) {
            return;
        }

        wp_register_script(
            'kontor-shop-blocks',
            KONTOR_SHOP_URL . 'assets/admin/blocks.js',
            ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor'],
            KONTOR_SHOP_VERSION,
            true
        );
        wp_localize_script('kontor-shop-blocks', 'kontorShopBlocks', [
            'category' => self::CATEGORY,
            'blocks'   => self::editor_data(),
            'i18n'     => [
                'settings' => __('Settings', 'kontor-shop'),
                'shop'     => __('Shop area (shop type slug, optional)', 'kontor-shop'),
                'shopHelp' => __('Only with Kontor Shop Pro: shows only this shop area. Empty = all products.', 'kontor-shop'),
                'perPage'  => __('Products per page (0 = all on one page)', 'kontor-shop'),
                'preview'  => __('The block is shown on the published page. In the editor you only see this placeholder.', 'kontor-shop'),
            ],
        ]);

        foreach (self::BLOCKS as $name => [$shortcode]) {
            register_block_type($name, [
                'api_version'     => 3,
                'editor_script'   => 'kontor-shop-blocks',
                'render_callback' => fn (array $attributes = []): string => self::render($shortcode, $attributes),
                'attributes'      => $name === 'kontor-shop/catalog'
                    ? ['shop' => ['type' => 'string', 'default' => ''], 'perPage' => ['type' => 'number', 'default' => 0]]
                    : [],
                'supports'        => ['html' => false, 'multiple' => $name !== 'kontor-shop/checkout'],
            ]);
        }
    }

    /**
     * Ausgabe = Shortcode mit bereinigten Optionen.
     *
     * @param array<string, mixed> $attributes
     */
    public static function render(string $shortcode, array $attributes): string
    {
        $atts = '';

        if ($shortcode === 'kontor_shop_catalog') {
            $shop     = sanitize_title((string) ($attributes['shop'] ?? ''));
            $per_page = max(0, min(200, (int) ($attributes['perPage'] ?? 0)));
            $atts     = ($shop !== '' ? ' shop="' . $shop . '"' : '') . ($per_page > 0 ? ' per_page="' . $per_page . '"' : '');
        }

        return do_shortcode('[' . $shortcode . $atts . ']');
    }

    /**
     * Titel, Beschreibung und Symbol je Block fuer das Editor-Skript.
     *
     * @return array<string, array{title: string, description: string, icon: string}>
     */
    public static function editor_data(): array
    {
        $texts = [
            'kontor-shop/catalog'   => [__('Shop', 'kontor-shop'), __('All products with filters and search. Usually on the page “Shop”.', 'kontor-shop')],
            'kontor-shop/cart'      => [__('Cart', 'kontor-shop'), __('The cart with quantities and the way to checkout.', 'kontor-shop')],
            'kontor-shop/checkout'  => [__('Checkout', 'kontor-shop'), __('Order form, payment and order confirmation. Use it on exactly one page.', 'kontor-shop')],
            'kontor-shop/cart-icon' => [__('Cart icon', 'kontor-shop'), __('Cart icon with counter, e.g. for the header. Then switch off the floating cart button under Settings → Design.', 'kontor-shop')],
        ];
        $data  = [];

        foreach (self::BLOCKS as $name => [, $icon]) {
            $data[$name] = ['title' => $texts[$name][0], 'description' => $texts[$name][1], 'icon' => $icon];
        }

        return $data;
    }
}
