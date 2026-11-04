<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin\HelpCenter\Content;

use Kontor\Shop\Admin\Settings\Section;

/**
 * Artikel des Hilfe-Centers: Shop-Auftritt (Design, CSS fuer Webmaster,
 * Shop-Seiten). Schema: Admin\HelpCenter\HelpCenter. Die Variablenliste
 * entspricht assets/frontend/shop.css (Kopfkommentar) und
 * docs/THEME_ANBINDUNG.md.
 */
final class ShopArticles
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function articles(): array
    {
        $design_url = Section::url('design');

        return [
            [
                'id'         => 'design',
                'group'      => 'shop',
                'title'      => __('Adjust the shop design', 'kontor-shop'),
                'summary'    => __('Colors, corners, image format – without programming', 'kontor-shop'),
                'keywords'   => __('design color colour theme dark corners radius columns image format font preview', 'kontor-shop'),
                'help_pages' => ['settings-design'],
                'what'       => __('So that the shop looks like the rest of your website: same colors, same corners, same font.', 'kontor-shop'),
                'steps'      => [
                    ['text' => __('Open Kontor Shop → Settings → Design.', 'kontor-shop'), 'url' => $design_url],
                    __('Choose the basic style “Like my theme” and look at the preview. It shows your real shop page with the settings on the left, before you save.', 'kontor-shop'),
                    __('If something doesn’t fit, change only that: main color, color scheme, corners, products per row, image format or font of the product names.', 'kontor-shop'),
                    __('Switch the preview to “Phone” and check the shop there too.', 'kontor-shop'),
                    __('Click “Save”.', 'kontor-shop'),
                ],
                'effect'     => [
                    __('After saving, all visitors see the new design right away.', 'kontor-shop'),
                    __('If your website uses a cache plugin (e.g. WP Rocket, LiteSpeed Cache), empty the cache, otherwise visitors may still see the old design for a while.', 'kontor-shop'),
                    __('Categories with their own accent color keep it; the main color applies to everything else.', 'kontor-shop'),
                ],
                'sections'   => [
                    [
                        'title' => __('What the settings do', 'kontor-shop'),
                        'list'  => [
                            __('Basic style: “Like my theme” takes corners and shadows from your theme. “Clear”, “Soft” and “Angular” are ready-made looks.', 'kontor-shop'),
                            __('Main shop color: “from the theme” follows your theme, even if it changes later. “Own color” stays fixed. The text on buttons automatically turns white or dark; the contrast shown next to it should be at least 4.5 : 1.', 'kontor-shop'),
                            __('Color scheme: choose “Dark” if your website has a dark background. Cards, cart and checkout then become dark too.', 'kontor-shop'),
                            __('Corners: 0 means square corners. Without “Own rounding”, the basic style decides.', 'kontor-shop'),
                            __('Products per row: the maximum on a wide screen. Phones always show one product per row.', 'kontor-shop'),
                            __('Image format: photos are cropped to this format in the catalog. Use “Portrait” for clothing so that shirts are not cut off.', 'kontor-shop'),
                            __('Card effect: what happens when the mouse moves over a product. On phones the cards stay calm.', 'kontor-shop'),
                        ],
                    ],
                ],
                'faq'        => [
                    ['q' => __('I changed the color in my theme, but the shop keeps the old color.', 'kontor-shop'), 'a' => __('Under Design, a fixed “own color” is probably saved. Choose “from the theme” and save. Versions before 2.1 saved a fixed color automatically; the Design page shows a note with a “Use theme color” button in that case.', 'kontor-shop')],
                    ['q' => __('The text on the buttons is hard to read.', 'kontor-shop'), 'a' => __('Choose a darker or lighter main color until the contrast next to the color field shows “easy to read”.', 'kontor-shop')],
                    ['q' => __('The preview stays empty.', 'kontor-shop'), 'a' => __('The preview loads your shop page. If a security plugin forbids showing your own pages in a frame, the preview stays empty – the settings still work. Check the result with “Open shop”.', 'kontor-shop')],
                ],
                'related'    => ['design-css', 'shop-pages', 'problem-shop-looks-wrong'],
            ],
            [
                'id'         => 'design-css',
                'group'      => 'shop',
                'title'      => __('Design for web developers (CSS)', 'kontor-shop'),
                'summary'    => __('Variables, order of precedence, class names, examples', 'kontor-shop'),
                'keywords'   => __('css variables child theme style.css custom properties developer webmaster class hook filter', 'kontor-shop'),
                'what'       => __('For whoever looks after the website: how to fit the shop into any theme with a few CSS variables, without touching the shop’s markup.', 'kontor-shop'),
                'sections'   => [
                    [
                        'title'      => __('The principle', 'kontor-shop'),
                        'paragraphs' => [
                            __('The shop only reads its own CSS variables (--kontor-shop-…), each with a neutral default. A theme adapts the shop by setting these variables – nothing else. Without them, the shop looks clean but neutral.', 'kontor-shop'),
                            __('Set the variables with :where(:root). That has no specificity, so it is only a suggestion: a value saved under Settings → Design always wins. If “Like my theme” is selected there and no own color is saved, your values apply.', 'kontor-shop'),
                        ],
                    ],
                    [
                        'title' => __('Order of precedence (highest first)', 'kontor-shop'),
                        'list'  => [
                            __('Accent color of a category or shop type (Kontor Shop → Categories).', 'kontor-shop'),
                            __('Settings → Design (printed as :root{…}).', 'kontor-shop'),
                            __('Your theme CSS with :where(:root){…}.', 'kontor-shop'),
                            __('Values that Kontor Shop reads from the theme.json of block themes (printed before the theme CSS).', 'kontor-shop'),
                            __('Defaults in the shop CSS.', 'kontor-shop'),
                        ],
                    ],
                    [
                        'title' => __('All variables', 'kontor-shop'),
                        'table' => [
                            'head' => [__('Variable', 'kontor-shop'), __('Affects', 'kontor-shop'), __('Default', 'kontor-shop')],
                            'rows' => [
                                ['--kontor-shop-primary', __('buttons, active filters, links, focus ring', 'kontor-shop'), '#2271b1'],
                                ['--kontor-shop-on-primary', __('text on the main color', 'kontor-shop'), '#fff'],
                                ['--kontor-shop-cart-icon-color', __('floating cart button', 'kontor-shop'), __('= main color', 'kontor-shop')],
                                ['--kontor-shop-cart-icon-text', __('icon on the cart button', 'kontor-shop'), __('= text on main color', 'kontor-shop')],
                                ['--kontor-shop-radius', __('buttons, fields, notes', 'kontor-shop'), '12px'],
                                ['--kontor-shop-card-radius', __('product cards only', 'kontor-shop'), __('= radius', 'kontor-shop')],
                                ['--kontor-shop-border', __('borders of cards and fields, dividers', 'kontor-shop'), '#dcdcde'],
                                ['--kontor-shop-surface', __('background of cards, fields, cart drawer', 'kontor-shop'), '#fff'],
                                ['--kontor-shop-soft', __('muted areas: image background, hover, info boxes', 'kontor-shop'), '#f6f7f7'],
                                ['--kontor-shop-text', __('main text', 'kontor-shop'), '#1d2327'],
                                ['--kontor-shop-muted', __('secondary text: description, VAT line, labels', 'kontor-shop'), '#646970'],
                                ['--kontor-shop-shadow', __('card shadow at rest', 'kontor-shop'), __('very subtle', 'kontor-shop')],
                                ['--kontor-shop-shadow-hover', __('card shadow on hover', 'kontor-shop'), '0 14px 32px rgba(0,0,0,.1)'],
                                ['--kontor-shop-heading-font', __('font of the product names', 'kontor-shop'), __('inherited', 'kontor-shop')],
                                ['--kontor-shop-card-min', __('minimum width of a card in the grid', 'kontor-shop'), '260px'],
                                ['--kontor-shop-columns', __('at most this many cards per row', 'kontor-shop'), __('as many as fit', 'kontor-shop')],
                                ['--kontor-shop-image-ratio', __('aspect ratio of product images in the catalog', 'kontor-shop'), '4 / 3'],
                                ['--kontor-shop-success', __('done states, “added” button, status steps', 'kontor-shop'), '#1a7f37'],
                                ['--kontor-shop-warning', __('notes at checkout', 'kontor-shop'), '#b45309'],
                                ['--kontor-shop-danger', __('errors, cart counter', 'kontor-shop'), '#b32d2e'],
                                ['--kontor-shop-danger-soft', __('background of error boxes', 'kontor-shop'), '#fcf0f1'],
                                ['--kontor-shop-danger-text', __('text in error boxes', 'kontor-shop'), '#8a1f20'],
                                ['--kontor-shop-toast-bg', __('short messages at the bottom', 'kontor-shop'), '#1d2327'],
                                ['--kontor-shop-toast-text', __('text of the short messages', 'kontor-shop'), '#fff'],
                            ],
                        ],
                    ],
                    [
                        'title'      => __('Template for your child theme', 'kontor-shop'),
                        'paragraphs' => [
                            __('Put this block at the end of the style.css of your (child) theme and only set what differs. Use your theme’s own variables where possible, then the shop follows future theme changes. Without a child theme, the same block works in the “Custom CSS” field under Settings → Design.', 'kontor-shop'),
                        ],
                        'code'       => "/* Kontor Shop - Plugin-Anbindung */\n:where(:root) {\n  --kontor-shop-primary:      var(--my-brand);\n  --kontor-shop-on-primary:   #fff;\n  --kontor-shop-radius:       var(--my-radius-medium);\n  --kontor-shop-card-radius:  var(--my-radius-large);\n  --kontor-shop-border:       rgba(0, 0, 0, .08);\n  --kontor-shop-surface:      var(--my-surface);\n  --kontor-shop-soft:         var(--my-surface-muted);\n  --kontor-shop-text:         var(--my-text);\n  --kontor-shop-muted:        var(--my-text-muted);\n  --kontor-shop-heading-font: var(--my-font-heading);\n}\n",
                    ],
                    [
                        'title'      => __('Class names you can rely on', 'kontor-shop'),
                        'paragraphs' => [
                            __('If a variable is not enough, target these classes. They stay stable across updates; always start a rule with one of them so it only affects the shop.', 'kontor-shop'),
                        ],
                        'list'       => [
                            '.kontor-shop-catalog, .kontor-shop-catalog__grid, .kontor-shop-catalog__filter',
                            '.kontor-shop-product, .kontor-shop-product__media, .kontor-shop-product__name, .kontor-shop-product__price',
                            '.kontor-shop-add-to-cart, .kontor-shop-btn',
                            '.kontor-shop-detail (' . __('product page', 'kontor-shop') . ')',
                            '.kontor-shop-cart, .kontor-shop-cart-drawer, .kontor-shop-cart-icon',
                            '.kontor-shop-checkout, .kontor-shop-order-confirmation',
                            'body.kontor-shop-fx-spotlight, body.kontor-shop-fx-none (' . __('card effect', 'kontor-shop') . ')',
                        ],
                    ],
                    [
                        'title'      => __('Changing markup and texts (PHP)', 'kontor-shop'),
                        'paragraphs' => [
                            __('Kontor Shop has no template files to copy. Instead, filters change specific parts, for example kontor_shop/catalog/card_attributes (attributes of a product card), kontor_shop/catalog/toolbar (extra controls above the catalog), kontor_shop/product/facts (facts on the product page) and kontor_shop/checkout/notices. Every filter is documented in the code where it is applied: search the plugin for apply_filters( \'kontor_shop/.', 'kontor-shop'),
                        ],
                    ],
                    [
                        'title' => __('Checklist: does it fit?', 'kontor-shop'),
                        'list'  => [
                            __('Cards are compact – no large gaps between name, text and price.', 'kontor-shop'),
                            __('Product names in the heading font, not underlined.', 'kontor-shop'),
                            __('Buttons and active filters in the brand color, text on them readable.', 'kontor-shop'),
                            __('Corners and shadows like the cards of your theme.', 'kontor-shop'),
                            __('Quantity and “Add to cart” side by side – otherwise increase --kontor-shop-card-min.', 'kontor-shop'),
                            __('Phone: one column, large touch targets, no sideways scrolling.', 'kontor-shop'),
                        ],
                    ],
                ],
                'faq'        => [
                    ['q' => __('My variables have no effect.', 'kontor-shop'), 'a' => __('Probably a value is saved under Settings → Design – it wins. Choose “Like my theme” and “from the theme” there and save. Also empty any page cache.', 'kontor-shop')],
                    ['q' => __('Buttons look like the theme’s buttons.', 'kontor-shop'), 'a' => __('Some themes style every button very specifically. First check whether a variable is enough; otherwise add a rule starting with .kontor-shop-add-to-cart button in your theme or the Custom CSS field.', 'kontor-shop')],
                    ['q' => __('There is no spotlight effect.', 'kontor-shop'), 'a' => __('The theme must call body_class() in its <body> tag. On touch devices the effect is intentionally off.', 'kontor-shop')],
                ],
                'related'    => ['design', 'shop-pages'],
            ],
            [
                'id'         => 'shop-pages',
                'group'      => 'shop',
                'title'      => __('Place the shop on your website', 'kontor-shop'),
                'summary'    => __('Shop pages, shortcodes, cart icon in the menu', 'kontor-shop'),
                'keywords'   => __('shortcode page catalog cart checkout menu icon embed block', 'kontor-shop'),
                'what'       => __('When Kontor Shop is activated, it creates the pages Shop, Cart and Checkout. This explains how to put them in your menu or show the shop somewhere else.', 'kontor-shop'),
                'steps'      => [
                    ['text' => __('Add the pages “Shop” and “Cart” to your menu under Appearance → Menus (block themes: Appearance → Editor → Navigation).', 'kontor-shop'), 'url' => admin_url('nav-menus.php')],
                    __('To show the shop on another page, add the block “Shop” there (block category “Kontor Shop”). In the classic editor, use the shortcode [kontor_shop_catalog] instead.', 'kontor-shop'),
                    __('Split the catalog into pages: in the block settings on the right, set “Products per page”, or use [kontor_shop_catalog per_page="24"].', 'kontor-shop'),
                    __('Cart icon in your own header: block “Cart icon” (or [kontor_shop_cart_icon]). Then switch off the floating cart button under Settings → Design.', 'kontor-shop'),
                ],
                'effect'     => [
                    __('Every product also has its own page with its own address, which you can share or link to.', 'kontor-shop'),
                    __('The checkout always uses the page with [kontor_shop_checkout]. Don’t delete it – without it, nobody can order.', 'kontor-shop'),
                ],
                'sections'   => [
                    [
                        'title' => __('All shortcodes', 'kontor-shop'),
                        'list'  => [
                            '[kontor_shop_catalog] – ' . __('products with filters and search', 'kontor-shop'),
                            '[kontor_shop_cart] – ' . __('cart', 'kontor-shop'),
                            '[kontor_shop_checkout] – ' . __('checkout and order confirmation', 'kontor-shop'),
                            '[kontor_shop_cart_icon] – ' . __('cart icon with counter', 'kontor-shop'),
                        ],
                    ],
                ],
                'faq'        => [
                    ['q' => __('I deleted the shop page by mistake.', 'kontor-shop'), 'a' => __('Restore it from the trash under Pages. If it is gone for good, deactivate and reactivate Kontor Shop – the missing page is created again.', 'kontor-shop')],
                ],
                'related'    => ['design', 'design-css'],
            ],
        ];
    }
}
