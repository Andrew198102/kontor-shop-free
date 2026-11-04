<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Cart;

use Kontor\Shop\Context\Membership;
use Kontor\Shop\Core\Registerable;
use Kontor\Shop\Domain\Cart\Cart;
use Kontor\Shop\Infrastructure\Cart\CookieCartStore;
use Kontor\Shop\Infrastructure\Catalog\SellableItems;
use Kontor\Shop\Infrastructure\Checkout\CheckoutSession;
use Kontor\Shop\Infrastructure\Checkout\ReservationStore;
use Kontor\Shop\Infrastructure\Logging\Logger;
use Kontor\Shop\Infrastructure\Repositories\ProductRepository;
use Kontor\Shop\Infrastructure\Repositories\StockRepository;
use Kontor\Shop\Modules\Catalog\ProductImage;
use Kontor\Shop\Settings\Repository;

/**
 * Warenkorb-Ausgabe (Shortcodes `[kontor_shop_cart]` fuer die volle
 * Warenkorb-Tabelle und `[kontor_shop_cart_icon]` fuer ein manuell im Theme
 * platzierbares Icon) + AJAX-Handler zum Hinzufuegen/Aendern/Entfernen von
 * Positionen (Abschnitt 3, Ziele 5/8). Zusaetzlich ein Off-Canvas-Drawer +
 * schwebender Warenkorb-Button (design.show_floating_cart_button), damit
 * der Warenkorb sich auf der Seite bemerkbar macht, ohne dass das Plugin
 * sich in eine bestimmte Theme-Header-Struktur einklinken muss. Mengen
 * werden bei jeder Aenderung gegen den aktuellen Bestand gekappt - eine
 * echte Reservierung mit TTL folgt erst beim Checkout-Einstieg (P4,
 * Abschnitt 6.1).
 */
final class CartModule implements Registerable
{
    private const NONCE_ACTION = 'kontor_shop_cart';

    private static bool $icon_shortcode_used = false;

    private Repository $settings;
    private ProductRepository $products;
    private StockRepository $stock;
    private ReservationStore $reservations;
    private CookieCartStore $store;
    private SellableItems $items;

    public function __construct(Repository $settings)
    {
        $this->items    = new SellableItems();
        $this->settings = $settings;
        $this->products = new ProductRepository();
        $this->stock    = new StockRepository();
        $this->reservations = new ReservationStore();
        $this->store    = new CookieCartStore();
    }

    public function register(): void
    {
        add_shortcode('kontor_shop_cart', [$this, 'render_shortcode']);
        add_shortcode('kontor_shop_cart_icon', [$this, 'render_icon_shortcode']);

        add_action('wp_ajax_kontor_shop_cart_add', [$this, 'ajax_add']);
        add_action('wp_ajax_nopriv_kontor_shop_cart_add', [$this, 'ajax_add']);
        add_action('wp_ajax_kontor_shop_cart_update', [$this, 'ajax_update']);
        add_action('wp_ajax_nopriv_kontor_shop_cart_update', [$this, 'ajax_update']);
        add_action('wp_ajax_kontor_shop_cart_remove', [$this, 'ajax_remove']);
        add_action('wp_ajax_nopriv_kontor_shop_cart_remove', [$this, 'ajax_remove']);

        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_footer', [$this, 'render_drawer_and_button']);
    }

    /**
     * shop.css/cart.js sind klein und werden vom Off-Canvas-Drawer +
     * schwebenden Button gebraucht, die standardmaessig site-weit
     * erscheinen (design.show_floating_cart_button) - deshalb bewusst immer
     * geladen statt nur auf Seiten mit Shop-Shortcode. Das umgeht auch das
     * WP-Timing-Problem, dass Shortcodes erst nach wp_enqueue_scripts
     * laufen (spaetes wp_enqueue_style() waere sonst nie ausgegeben worden).
     */
    public function enqueue_assets(): void
    {
        wp_enqueue_style(
            'kontor-shop-frontend',
            KONTOR_SHOP_URL . 'assets/frontend/shop.css',
            [],
            KONTOR_SHOP_VERSION
        );

        // Gemeinsame Leiste fuer schwebende Knoepfe (Theme „nach oben“, Suchspiel …), siehe fab-dock.js.
        wp_enqueue_script(
            'kontor-shop-fab-dock',
            KONTOR_SHOP_URL . 'assets/frontend/fab-dock.js',
            [],
            KONTOR_SHOP_VERSION,
            true
        );

        wp_enqueue_script(
            'kontor-shop-cart',
            KONTOR_SHOP_URL . 'assets/frontend/cart.js',
            ['kontor-shop-fab-dock'],
            KONTOR_SHOP_VERSION,
            true
        );

        wp_localize_script('kontor-shop-cart', 'kontorShopCart', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE_ACTION),
            'added'   => __('Added', 'kontor-shop'),
            'error'   => __('Sorry, that didn\'t work. Please try again.', 'kontor-shop'),
            'errorShort' => __('Error', 'kontor-shop'),
            'timeout' => __('The server isn\'t responding. Please check your connection and try again.', 'kontor-shop'),
            'chooseVariant' => __('Please select a variant first.', 'kontor-shop'),
        ]);
    }

    public function ajax_add(): void
    {
        $this->guard(function (): void {
            $product_id = absint($_POST['product_id'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in CartModule::guard
            $variant_id = absint($_POST['variant_id'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in CartModule::guard
            $quantity   = max(1, absint($_POST['quantity'] ?? 1)); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in CartModule::guard
            $item       = $product_id > 0 ? $this->items->resolve($product_id, $variant_id) : null;

            if ($item !== null && $item->product->has_variants() && $item->variant === null && $item->product->is_sellable()) {
                wp_send_json_error(['message' => __('Please select a variant (e.g. size) first.', 'kontor-shop'), 'code' => 'variant'], 422);
            }

            if ($item === null || !$item->is_sellable()) {
                wp_send_json_error(['message' => __('Sorry, this product is no longer available. Please reload the page.', 'kontor-shop')], 404);
            }

            $available = $this->reservations->free_for($product_id, CheckoutSession::existing_key(), $variant_id);

            if ($available === 0) {
                wp_send_json_error(['message' => sprintf(
                    /* translators: %s: product name */
                    __('Sorry, "%s" is sold out.', 'kontor-shop'),
                    $item->name()
                )], 409);
            }

            $cart   = $this->store->load();
            $wanted = $cart->quantity_for($product_id, $variant_id) + $quantity;
            $final  = min($wanted, $available);

            $cart->set_quantity($product_id, $final, $variant_id);
            $this->store->save($cart);

            $payload = $this->cart_payload($cart);

            if ($final < $wanted) {
                $payload['notice'] = sprintf(
                    /* translators: 1: product name, 2: available quantity */
                    __('Only %2$d of "%1$s" are still available - the quantity in your cart has been adjusted.', 'kontor-shop'),
                    $item->name(),
                    $available
                );
            }

            wp_send_json_success($payload);
        });
    }

    public function ajax_update(): void
    {
        $this->guard(function (): void {
            $product_id = absint($_POST['product_id'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in CartModule::guard
            $variant_id = absint($_POST['variant_id'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in CartModule::guard
            $quantity   = absint($_POST['quantity'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in CartModule::guard
            $available  = $this->reservations->free_for($product_id, CheckoutSession::existing_key(), $variant_id);

            $cart = $this->store->load();
            $cart->set_quantity($product_id, min($quantity, $available), $variant_id);
            $this->store->save($cart);
            $this->release_hold($product_id, $variant_id);

            $payload = $this->cart_payload($cart);

            if ($quantity > $available) {
                $payload['notice'] = sprintf(
                    /* translators: %d: available quantity */
                    __('Only %d are still available - the quantity has been adjusted.', 'kontor-shop'),
                    $available
                );
            }

            wp_send_json_success($payload);
        });
    }

    public function ajax_remove(): void
    {
        $this->guard(function (): void {
            $product_id = absint($_POST['product_id'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in CartModule::guard
            $variant_id = absint($_POST['variant_id'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in CartModule::guard

            $cart = $this->store->load();
            $cart->remove($product_id, $variant_id);
            $this->store->save($cart);
            $this->release_hold($product_id, $variant_id);

            wp_send_json_success($this->cart_payload($cart));
        });
    }

    /**
     * Aendert sich der Warenkorb, faellt die bisherige Reservierung dieser
     * Position weg; die Kasse reserviert beim naechsten Aufruf neu.
     */
    private function release_hold(int $product_id, int $variant_id): void
    {
        $session = CheckoutSession::existing_key();

        if ($session !== null) {
            $this->reservations->release_product($product_id, $session, $variant_id);
        }
    }

    /**
     * Gemeinsamer Rahmen der AJAX-Aktionen: Sicherheitspruefung (mit
     * verstaendlicher Meldung bei abgelaufener Sitzung, z. B. gecachte Seite)
     * und Abfangen unerwarteter Fehler - der Kunde bekommt eine klare Meldung
     * samt Referenznummer, das Protokoll die Details.
     */
    private function guard(callable $action): void
    {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash((string) ($_POST['nonce'] ?? ''))), self::NONCE_ACTION)) {
            wp_send_json_error(['message' => __('Your session has expired. Please reload the page and try again.', 'kontor-shop'), 'code' => 'expired'], 403);
        }

        try {
            $action();
        } catch (\Throwable $e) {
            $reference = Logger::exception($e, 'cart', 'Warenkorb-Aktion fehlgeschlagen');

            wp_send_json_error([
                'message'   => sprintf(
                    /* translators: %s: error reference number */
                    __('Sorry, that didn\'t work (technical error). Please try again. Error code: %s', 'kontor-shop'),
                    $reference
                ),
                'reference' => $reference,
            ], 500);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function cart_payload(Cart $cart): array
    {
        return [
            'count' => $cart->total_quantity(),
            'html'  => $this->render_cart($cart),
        ];
    }

    public function render_shortcode(): string
    {
        return $this->render_cart($this->store->load());
    }

    public function render_icon_shortcode(): string
    {
        self::$icon_shortcode_used = true;

        return $this->render_icon_markup('kontor-shop-cart-icon--inline', $this->store->load()->total_quantity());
    }

    public function render_drawer_and_button(): void
    {
        $show_floating = (bool) $this->settings->get('design.show_floating_cart_button', true);

        if (!$show_floating && !self::$icon_shortcode_used) {
            return;
        }

        $cart = $this->store->load();

        echo '<div class="kontor-shop-cart-drawer-backdrop" id="kontor-shop-cart-drawer-backdrop"></div>';
        echo '<aside class="kontor-shop-cart-drawer" id="kontor-shop-cart-drawer" role="dialog" aria-modal="true"'
            . ' aria-labelledby="kontor-shop-cart-drawer-title" aria-hidden="true">';
        echo '<div class="kontor-shop-cart-drawer__header">';
        echo '<h2 id="kontor-shop-cart-drawer-title">' . esc_html__('Cart', 'kontor-shop') . '</h2>';
        echo '<button type="button" class="kontor-shop-cart-drawer__close" id="kontor-shop-cart-drawer-close" aria-label="'
            . esc_attr__('Close', 'kontor-shop') . '">&times;</button>';
        echo '</div>';
        echo '<div class="kontor-shop-cart-drawer__body" id="kontor-shop-cart-drawer-body">' . $this->render_cart($cart) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_cart liefert escaptes HTML (mit Mengenfeldern, daher kein wp_kses_post)
        echo '</aside>';

        if ($show_floating) {
            $hide_when_empty = (bool) $this->settings->get('design.hide_cart_icon_when_empty', false);
            $extra_class     = $hide_when_empty && $cart->is_empty() ? 'is-empty' : '';

            echo $this->render_icon_markup($extra_class, $cart->total_quantity(), $hide_when_empty); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_icon_markup liefert escaptes HTML
        }
    }

    private function render_icon_markup(string $extra_class, int $count, bool $hide_when_empty = false): string
    {
        $class = trim('kontor-shop-cart-icon ' . $extra_class);

        $html = '<button type="button" class="' . esc_attr($class) . '" id="kontor-shop-cart-toggle"'
            . ($hide_when_empty ? ' data-hide-when-empty="1"' : '') . ' aria-label="'
            . esc_attr__('Open cart', 'kontor-shop') . '">';
        $html .= '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 4h-2l-1 2h2l3.6 7.59-1.35 2.44c-.16.28-.25.61-.25.97 0 1.1.9 2 2 2h12v-2h-11.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1h-14.19l-.94-2zm2 15c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2zm9 0c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z"></path></svg>';
        $html .= '<span class="kontor-shop-cart-icon__count" data-count="' . esc_attr((string) $count) . '">' . esc_html((string) $count) . '</span>';
        $html .= '</button>';

        return $html;
    }

    private function render_cart(Cart $cart): string
    {
        if ($cart->is_empty()) {
            return '<div class="kontor-shop-cart kontor-shop-cart--empty"><p>'
                . esc_html__('Your cart is empty.', 'kontor-shop') . '</p></div>';
        }

        $is_member   = Membership::is_member();
        $placeholder = absint($this->settings->get('design.placeholder_image_id', 0));
        $total       = 0.0;

        // Alles in EINEM Wurzelelement: cart.js ersetzt es per outerHTML, so
        // bleiben keine verwaisten Geschwister-Elemente (Summe/Link) stehen.
        $html = '<div class="kontor-shop-cart"><ul class="kontor-shop-cart__list">';

        foreach ($cart->items() as $item) {
            $sellable = $this->items->resolve($item->product_id, $item->variant_id);

            if ($sellable === null) {
                continue;
            }

            $unit_price = $sellable->price_for($is_member);
            $line_total = $unit_price * $item->quantity;
            $total     += $line_total;
            $available  = $this->reservations->free_for($item->product_id, CheckoutSession::existing_key(), $item->variant_id);
            $field_id   = 'kontor-shop-cart-qty-' . $item->product_id . ($item->variant_id > 0 ? '-' . $item->variant_id : '');
            $base_price = $sellable->unit_price_text($unit_price);

            $html .= '<li class="kontor-shop-cart__item" data-product-id="' . esc_attr((string) $item->product_id) . '" data-variant-id="' . esc_attr((string) $item->variant_id) . '">';
            $html .= '<div class="kontor-shop-cart__thumb">'
                . ProductImage::render($sellable->image_id(), $sellable->product->name, 'thumbnail', $placeholder) . '</div>';
            $html .= '<div class="kontor-shop-cart__info">';
            $html .= '<span class="kontor-shop-cart__name">' . esc_html($sellable->product->name) . '</span>';

            if ($sellable->variant !== null) {
                $html .= '<span class="kontor-shop-cart__variant">' . esc_html($sellable->variant_label()) . '</span>';
            }

            $html .= '<span class="kontor-shop-cart__unit">' . esc_html(number_format_i18n($unit_price, 2)) . ' €'
                . ($base_price !== '' ? ' <small class="kontor-shop-unit-price">(' . esc_html($base_price) . ')</small>' : '') . '</span>';
            $html .= '<span class="kontor-shop-cart__controls">';
            $html .= '<label class="kontor-shop-sr" for="' . esc_attr($field_id) . '">'
                . esc_html__('Quantity', 'kontor-shop') . '</label>';
            $html .= '<input type="number" id="' . esc_attr($field_id) . '" class="kontor-shop-cart__qty" min="0" max="'
                . esc_attr((string) $available) . '" value="' . esc_attr((string) $item->quantity) . '" inputmode="numeric" />';
            $html .= '<button type="button" class="kontor-shop-cart__remove">' . esc_html__('Remove', 'kontor-shop') . '</button>';
            $html .= '</span></div>';
            $html .= '<span class="kontor-shop-cart__line">' . esc_html(number_format_i18n($line_total, 2)) . ' €</span>';
            $html .= '</li>';
        }

        $html .= '</ul>';
        $html .= '<p class="kontor-shop-cart__total"><span>' . esc_html__('Total', 'kontor-shop') . '</span> <strong>'
            . esc_html(number_format_i18n($total, 2)) . ' €</strong></p>';
        $html .= '<p class="kontor-shop-cart__hint">' . esc_html__('Shipping costs are calculated at checkout.', 'kontor-shop') . '</p>';

        $checkout_page_id = absint($this->settings->get('general.checkout_page_id', 0));
        $checkout_url      = $checkout_page_id > 0 ? get_permalink($checkout_page_id) : false;

        /**
         * Filter kontor_shop/cart/notice: Zeile unter der Summe im Warenkorb,
         * z. B. "Angemeldet als Mitglied" oder "Mitglied? Jetzt anmelden"
         * (Pro, Mitgliedschaft). Fertiges, escaptes HTML. Standard: ''.
         *
         * @param string $html
         * @param bool   $is_member Kaeufer gilt als Mitglied (kontor_shop/membership/is_member)
         * @param string $return_to Ruecksprung nach einer Anmeldung (Kasse)
         */
        $html .= (string) apply_filters('kontor_shop/cart/notice', '', $is_member, $checkout_url ?: home_url('/'));

        if ($checkout_url) {
            $html .= '<a class="kontor-shop-btn kontor-shop-cart__checkout" href="' . esc_url($checkout_url) . '">'
                . esc_html__('Go to checkout', 'kontor-shop') . '</a>';
        }

        $html .= '</div>';

        return $html;
    }
}
