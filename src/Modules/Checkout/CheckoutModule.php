<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Checkout;

use Kontor\Shop\Settings\TextDefaults;
use Kontor\Shop\Context\Membership;
use Kontor\Shop\Context\RequestContext;
use Kontor\Shop\Core\Registerable;
use Kontor\Shop\Domain\Cart\Cart;
use Kontor\Shop\Domain\Checkout\CheckoutAborted;
use Kontor\Shop\Domain\Checkout\CheckoutValidator;
use Kontor\Shop\Domain\Notifications\DefaultTemplates;
use Kontor\Shop\Domain\Order\Carrier;
use Kontor\Shop\Domain\Order\Fulfilment;
use Kontor\Shop\Domain\Order\ItemCancellation;
use Kontor\Shop\Domain\Order\Order;
use Kontor\Shop\Domain\Order\OrderItem;
use Kontor\Shop\Domain\Order\OrderLink;
use Kontor\Shop\Domain\Order\OrderStatus;
use Kontor\Shop\Domain\Order\PaymentDeadline;
use Kontor\Shop\Domain\Order\PaymentMethod;
use Kontor\Shop\Domain\Stock\Availability;
use Kontor\Shop\Domain\Stock\MovementType;
use Kontor\Shop\Domain\Tax\TaxCalculator;
use Kontor\Shop\Infrastructure\Cart\CookieCartStore;
use Kontor\Shop\Infrastructure\Catalog\SellableItems;
use Kontor\Shop\Infrastructure\Checkout\CheckoutSession;
use Kontor\Shop\Infrastructure\Checkout\ReservationStore;
use Kontor\Shop\Infrastructure\Checkout\SpamGuard;
use Kontor\Shop\Infrastructure\Logging\Logger;
use Kontor\Shop\Infrastructure\Payment\BankAccounts;
use Kontor\Shop\Infrastructure\Repositories\OrderRepository;
use Kontor\Shop\Infrastructure\Repositories\ProductRepository;
use Kontor\Shop\Infrastructure\Repositories\StockRepository;
use Kontor\Shop\Infrastructure\Repositories\WarehouseRepository;
use Kontor\Shop\Modules\Catalog\ProductImage;
use Kontor\Shop\Modules\Notifications\NotificationsModule;
use Kontor\Shop\Modules\Payments\PaymentMethodResolver;
use Kontor\Shop\Modules\Payments\PaymentOptions;
use Kontor\Shop\Modules\Shipping\ShippingCalculator;
use Kontor\Shop\Settings\Repository;
use Kontor\Shop\Support\Countries;
use Kontor\Shop\Support\LegalText;
use Kontor\Shop\Support\Translation;

/**
 * Checkout-Flow (Shortcode `[kontor_shop_checkout]`, Abschnitt 6.1): Bestand
 * reservieren, Kundendaten erfassen, Bestellung anlegen, Reservierung in
 * eine endgueltige online_sale-Bewegung ueberfuehren. Kein Redirect nach
 * dem Absenden - die Bestaetigung wird direkt in derselben Antwort gezeigt,
 * um "headers already sent" innerhalb eines Shortcodes zu vermeiden.
 *
 * Rabatte (Pro, Rabattcodes) docken ueber Filter und Aktionen an
 * (kontor_shop/checkout/*, Doc-Kommentare an der jeweiligen Stelle): Feld in
 * der Zusammenfassung, Einloesen per Formular-Knopf, Pruefung beim Bestellen,
 * Rabattanteil je Position, Sperre und Einloesung in der Transaktion. Ohne
 * Erweiterung gibt es keinen Rabatt (alle Anteile 0). Die Rechnung mit
 * rabattierten Positionen (Bestellstatus-Seite, Storno, Retoure, USt) bleibt
 * im Kern - auch alte Bestellungen mit Rabatt zeigen sie richtig an.
 */
final class CheckoutModule implements Registerable
{
    private const NONCE_ACTION    = 'kontor_shop_checkout';
    private const FORM_ID         = 'kontor-shop-checkout-form';

    private Repository $settings;
    private ProductRepository $products;
    private StockRepository $stock;
    private OrderRepository $orders;
    private ReservationStore $reservations;
    private CookieCartStore $cart_store;
    private ShippingCalculator $shipping;
    private ?string $submit_error = null;

    /** @var array<int, string> Hinweise zur Reservierung beim Oeffnen der Kasse */
    private array $hold_notices = [];

    private ?int $hold_until = null;

    /** @var array<string, string> Fehlermeldungen je Formularfeld (Feldname => Text) */
    private array $field_errors = [];

    /** @var array<int, int> */
    private array $confirmed_orders = [];

    /** @var array<string, string> Vorbelegung der Kassenfelder aus dem Mitglieds-Login (Feldname => Wert) */
    private array $prefill = [];

    private PaymentMethodResolver $payment_methods;
    private BankAccounts $bank_accounts;
    private WarehouseRepository $warehouses;
    private SellableItems $items;

    public function __construct(Repository $settings)
    {
        $this->settings        = $settings;
        $this->products        = new ProductRepository();
        $this->stock           = new StockRepository();
        $this->orders          = new OrderRepository();
        $this->reservations    = new ReservationStore();
        $this->cart_store      = new CookieCartStore();
        $this->shipping        = new ShippingCalculator($settings);
        $this->payment_methods = new PaymentMethodResolver($settings);
        $this->bank_accounts   = new BankAccounts($settings);
        $this->warehouses      = new WarehouseRepository();
        $this->items           = new SellableItems($this->products);
    }

    public function register(): void
    {
        add_shortcode('kontor_shop_checkout', [$this, 'render_shortcode']);
        add_action('template_redirect', [$this, 'maybe_handle_submit'], 5);
    }

    /**
     * Verarbeitet das Absenden VOR der Ausgabe (template_redirect): bei Erfolg
     * Weiterleitung auf die Bestaetigungsseite (Post/Redirect/Get) - so loest ein
     * Neuladen keine zweite Bestellung aus und die Zahlungsdaten bleiben abrufbar.
     * Die Seite ist ueber ein geheimes Token (32 Zeichen, 30 Tage gueltig)
     * erreichbar, nie ueber eine Bestell-ID.
     */
    public function maybe_handle_submit(): void
    {
        if (!$this->is_checkout_request()) {
            return;
        }

        if (isset($_GET['kontor_order'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige-Parameter
            nocache_headers();
            header('X-Robots-Tag: noindex, nofollow');

            return;
        }

        $method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';

        if ($method !== 'POST' || !isset($_POST['kontor_shop_checkout_nonce'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nur Pruefung, ob abgeschickt wurde; Nonce wird unten geprueft
            // Kasse geoeffnet: Ware fuer den Kunden zuruecklegen (muss vor jeder Ausgabe passieren, der Cookie wird hier gesetzt).
            nocache_headers();
            $this->hold_cart();

            /**
             * Aktion kontor_shop/checkout/opened: die Kasse wurde aufgerufen (GET,
             * vor jeder Ausgabe - Cookies lassen sich noch setzen), die Ware ist
             * reserviert. Pro prueft hier einen gemerkten Rabattcode erneut;
             * Hinweise fuer den Kunden kommen ueber kontor_shop/checkout/notices.
             *
             * @param Cart $cart Warenkorb nach dem Reservieren (kann leer sein)
             */
            do_action('kontor_shop/checkout/opened', $this->cart_store->load());

            return;
        }

        $cart = $this->cart_store->load();

        if ($cart->is_empty()) {
            return;
        }

        $is_member = Membership::is_member();
        $nonce_ok  = wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['kontor_shop_checkout_nonce'])), self::NONCE_ACTION) !== false;

        /**
         * Filter kontor_shop/checkout/intercept: das Absenden ist keine Bestellung,
         * sondern eine eigene Aktion im Kassenformular (Pro: Rabattcode einloesen
         * oder entfernen - auch ein eingetippter, noch nicht eingeloester Code
         * beim Klick auf "Bestellen": erst einloesen und die neue Summe zeigen).
         * true = erledigt, die Kasse wird mit den Eingaben erneut angezeigt;
         * Rueckmeldungen ueber kontor_shop/checkout/notices bzw. die eigene
         * Ausgabe (kontor_shop/checkout/summary_after_totals). Standard false.
         *
         * @param bool $handled   schon von einer anderen Erweiterung erledigt
         * @param Cart $cart      Warenkorb
         * @param bool $is_member Mitgliedspreise
         * @param bool $nonce_ok  Nonce gueltig - sonst nichts aendern (Sitzung abgelaufen)
         */
        if ((bool) apply_filters('kontor_shop/checkout/intercept', false, $cart, $is_member, $nonce_ok)) {
            nocache_headers();
            $this->hold_until = $this->reservations->expires_at_for_session(CheckoutSession::key());

            return;
        }

        try {
            $result = $this->handle_submit($cart, $is_member);
        } catch (\Throwable $e) {
            $reference = Logger::exception($e, 'checkout', 'Unerwarteter Fehler beim Bestellen', [], true);
            $result    = $this->technical_error_message($reference);
        }

        if (is_string($result)) {
            $this->submit_error = $result;

            return;
        }

        $token = $this->orders->find((int) $result[0])?->checkout_token;
        $url   = (string) get_permalink();

        if ($token !== null && $url !== '') {
            wp_safe_redirect(add_query_arg(['kontor_order' => $token, 'neu' => '1'], $url), 303);
            exit;
        }

        // Ohne Weiterleitung (sollte nicht vorkommen): Bestaetigung direkt zeigen.
        $this->confirmed_orders = $result;
    }

    private function reservation_ttl(): int
    {
        return Availability::ttl_seconds($this->settings->get('checkout.reservation_minutes', Availability::DEFAULT_MINUTES));
    }

    /**
     * Warenwert des Warenkorbs (brutto, nach Mitgliedspreis, vor Rabatt).
     */
    private function goods_total(Cart $cart, bool $is_member): float
    {
        $total = 0.0;

        foreach ($cart->items() as $item) {
            $sellable = $this->items->resolve($item->product_id, $item->variant_id);

            if ($sellable !== null) {
                $total += round($sellable->price_for($is_member) * $item->quantity, 2);
            }
        }

        return round($total, 2);
    }

    /**
     * Rabattanteil je Position (Pro: Rabattcode), auf den Cent gerundet und nie
     * mehr als der Positionswert. Ohne Erweiterung ueberall 0.
     *
     * @param array<int, float> $line_totals Positionswerte (brutto, nach Mitgliedspreis)
     * @param array{stage: string, cart: Cart, is_member: bool, goods_total: float, email: string} $context
     * @return array<int, float> gleiche Schluessel wie $line_totals
     */
    private function line_discounts(array $line_totals, array $context): array
    {
        /**
         * Filter kontor_shop/checkout/line_discounts: Rabatt anteilig auf die
         * Positionen verteilen (Versand bleibt unrabattiert). Daraus rechnet der
         * Kern Warenwert nach Rabatt, Rabatt je Teil-Bestellung, Versandkosten-
         * Basis und USt. Standard [] = kein Rabatt.
         *
         * @param array<int, float> $shares      Rabattanteil je Position (Schluessel wie $line_totals)
         * @param array<int, float> $line_totals Positionswerte (brutto, nach Mitgliedspreis)
         * @param array             $context     stage: 'summary' (Anzeige der Kasse) oder 'order'
         *                                       (Bestellen, nach kontor_shop/checkout/validate),
         *                                       cart, is_member, goods_total (Warenwert vor Rabatt),
         *                                       email (beim Bestellen, sonst '')
         */
        $raw    = (array) apply_filters('kontor_shop/checkout/line_discounts', [], $line_totals, $context);
        $shares = [];

        foreach ($line_totals as $key => $total) {
            $shares[$key] = round(min(max(0.0, (float) ($raw[$key] ?? 0.0)), max(0.0, (float) $total)), 2);
        }

        return $shares;
    }

    /**
     * Bezeichnung des Rabatts (Pro: der eingeloeste Code), null = keiner.
     */
    private function discount_label(): ?string
    {
        /**
         * Filter kontor_shop/checkout/discount_label: Bezeichnung des gewaehrten
         * Rabatts - Pro: der eingeloeste Rabattcode. Landet in orders.discount_code
         * und in der Rabattzeile der Zusammenfassung. Standard null.
         *
         * @param string|null $label
         */
        $label = apply_filters('kontor_shop/checkout/discount_label', null);

        return is_string($label) && $label !== '' ? mb_substr($label, 0, 40) : null;
    }

    /**
     * Beim Oeffnen der Kasse: jede Position fuer diesen Besucher reservieren
     * (Abschnitt 6.1, Schritt 2). Ist weniger frei als im Warenkorb liegt (andere
     * halten Ware zurueck), wird die Menge angepasst bzw. die Position entfernt -
     * mit Hinweis. Eine bestehende Reservierung mit gleicher Menge behaelt ihren
     * Ablauf, Neuladen verlaengert sie nicht.
     */
    private function hold_cart(): void
    {
        $cart = $this->cart_store->load();

        if ($cart->is_empty()) {
            return;
        }

        $session_key = CheckoutSession::key();
        $ttl         = $this->reservation_ttl();
        $changed     = false;

        try {
            foreach ($cart->items() as $item) {
                $sellable = $this->items->resolve($item->product_id, $item->variant_id);
                $name     = $sellable?->name() ?? (string) $item->product_id;
                $free     = $sellable !== null && $sellable->is_sellable() ? $this->reservations->free_for($item->product_id, $session_key, $item->variant_id) : 0;
                $fit      = Availability::clamp($item->quantity, $free);

                if ($fit['status'] === 'removed') {
                    $cart->remove($item->product_id, $item->variant_id);
                    $this->reservations->release_product($item->product_id, $session_key, $item->variant_id);
                    $this->hold_notices[] = sprintf(
                        /* translators: %s: product name */
                        __('Sorry, "%s" is no longer available and was removed from your cart.', 'kontor-shop'),
                        $name
                    );
                    $changed = true;

                    continue;
                }

                if ($fit['status'] === 'reduced') {
                    $cart->set_quantity($item->product_id, $fit['quantity'], $item->variant_id);
                    $this->hold_notices[] = sprintf(
                        /* translators: 1: product name, 2: available quantity */
                        __('Only %2$d of "%1$s" are still available - the quantity was adjusted.', 'kontor-shop'),
                        $name,
                        $fit['quantity']
                    );
                    $changed = true;
                }

                if (!$this->reservations->reserve($item->product_id, $fit['quantity'], $session_key, $ttl, true, $item->variant_id)) {
                    // Zwischenzeitlich vergeben (Wettlauf): wie "nicht mehr verfuegbar" behandeln.
                    $cart->remove($item->product_id, $item->variant_id);
                    $this->hold_notices[] = sprintf(
                        /* translators: %s: product name */
                        __('Sorry, "%s" is no longer available and was removed from your cart.', 'kontor-shop'),
                        $name
                    );
                    $changed = true;
                }
            }
        } catch (\Throwable $e) {
            // Ohne Reservierung geht es weiter - beim Absenden wird erneut geprueft.
            Logger::exception($e, 'checkout', 'Reservierung beim Oeffnen der Kasse fehlgeschlagen');
        }

        if ($changed) {
            $this->cart_store->save($cart);
        }

        $this->hold_until = $this->reservations->expires_at_for_session($session_key);
    }

    private function is_checkout_request(): bool
    {
        if (!is_singular()) {
            return false;
        }

        $post = get_post();

        return $post !== null && has_shortcode((string) $post->post_content, 'kontor_shop_checkout');
    }

    public function render_shortcode(): string
    {
        // Bestaetigungsseite: ausschliesslich ueber das geheime Token, nie ueber
        // eine Bestell-ID - sonst waeren Kundendaten ohne Zugriffsschutz abrufbar.
        $token = isset($_GET['kontor_order']) ? sanitize_text_field(wp_unslash((string) $_GET['kontor_order'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige-Parameter (geheimes Token)

        if ($token !== '') {
            $orders = $this->orders->find_by_token($token);

            return $orders !== [] && OrderLink::is_valid($orders, OrderStatusLink::days($this->settings), time())
                ? $this->render_confirmation($orders, isset($_GET['neu'])) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige-Parameter
                : '<p class="kontor-shop-checkout__error" role="alert">' . esc_html__(
                    'This link is invalid or has expired. You\'ll find the details of your order in our emails – if you have questions, just reply to them.',
                    'kontor-shop'
                ) . '</p>';
        }

        if ($this->confirmed_orders !== []) {
            return $this->render_confirmation(array_values(array_filter(array_map(
                fn (int $id): ?Order => $this->orders->find($id),
                $this->confirmed_orders
            ))));
        }

        $cart = $this->cart_store->load();

        if ($cart->is_empty()) {
            return '<p class="kontor-shop-checkout__empty">' . esc_html__('Your cart is empty.', 'kontor-shop') . '</p>';
        }

        $is_member = Membership::is_member();

        return $this->render_form($cart, $is_member, $this->submit_error);
    }

    /**
     * @return array<int, int>|string IDs der angelegten Bestellungen oder Fehlermeldung
     */
    private function handle_submit(Cart $cart, bool $is_member): array|string
    {
        // Abgelaufene Sitzung (z. B. Seite lange offen/gecacht): freundlich melden statt
        // der WordPress-Standardseite "Der Link ist abgelaufen". Eingaben bleiben erhalten.
        if (!wp_verify_nonce(isset($_POST['kontor_shop_checkout_nonce']) ? sanitize_text_field(wp_unslash($_POST['kontor_shop_checkout_nonce'])) : '', self::NONCE_ACTION)) {
            return __('Your session has expired. Your entries are still there - just submit the form again.', 'kontor-shop');
        }

        $spam_error = SpamGuard::check($_POST);

        if ($spam_error !== null) {
            return $spam_error;
        }

        $post      = wp_unslash($_POST);
        $allowed   = $this->payment_methods->available_methods($is_member);
        $validated = CheckoutValidator::validate($post, [
            'allow_pickup'    => (bool) $this->settings->get('shipping.allow_self_pickup', true),
            'countries'       => (array) $this->settings->get('checkout.countries', ['AT']),
            'phone'           => (string) $this->settings->get('checkout.phone', CheckoutValidator::PHONE_OPTIONAL),
            'billing_enabled' => (bool) $this->settings->get('checkout.billing_address', true),
        ]);

        $errors = $validated['errors'];
        $values = $validated['values'];

        $payment_method = sanitize_key((string) ($post['payment_method'] ?? ''));

        if (!PaymentMethod::is_known($payment_method) || !in_array($payment_method, $allowed, true)) {
            $errors['payment_method'] = __('Please select a payment method.', 'kontor-shop');
        } elseif ($payment_method === PaymentMethod::CASH && empty($values['self_pickup'])) {
            $errors['payment_method'] = __('Cash payment is only possible if you pick up the order yourself. Please choose another payment method or self pickup.', 'kontor-shop');
        }

        if ((bool) $this->settings->get('legal.require_terms', true) && empty($post['accept_terms'])) {
            $errors['accept_terms'] = __('Please accept the terms and conditions and the right of withdrawal to place your order.', 'kontor-shop');
        }

        if ((bool) $this->settings->get('legal.require_privacy', true) && empty($post['accept_privacy'])) {
            $errors['accept_privacy'] = __('Please confirm that you\'ve read the privacy policy to place your order.', 'kontor-shop');
        }

        if ($errors !== []) {
            $this->field_errors = $errors;

            return _n(
                'Please check the highlighted field - your order hasn\'t been submitted yet.',
                'Please check the highlighted fields - your order hasn\'t been submitted yet.',
                count($errors),
                'kontor-shop'
            );
        }

        $self_pickup = (bool) $values['self_pickup'];
        $session_key = CheckoutSession::key();

        if (has_filter('kontor_shop/checkout/validate')) {
            /**
             * Filter kontor_shop/checkout/validate: letzte Pruefung vor dem
             * Reservieren und Anlegen (Pro: Rabattcode erneut pruefen - Frist,
             * Einloesungen, einmal pro Kunde mit der eingegebenen E-Mail). Ein
             * Text bricht ab und wird dem Kunden gezeigt; die Eingaben bleiben.
             * Standard null = alles in Ordnung.
             *
             * @param string|null          $error       Fehlermeldung oder null
             * @param Cart                 $cart        Warenkorb
             * @param array<string, mixed> $values      gepruefte Kassenfelder (CheckoutValidator), u. a. email
             * @param float                $goods_total Warenwert vor Rabatt (brutto, nach Mitgliedspreis)
             * @param bool                 $is_member   Mitgliedspreise
             */
            $error = apply_filters('kontor_shop/checkout/validate', null, $cart, $values, $this->goods_total($cart, $is_member), $is_member);

            if (is_string($error) && $error !== '') {
                return $error;
            }
        }

        try {
            foreach ($cart->items() as $item) {
                if (!$this->reservations->reserve($item->product_id, $item->quantity, $session_key, $this->reservation_ttl(), false, $item->variant_id)) {
                    $this->reservations->release_for_session($session_key);

                    return sprintf(
                        /* translators: %s: product name */
                        __('There\'s not enough stock left of "%s". Please adjust the quantity in your cart - your entries will be kept.', 'kontor-shop'),
                        $this->items->resolve($item->product_id, $item->variant_id)?->name() ?? (string) $item->product_id
                    );
                }
            }

            $order_items = [];

            foreach ($cart->items() as $item) {
                $sellable = $this->items->resolve($item->product_id, $item->variant_id);

                if ($sellable === null || !$sellable->is_sellable()) {
                    $this->reservations->release_for_session($session_key);

                    return sprintf(
                        /* translators: %s: product name */
                        __('Sorry, the product "%s" is no longer available. Please remove it from your cart.', 'kontor-shop'),
                        $sellable?->name() ?? (string) $item->product_id
                    );
                }

                $product    = $sellable->product;
                $unit_price = $sellable->price_for($is_member);

                $order_items[] = [
                    'product_id'    => $product->id,
                    'variant_id'    => $sellable->variant_id(),
                    'warehouse_id'  => $product->warehouse_id,
                    'product_name'  => mb_substr($sellable->name(), 0, 190),
                    'variant_label' => mb_substr($sellable->variant_label(), 0, 190),
                    'quantity'      => $item->quantity,
                    'list_price'    => $sellable->list_price(),
                    'unit_price'    => $unit_price,
                    'line_total'    => round($unit_price * $item->quantity, 2),
                    'tax_rate'      => $product->tax_rate,
                    'discount'      => 0.0,
                ];
            }

            // Rabatt anteilig auf die Positionen verteilen (Versand bleibt unrabattiert; ohne Erweiterung 0).
            $line_totals = array_map(static fn (array $item): float => (float) $item['line_total'], $order_items);
            $shares      = $this->line_discounts($line_totals, [
                'stage'       => 'order',
                'cart'        => $cart,
                'is_member'   => $is_member,
                'goods_total' => round(array_sum($line_totals), 2),
                'email'       => (string) $values['email'],
            ]);

            foreach ($shares as $index => $share) {
                $order_items[$index]['discount'] = $share;
            }

            // Artikel aus Lagern mit verschiedenen Bankkonten werden auf je eine
            // Bestellung pro Konto aufgeteilt (eigene Nummer und Zahlungsreferenz),
            // damit die Zahlungen sauber getrennt eingehen. Ohne Kontentrennung
            // entsteht wie bisher genau eine Bestellung.
            $groups = [];

            foreach ($order_items as $item) {
                $groups[$this->account_id_for_warehouse((int) $item['warehouse_id'])][] = $item;
            }

            // Warenwert je Teil-Bestellung nach Rabatt - darauf beziehen sich auch Versandkosten und Gratisversand-Grenze.
            $group_totals    = [];
            $group_discounts = [];

            foreach ($groups as $account_id => $group_items) {
                $group_discounts[$account_id] = round(array_sum(array_column($group_items, 'discount')), 2);
                $group_totals[$account_id]    = round(array_sum(array_column($group_items, 'line_total')) - $group_discounts[$account_id], 2);
            }

            $shipping_costs = $this->shipping->calculate_for_groups($self_pickup, $is_member, $group_totals);
            $token          = wp_generate_password(32, false, false);
            $orders_created = $this->create_orders($groups, $group_totals, $shipping_costs, $values, $is_member, $payment_method, $token, $group_discounts);
        } catch (CheckoutAborted $e) {
            // Von einer Erweiterung abgebrochen (z. B. Rabattcode unter Sperre nicht mehr gueltig): kein technischer Fehler.
            $this->reservations->release_for_session($session_key);

            return $e->public_message !== '' ? $e->public_message : __('Your order hasn\'t been submitted yet. Please check the total and then submit your order again.', 'kontor-shop');
        } catch (\Throwable $e) {
            $this->reservations->release_for_session($session_key);

            $reference = Logger::exception($e, 'checkout', 'Bestellung konnte nicht angelegt werden', [
                'cart_lines' => count($cart->items()),
                'is_member'  => $is_member,
            ], true);

            return $this->technical_error_message($reference);
        }

        $this->reservations->release_for_session($session_key);
        $this->cart_store->clear();

        /**
         * Aktion kontor_shop/checkout/completed: die Bestellung(en) sind
         * gespeichert, der Warenkorb ist geleert (noch vor den Mails, Cookies
         * lassen sich setzen). Pro vergisst hier den eingeloesten Rabattcode.
         *
         * @param array<int, Order> $orders angelegte (Teil-)Bestellungen
         */
        do_action('kontor_shop/checkout/completed', $orders_created);

        SpamGuard::record_success();

        // Ein Fehler beim Mailversand darf die bereits gespeicherte Bestellung nicht entwerten.
        foreach ($orders_created as $created_order) {
            try {
                do_action(NotificationsModule::HOOK_ORDER_CREATED, $created_order);
            } catch (\Throwable $e) {
                Logger::exception($e, 'checkout', 'Benachrichtigung nach Bestellung fehlgeschlagen', ['order_id' => $created_order->id]);
            }
        }

        return array_map(static fn (Order $order): int => (int) $order->id, $orders_created);
    }

    /**
     * Legt alle Teil-Bestellungen samt Bestandsbuchungen in EINER Transaktion an:
     * entweder komplett oder gar nicht (kein Bestand ohne Bestellung, keine
     * halbe Bestellung).
     *
     * @param array<string, array<int, array<string, mixed>>> $groups
     * @param array<string, float> $group_totals
     * @param array<string, float> $shipping_costs
     * @param array<string, mixed> $values
     * @param array<string, float> $group_discounts Rabattanteil je Teil-Bestellung
     * @return array<int, Order>
     * @throws CheckoutAborted wenn eine Erweiterung unter Sperre abbricht (z. B. Rabattcode nicht mehr einloesbar)
     */
    private function create_orders(array $groups, array $group_totals, array $shipping_costs, array $values, bool $is_member, string $payment_method, string $token, array $group_discounts = []): array
    {
        global $wpdb;

        $orders         = [];
        $discount_label = array_sum($group_discounts) > 0 ? $this->discount_label() : null;

        $wpdb->query('START TRANSACTION');

        try {
            /**
             * Aktion kontor_shop/checkout/before_orders: innerhalb der Transaktion,
             * bevor etwas angelegt wird. Hier sperrt Pro den Rabattcode und prueft
             * ihn mit dem dann gueltigen Stand - ein Einmal-Code kann so nicht von
             * zwei gleichzeitigen Bestellungen eingeloest werden. Eine
             * Domain\Checkout\CheckoutAborted rollt alles zurueck und zeigt dem
             * Kunden ihren Text.
             *
             * @param array<string, array<int, array<string, mixed>>> $groups      Positionen je Teil-Bestellung (mit discount)
             * @param array<string, mixed>                            $values      gepruefte Kassenfelder, u. a. email
             * @param float                                           $goods_total Warenwert vor Rabatt
             */
            do_action('kontor_shop/checkout/before_orders', $groups, $values, round(array_sum($group_totals) + array_sum($group_discounts), 2));

            foreach ($groups as $account_id => $group_items) {
                $group_total   = $group_totals[$account_id];
                $shipping_cost = $shipping_costs[$account_id];

                $order = $this->orders->create([
                    'status'            => OrderStatus::OPEN,
                    'is_member'         => $is_member,
                    'customer_name'     => $values['customer_name'],
                    'customer_email'    => $values['email'],
                    'customer_phone'    => $values['phone'],
                    'delivery_address'  => $values['delivery_address'],
                    'billing_name'      => $values['billing_display_name'],
                    'billing_address'   => $values['billing_address'],
                    'billing_vat_id'    => $values['billing_vat_id'],
                    'self_pickup'       => $values['self_pickup'],
                    'shipping_cost'     => $shipping_cost,
                    'items_total'       => $group_total,
                    'total'             => round($group_total + $shipping_cost, 2),
                    'payment_method'    => $payment_method,
                    'bank_account_id'   => (string) $account_id,
                    'checkout_token'    => $token,
                    'discount_code'     => $discount_label,
                    'discount_total'    => $group_discounts[$account_id] ?? 0.0,
                    'shipping_tax'      => TaxCalculator::allocate_shipping(
                        $shipping_cost,
                        array_map(static fn (array $item): array => ['tax_rate' => $item['tax_rate'], 'line_total' => round((float) $item['line_total'] - (float) $item['discount'], 2)], $group_items),
                        (string) $this->settings->get('tax.shipping_mode', TaxCalculator::SHIPPING_PROPORTIONAL),
                        ($rate = $this->settings->get('tax.shipping_rate', '')) !== '' ? (float) $rate : null
                    ),
                    'legal_accepted_at' => current_time('mysql'),
                ], $group_items);

                foreach ($group_items as $item) {
                    $this->stock->record((int) $item['product_id'], MovementType::ONLINE_SALE, -1 * (int) $item['quantity'], $order->id, null, null, (int) $item['variant_id']);
                }

                /**
                 * Aktion kontor_shop/checkout/order_created_in_tx: eine (Teil-)Bestellung
                 * ist angelegt, die Transaktion laeuft noch (Fehler/CheckoutAborted rollen
                 * alles zurueck). Pro bucht hier die Einloesung des Rabattcodes.
                 *
                 * @param Order                             $order    angelegte Bestellung
                 * @param array<int, array<string, mixed>> $items    ihre Positionen (mit discount)
                 * @param string                            $token    Checkout-Token (gleich fuer alle Teil-Bestellungen)
                 * @param string                            $email    E-Mail-Adresse des Kunden
                 * @param float                             $discount Rabatt dieser Teil-Bestellung
                 */
                do_action('kontor_shop/checkout/order_created_in_tx', $order, $group_items, $token, (string) $values['email'], $group_discounts[$account_id] ?? 0.0);

                // Nichts zu bezahlen: gleich als bezahlt buchen - sonst wuerde eine 0-€-Vorkasse
                // erinnert, nie ans Lager gegeben und am Ende automatisch storniert.
                if ($order->nothing_to_pay()) {
                    $this->orders->update_status((int) $order->id, OrderStatus::PAID, null, __('Nothing to pay (€0.00) – automatically booked as paid when you order.', 'kontor-shop'), [
                        'paid_at' => current_time('mysql'),
                    ], __('Shop (automatic)', 'kontor-shop'), OrderStatus::OPEN);

                    $order = $this->orders->find((int) $order->id) ?? $order;
                }

                $orders[] = $order;
            }

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');

            throw $e;
        }

        return $orders;
    }

    /**
     * Meldung bei unerwarteten technischen Fehlern: sagt klar, dass NICHTS
     * bestellt wurde, was zu tun ist, und nennt die Referenznummer fuer den Support.
     */
    private function technical_error_message(string $reference): string
    {
        return sprintf(
            /* translators: %s: error reference number, e.g. KS-1A2B3C */
            __('Sorry, a technical error occurred while processing your order. Your order was NOT submitted and you haven\'t been charged. Please try again in a few minutes. If the problem persists, please contact the shop and mention this error code: %s', 'kontor-shop'),
            $reference
        );
    }

    /**
     * Konto-ID, ueber die Zahlungen fuer Artikel dieses Lagers laufen
     * (zugeordnetes Konto, sonst Standardkonto, ohne Konten leer).
     */
    private function account_id_for_warehouse(int $warehouse_id): string
    {
        $account = $this->bank_accounts->resolve($this->warehouses->find($warehouse_id)?->bank_account_id);

        return $account !== null ? $account->id : '';
    }

    private function render_form(Cart $cart, bool $is_member, ?string $error): string
    {
        wp_enqueue_script('kontor-shop-checkout', KONTOR_SHOP_URL . 'assets/frontend/checkout.js', [], KONTOR_SHOP_VERSION, true);

        /**
         * Filter kontor_shop/checkout/prefill: Vorbelegung der Kassenfelder
         * (nur beim ersten Aufruf, nie ueber Eingaben hinweg), z. B. Name und
         * Adresse eines angemeldeten Mitglieds (Pro, Mitgliedschaft) oder
         * Kundendaten, die ein KI-Assistent vorbereitet hat (Pro,
         * Checkout-Uebergabe). Standard: [] (leere Felder).
         *
         * @param array<string, string> $prefill Feldname => Wert
         * @param RequestContext        $ctx     Benutzer und Cookies der Anfrage
         */
        $this->prefill = array_map('strval', (array) apply_filters('kontor_shop/checkout/prefill', [], RequestContext::from_globals()));

        $items_total = 0.0;
        $rows        = [];
        $placeholder = absint($this->settings->get('design.placeholder_image_id', 0));

        foreach ($cart->items() as $item) {
            $sellable = $this->items->resolve($item->product_id, $item->variant_id);

            if ($sellable === null) {
                continue;
            }

            $unit_price   = $sellable->price_for($is_member);
            $line_total   = round($unit_price * $item->quantity, 2);
            $items_total += $line_total;

            $rows[] = ['product' => $sellable->product, 'item' => $sellable, 'quantity' => $item->quantity, 'unit_price' => $unit_price, 'line_total' => $line_total];
        }

        $items_total = round($items_total, 2);

        // Rabatt (Erweiterung, z. B. Pro-Rabattcode): anteilig auf die Zeilen verteilt (wie beim Bestellen).
        $shares   = $this->line_discounts(array_column($rows, 'line_total'), [
            'stage'       => 'summary',
            'cart'        => $cart,
            'is_member'   => $is_member,
            'goods_total' => $items_total,
            'email'       => '',
        ]);
        $discount = round(array_sum($shares), 2);

        if ($discount > 0) {
            foreach ($shares as $index => $share) {
                $rows[$index]['net_total'] = round($rows[$index]['line_total'] - $share, 2);
            }
        }

        $net_total    = round($items_total - $discount, 2);
        $group_totals = [];

        foreach ($rows as $row) {
            $key                = $this->account_id_for_warehouse($row['product']->warehouse_id);
            $group_totals[$key] = ($group_totals[$key] ?? 0.0) + ($row['net_total'] ?? $row['line_total']);
        }

        $shipping_flat = array_sum($this->shipping->calculate_for_groups(false, $is_member, $group_totals));

        $allow_pickup    = (bool) $this->settings->get('shipping.allow_self_pickup', true);
        $allowed_methods = $this->payment_methods->available_methods($is_member);
        $is_pickup       = $allow_pickup && !empty($_POST['self_pickup']); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nur Wiederanzeige der eigenen Eingaben; verarbeitet wird erst nach der Nonce-Pruefung in handle_submit()
        $shown_shipping  = $is_pickup ? 0.0 : $shipping_flat;

        $hold_html = '';

        /**
         * Filter kontor_shop/checkout/notices: Hinweise oben in der Kasse (z. B. Pro:
         * "Rabattcode entfernt, weil abgelaufen"). Standard: die Hinweise zur
         * Reservierung (Menge angepasst, Artikel entfernt). Reiner Text, wird escaped.
         *
         * @param array<int, string> $notices
         * @param Cart               $cart
         */
        $notices = array_filter(array_map('strval', (array) apply_filters('kontor_shop/checkout/notices', $this->hold_notices, $cart)), static fn (string $notice): bool => $notice !== '');

        foreach ($notices as $notice) {
            $hold_html .= '<p class="kontor-shop-checkout__notice" role="status">' . esc_html($notice) . '</p>';
        }

        if ($this->hold_until !== null) {
            $hold_html .= '<p class="kontor-shop-checkout__hold" role="status">' . esc_html(sprintf(
                /* translators: %s: time */
                __('Your products are reserved for you until %s. After that, they\'ll be released again.', 'kontor-shop'),
                wp_date('H:i', $this->hold_until)
            )) . '</p>';
        }

        $html = '<div class="kontor-shop-checkout" data-processing="' . esc_attr__('Your order is being processed - please don\'t close this window ...', 'kontor-shop') . '">';

        $html .= $hold_html;
        $html .= $this->render_error_summary($error);
        /**
         * Filter kontor_shop/checkout/notice: Zeile ueber dem Kassenformular,
         * z. B. "Angemeldet als Mitglied (Name) - Mitgliedsvorteile gelten"
         * (Pro, Mitgliedschaft). Fertiges, escaptes HTML. Standard: ''.
         *
         * @param string $html
         * @param bool   $is_member Kaeufer gilt als Mitglied (kontor_shop/membership/is_member)
         * @param string $return_to Ruecksprung nach einer Anmeldung (diese Seite)
         */
        $html .= (string) apply_filters('kontor_shop/checkout/notice', '', $is_member, (string) get_permalink());

        if (count($group_totals) > 1) {
            $html .= '<p class="kontor-shop-checkout__note kontor-shop-checkout__split" role="note">' . sprintf(
                /* translators: %d: number of partial orders */
                esc_html__('Your products come from different warehouses and will be split into %d separate orders, each with its own payment.', 'kontor-shop'),
                count($group_totals)
            ) . '</p>';
        }

        $html .= '<div class="kontor-shop-checkout__layout">';

        // ---------- Formular ----------
        $html .= '<form method="post" action="" class="kontor-shop-checkout__form" id="' . self::FORM_ID . '">';
        // $display = false: das Feld muss IN das Formular-HTML, nicht direkt ausgegeben
        // werden - sonst fehlt es beim Absenden und die Bestellung wird nie verarbeitet.
        $html .= wp_nonce_field(self::NONCE_ACTION, 'kontor_shop_checkout_nonce', true, false);
        $html .= SpamGuard::field_html();

        $html .= '<fieldset class="kontor-shop-fieldset"><legend>' . esc_html__('Your details', 'kontor-shop') . '</legend>';
        $html .= '<div class="kontor-shop-field-row">'
            . $this->input_field('first_name', __('First name', 'kontor-shop'), ['autocomplete' => 'given-name', 'required' => true, 'maxlength' => 80])
            . $this->input_field('last_name', __('Last name', 'kontor-shop'), ['autocomplete' => 'family-name', 'required' => true, 'maxlength' => 80])
            . '</div>';
        $html .= $this->input_field('customer_email', __('Email', 'kontor-shop'), [
            'type' => 'email', 'autocomplete' => 'email', 'inputmode' => 'email', 'required' => true, 'maxlength' => 190,
            'hint' => __('This is where we send the order confirmation.', 'kontor-shop'),
        ]);

        $phone_mode = (string) $this->settings->get('checkout.phone', CheckoutValidator::PHONE_OPTIONAL);

        if ($phone_mode !== CheckoutValidator::PHONE_OFF) {
            $html .= $this->input_field('customer_phone', __('Phone', 'kontor-shop') . ($phone_mode === CheckoutValidator::PHONE_REQUIRED ? '' : ' ' . __('(optional)', 'kontor-shop')), [
                'type' => 'tel', 'autocomplete' => 'tel', 'inputmode' => 'tel', 'required' => $phone_mode === CheckoutValidator::PHONE_REQUIRED, 'maxlength' => 40,
                'hint' => __('For questions about the delivery.', 'kontor-shop'),
            ]);
        }

        $html .= '</fieldset>';

        $html .= '<fieldset class="kontor-shop-fieldset"><legend>' . esc_html__('Delivery', 'kontor-shop') . '</legend>';

        if ($allow_pickup) {
            $html .= '<label class="kontor-shop-choice kontor-shop-choice--check"><input type="checkbox" name="self_pickup" value="1" '
                . checked($is_pickup, true, false) . ' /> <span>'
                . esc_html__('I\'ll pick it up myself (no shipping cost)', 'kontor-shop') . '</span></label>';
        }

        $countries = array_values(array_filter(
            array_map('strtoupper', (array) $this->settings->get('checkout.countries', ['AT'])),
            [Countries::class, 'is_known']
        )) ?: ['AT'];

        $html .= '<div class="kontor-shop-delivery"' . ($is_pickup ? ' hidden' : '') . '>';
        $html .= $this->input_field('company', __('Company', 'kontor-shop') . ' ' . __('(optional)', 'kontor-shop'), ['autocomplete' => 'organization', 'maxlength' => 100]);
        $html .= $this->input_field('street', __('Street and house number', 'kontor-shop'), ['autocomplete' => 'address-line1', 'required' => true, 'maxlength' => 120, 'when_delivery' => true]);
        $html .= $this->input_field('address2', __('Address line 2', 'kontor-shop') . ' ' . __('(optional, e.g. staircase/unit)', 'kontor-shop'), ['autocomplete' => 'address-line2', 'maxlength' => 120]);
        $html .= '<div class="kontor-shop-field-row kontor-shop-field-row--zip">'
            . $this->input_field('postal_code', __('Postal code', 'kontor-shop'), ['autocomplete' => 'postal-code', 'inputmode' => 'text', 'required' => true, 'maxlength' => 10, 'when_delivery' => true, 'postal' => true])
            . $this->input_field('city', __('City', 'kontor-shop'), ['autocomplete' => 'address-level2', 'required' => true, 'maxlength' => 80, 'when_delivery' => true])
            . '</div>';
        $html .= $this->country_field('country', __('Country', 'kontor-shop'), $countries, 'country-name');
        $html .= '</div></fieldset>';

        if ((bool) $this->settings->get('checkout.billing_address', true)) {
            $billing_open = !empty($_POST['billing_different']); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nur Wiederanzeige der eigenen Eingaben; verarbeitet wird erst nach der Nonce-Pruefung in handle_submit()

            $html .= '<fieldset class="kontor-shop-fieldset"><legend>' . esc_html__('Invoice', 'kontor-shop') . '</legend>';
            $html .= '<label class="kontor-shop-choice kontor-shop-choice--check"><input type="checkbox" name="billing_different" value="1" '
                . checked($billing_open, true, false) . ' /> <span>' . esc_html__('Billing address is different / invoice to a company', 'kontor-shop') . '</span></label>';
            $html .= '<div class="kontor-shop-billing"' . ($billing_open ? '' : ' hidden') . '>';
            $html .= $this->input_field('billing_name', __('Name', 'kontor-shop'), ['autocomplete' => 'billing name', 'maxlength' => 190]);
            $html .= $this->input_field('billing_company', __('Company', 'kontor-shop') . ' ' . __('(optional)', 'kontor-shop'), ['autocomplete' => 'billing organization', 'maxlength' => 100]);
            $html .= $this->input_field('billing_vat_id', __('VAT ID', 'kontor-shop') . ' ' . __('(optional, e.g. ATU12345678)', 'kontor-shop'), ['maxlength' => 20]);
            $html .= $this->input_field('billing_street', __('Street and house number', 'kontor-shop'), ['autocomplete' => 'billing address-line1', 'required' => true, 'maxlength' => 120, 'when_billing' => true]);
            $html .= $this->input_field('billing_address2', __('Address line 2', 'kontor-shop') . ' ' . __('(optional)', 'kontor-shop'), ['autocomplete' => 'billing address-line2', 'maxlength' => 120]);
            $html .= '<div class="kontor-shop-field-row kontor-shop-field-row--zip">'
                . $this->input_field('billing_postal_code', __('Postal code', 'kontor-shop'), ['autocomplete' => 'billing postal-code', 'required' => true, 'maxlength' => 10, 'when_billing' => true, 'postal' => true])
                . $this->input_field('billing_city', __('City', 'kontor-shop'), ['autocomplete' => 'billing address-level2', 'required' => true, 'maxlength' => 80, 'when_billing' => true])
                . '</div>';
            $html .= $this->country_field('billing_country', __('Country', 'kontor-shop'), array_keys(Countries::all()), 'billing country-name');
            $html .= '</div></fieldset>';
        }

        $invoice_days  = (new PaymentOptions($this->settings))->terms()->invoice_days;
        $method_labels = [
            PaymentMethod::PREPAYMENT => __('Prepayment (bank transfer)', 'kontor-shop'),
            PaymentMethod::INVOICE    => __('On invoice', 'kontor-shop'),
            PaymentMethod::CASH       => __('Cash on pickup', 'kontor-shop'),
        ];
        $method_hints  = [
            PaymentMethod::CASH       => __('We set your order aside, and you pay in cash when you pick it up.', 'kontor-shop'),
            PaymentMethod::PREPAYMENT => __('You transfer first, and we ship after we receive your payment.', 'kontor-shop'),
            PaymentMethod::INVOICE    => $invoice_days > 0
                ? sprintf(
                    /* translators: %d: days */
                    __('We ship right away, and you pay by bank transfer within %d days of shipping.', 'kontor-shop'),
                    $invoice_days
                )
                : __('We ship right away, and you pay by bank transfer after receiving the goods.', 'kontor-shop'),
        ];

        $html .= '<fieldset class="kontor-shop-fieldset' . (isset($this->field_errors['payment_method']) ? ' has-error' : '') . '" id="ks-field-payment_method"><legend>' . esc_html__('Payment method', 'kontor-shop') . '</legend>';

        // Bar bei Abholung nur sichtbar, solange "Ich hole selbst ab" angehakt ist (checkout.js schaltet um).
        $default_method = current(array_diff($allowed_methods, [PaymentMethod::CASH])) ?: PaymentMethod::PREPAYMENT;
        $chosen_method  = isset($_POST['payment_method']) ? sanitize_text_field(wp_unslash($_POST['payment_method'])) : $default_method; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nur Wiederanzeige der eigenen Eingaben; verarbeitet wird erst nach der Nonce-Pruefung in handle_submit()

        foreach ($allowed_methods as $method) {
            $pickup_only = $method === PaymentMethod::CASH;

            $html .= '<label class="kontor-shop-choice"' . ($pickup_only ? ' data-ks-pickup-only' . ($is_pickup ? '' : ' hidden') : '') . '><input type="radio" name="payment_method" value="'
                . esc_attr($method) . '" ' . checked($method, $chosen_method, false) . ' /> <span>'
                . esc_html($method_labels[$method] ?? $method)
                . (count($allowed_methods) > 1 && isset($method_hints[$method]) ? ' <small class="kontor-shop-choice__hint">' . esc_html($method_hints[$method]) . '</small>' : '')
                . '</span></label>';
        }

        $html .= $this->field_error_html('payment_method');
        $html .= '</fieldset>';

        $html .= $this->render_consents();

        $price_note   = trim((string) $this->settings->get('legal.price_note', ''));
        $tax_lines    = array_map(static fn (array $row): array => ['tax_rate' => $row['product']->tax_rate, 'line_total' => $row['net_total'] ?? $row['line_total']], $rows);
        $has_vat      = TaxCalculator::breakdown($tax_lines) !== [];
        $has_exempt   = count(array_filter($tax_lines, static fn (array $line): bool => $line['tax_rate'] === null || $line['tax_rate'] <= 0)) > 0;

        $html .= '<p class="kontor-shop-checkout__note">'
            . esc_html($has_vat ? __('All prices are final prices including the VAT shown, plus shipping.', 'kontor-shop')
                                : __('All prices plus shipping.', 'kontor-shop')) . '</p>';

        if ($has_exempt) {
            $exempt_note = TextDefaults::or_default((string) $this->settings->get('tax.exempt_note', ''), TextDefaults::exempt_note());

            if ($exempt_note !== '') {
                $html .= '<p class="kontor-shop-checkout__note">' . esc_html($exempt_note) . '</p>';
            }
        }

        $button_label = TextDefaults::or_default((string) $this->settings->get('legal.order_button_label', ''), TextDefaults::order_button_label());

        if ($price_note !== '') {
            $html .= '<p class="kontor-shop-checkout__note">' . esc_html($price_note) . '</p>';
        }

        $html .= '<button type="submit" class="kontor-shop-btn kontor-shop-checkout__submit">' . esc_html($button_label) . '</button>';

        $imprint = $this->legal_links()['impressum'];

        if ($imprint['url'] !== '') {
            $html .= '<p class="kontor-shop-checkout__legal-links"><a href="' . esc_url($imprint['url']) . '" target="_blank" rel="noopener">'
                . esc_html($imprint['label']) . '</a></p>';
        }

        $html .= '</form>';

        // ---------- Zusammenfassung ----------
        $html .= '<aside class="kontor-shop-checkout__summary" data-checkout-summary data-items="'
            . esc_attr(number_format($net_total, 2, '.', '')) . '" data-shipping="' . esc_attr(number_format($shipping_flat, 2, '.', '')) . '">';
        $html .= '<h2>' . esc_html__('Your order', 'kontor-shop') . '</h2><ul class="kontor-shop-checkout__items">';

        foreach ($rows as $row) {
            $base_price = $row['item']->unit_price_text($row['unit_price']);

            $html .= '<li><span class="kontor-shop-checkout__thumb">'
                . ProductImage::render($row['item']->image_id(), $row['product']->name, 'thumbnail', $placeholder) . '</span>';
            $html .= '<span class="kontor-shop-checkout__item-name">' . esc_html($row['product']->name)
                . ($row['item']->variant !== null ? ' <span class="kontor-shop-checkout__variant">' . esc_html($row['item']->variant_label()) . '</span>' : '')
                . ' <small>&times; ' . esc_html((string) $row['quantity']) . '</small>'
                . ($base_price !== '' ? ' <small class="kontor-shop-unit-price">' . esc_html($base_price) . '</small>' : '') . '</span>';
            $html .= '<span class="kontor-shop-checkout__item-total">' . esc_html(number_format_i18n($row['line_total'], 2)) . ' €</span></li>';
        }

        $html .= '</ul><dl class="kontor-shop-checkout__totals">';
        $html .= '<div><dt>' . esc_html__('Order value', 'kontor-shop') . '</dt><dd>' . esc_html(number_format_i18n($items_total, 2)) . ' €</dd></div>';

        if ($discount > 0) {
            $html .= $this->discount_row($this->discount_label(), $discount);
        }

        $html .= '<div><dt>' . esc_html__('Shipping', 'kontor-shop') . '</dt><dd data-shipping-value>' . esc_html(number_format_i18n($shown_shipping, 2)) . ' €</dd></div>';
        $html .= '<div class="kontor-shop-checkout__grand"><dt>' . esc_html__('Total', 'kontor-shop') . '</dt><dd data-total-value>'
            . esc_html(number_format_i18n($net_total + $shown_shipping, 2)) . ' €</dd></div>';
        $html .= $this->render_vat_lines($tax_lines, true);
        $html .= '</dl>';

        /**
         * Filter kontor_shop/checkout/summary_after_totals: HTML unter den Summen
         * der Zusammenfassung (Pro: Feld "Rabattcode"). Knoepfe darin gehoeren per
         * form-Attribut zum Bestellformular ($form_id); mit data-ks-no-overlay
         * sperrt checkout.js das Formular nicht ("wird bearbeitet"). Standard ''.
         *
         * @param string $html      fertiges, escaptes HTML
         * @param string $form_id   id des Bestellformulars
         * @param Cart   $cart      Warenkorb
         * @param float  $discount  gewaehrter Rabatt (0 = keiner)
         */
        $html .= (string) apply_filters('kontor_shop/checkout/summary_after_totals', '', self::FORM_ID, $cart, $discount);
        $html .= '</aside>';

        $html .= '</div></div>';

        return $html;
    }

    /**
     * Zeilen "enthaltene USt" je Steuersatz (nur Warenwert; Versand-USt folgt
     * mit der Rechnungsstellung).
     *
     * @param array<int, array{tax_rate: float|null, line_total: float}> $lines
     */
    private function render_vat_lines(array $lines, bool $goods_only = false): string
    {
        $html = '';

        foreach (TaxCalculator::breakdown($lines) as $group) {
            $html .= '<div class="kontor-shop-checkout__vat"><dt>' . esc_html(sprintf(
                /* translators: %s: tax rate in percent */
                $goods_only ? __('included VAT %s %% (goods)', 'kontor-shop') : __('included VAT %s %%', 'kontor-shop'),
                number_format_i18n($group['rate'], $group['rate'] == floor($group['rate']) ? 0 : 1)
            )) . '</dt><dd>' . esc_html(number_format_i18n($group['vat'], 2)) . ' €</dd></div>';
        }

        return $html;
    }

    private function discount_row(?string $code, float $discount): string
    {
        return '<div class="kontor-shop-checkout__discount"><dt>' . esc_html($code !== null && $code !== '' ? sprintf(
            /* translators: %s: discount code */
            __('Discount (%s)', 'kontor-shop'),
            $code
        ) : __('Discount', 'kontor-shop')) . '</dt><dd>&minus;' . esc_html(number_format_i18n($discount, 2)) . ' €</dd></div>';
    }

    // ------------------------------------------------------------------ Formular-Helfer

    private function old(string $name): string
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nur Wiederanzeige der eigenen Eingaben; verarbeitet wird erst nach der Nonce-Pruefung in handle_submit()
        if (isset($_POST[$name])) {
            return sanitize_text_field((string) wp_unslash($_POST[$name]));
        }

        // Noch nicht abgesendet: Daten der angemeldeten Person vorbelegen (aenderbar).
        return empty($_POST) ? ($this->prefill[$name] ?? '') : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }

    private function field_error_html(string $name): string
    {
        return isset($this->field_errors[$name])
            ? '<p class="kontor-shop-field__error" id="ks-err-' . esc_attr($name) . '">' . esc_html($this->field_errors[$name]) . '</p>'
            : '';
    }

    /**
     * Eingabefeld mit Label, Hinweis und Fehlermeldung direkt am Feld
     * (aria-invalid/aria-describedby, sichtbarer roter Rahmen).
     *
     * @param array<string, mixed> $o type, autocomplete, inputmode, required, maxlength, hint, when_delivery/when_billing (Pflicht nur wenn Block sichtbar), postal
     */
    private function input_field(string $name, string $label, array $o = []): string
    {
        $id        = 'ks-field-' . $name;
        $has_error = isset($this->field_errors[$name]);
        $describe  = trim(($has_error ? 'ks-err-' . $name : '') . ' ' . (!empty($o['hint']) ? 'ks-hint-' . $name : ''));
        $required  = !empty($o['required']);
        $toggle    = !empty($o['when_delivery']) || !empty($o['when_billing']);
        $is_pickup = !empty($_POST['self_pickup']); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nur Wiederanzeige der eigenen Eingaben; verarbeitet wird erst nach der Nonce-Pruefung in handle_submit()
        $billing   = !empty($_POST['billing_different']); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nur Wiederanzeige der eigenen Eingaben; verarbeitet wird erst nach der Nonce-Pruefung in handle_submit()

        // Felder in ein-/ausblendbaren Bloecken: Pflicht (required) nur bei sichtbarem Block; checkout.js schaltet um.
        $html_required = $required && (!$toggle || (!empty($o['when_delivery']) && !$is_pickup) || (!empty($o['when_billing']) && $billing));

        $html = '<div class="kontor-shop-field' . ($has_error ? ' has-error' : '') . '">';
        $html .= '<label for="' . esc_attr($id) . '">' . esc_html($label) . ($required ? ' <span class="kontor-shop-req" aria-hidden="true">*</span>' : '') . '</label>';
        $html .= '<input type="' . esc_attr((string) ($o['type'] ?? 'text')) . '" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr($this->old($name)) . '"'
            . (!empty($o['autocomplete']) ? ' autocomplete="' . esc_attr((string) $o['autocomplete']) . '"' : '')
            . (!empty($o['inputmode']) ? ' inputmode="' . esc_attr((string) $o['inputmode']) . '"' : '')
            . (!empty($o['maxlength']) ? ' maxlength="' . (int) $o['maxlength'] . '"' : '')
            . ($required ? ' data-required="1"' : '')
            . ($html_required ? ' required' : '')
            . (!empty($o['postal']) ? ' data-postal="1"' : '')
            . ($has_error ? ' aria-invalid="true"' : '')
            . ($describe !== '' ? ' aria-describedby="' . esc_attr($describe) . '"' : '') . ' />';

        if (!empty($o['hint'])) {
            $html .= '<p class="kontor-shop-field__hint" id="ks-hint-' . esc_attr($name) . '">' . esc_html((string) $o['hint']) . '</p>';
        }

        return $html . $this->field_error_html($name) . '</div>';
    }

    /**
     * @param array<int, string> $codes
     */
    private function country_field(string $name, string $label, array $codes, string $autocomplete): string
    {
        $id        = 'ks-field-' . $name;
        $has_error = isset($this->field_errors[$name]);
        $selected  = strtoupper($this->old($name)) ?: ($codes[0] ?? 'AT');

        $html = '<div class="kontor-shop-field' . ($has_error ? ' has-error' : '') . '"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label>';
        $html .= '<select id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" autocomplete="' . esc_attr($autocomplete) . '" data-country'
            . ($has_error ? ' aria-invalid="true" aria-describedby="ks-err-' . esc_attr($name) . '"' : '') . '>';

        foreach ($codes as $code) {
            $html .= '<option value="' . esc_attr($code) . '" data-pattern="' . esc_attr(Countries::postal_pattern($code)) . '" data-example="' . esc_attr(Countries::postal_example($code)) . '" '
                . selected($selected, $code, false) . '>' . esc_html(Countries::name($code)) . '</option>';
        }

        return $html . '</select>' . $this->field_error_html($name) . '</div>';
    }

    /**
     * Fehlerkasten oben: allgemeine Meldung plus Liste der fehlerhaften Felder
     * mit Sprunglinks. Bekommt beim Laden den Fokus (checkout.js).
     */
    private function render_error_summary(?string $error): string
    {
        if ($error === null && $this->field_errors === []) {
            return '';
        }

        $html = '<div class="kontor-shop-checkout__error" role="alert" tabindex="-1" id="kontor-shop-error-summary">';

        if ($error !== null) {
            $html .= '<p>' . esc_html($error) . '</p>';
        }

        if ($this->field_errors !== []) {
            $html .= '<ul>';

            foreach ($this->field_errors as $field => $message) {
                $html .= '<li><a href="#ks-field-' . esc_attr($field) . '">' . esc_html($message) . '</a></li>';
            }

            $html .= '</ul>';
        }

        return $html . '</div>';
    }

    private function render_consents(): string
    {
        $links = $this->legal_links();
        $html  = '';

        if ((bool) $this->settings->get('legal.require_terms', true)) {
            $html .= '<label class="kontor-shop-choice kontor-shop-choice--check"><input type="checkbox" id="ks-field-accept_terms" name="accept_terms" value="1" required '
                . checked(!empty($_POST['accept_terms']), true, false) . ' /> <span>' // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nur Wiederanzeige der eigenen Eingaben; verarbeitet wird erst nach der Nonce-Pruefung in handle_submit()
                . LegalText::render(TextDefaults::or_default((string) $this->settings->get('legal.terms_label', ''), TextDefaults::terms_label()), $links) . '</span></label>' . $this->field_error_html('accept_terms');
        }

        if ((bool) $this->settings->get('legal.require_privacy', true)) {
            $html .= '<label class="kontor-shop-choice kontor-shop-choice--check"><input type="checkbox" id="ks-field-accept_privacy" name="accept_privacy" value="1" required '
                . checked(!empty($_POST['accept_privacy']), true, false) . ' /> <span>' // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nur Wiederanzeige der eigenen Eingaben; verarbeitet wird erst nach der Nonce-Pruefung in handle_submit()
                . LegalText::render(TextDefaults::or_default((string) $this->settings->get('legal.privacy_label', ''), TextDefaults::privacy_label()), $links) . '</span></label>' . $this->field_error_html('accept_privacy');
        }

        return $html;
    }

    /**
     * @return array<string, array{label: string, url: string}>
     */
    private function legal_links(): array
    {
        $definitions = [
            'agb'         => ['terms_page_id', __('Terms and conditions', 'kontor-shop')],
            'widerruf'    => ['withdrawal_page_id', __('Right of withdrawal', 'kontor-shop')],
            'datenschutz' => ['privacy_page_id', __('Privacy policy', 'kontor-shop')],
            'impressum'   => ['imprint_page_id', __('Legal notice', 'kontor-shop')],
        ];

        $links = [];

        foreach ($definitions as $key => [$setting, $label]) {
            $page_id = absint($this->settings->get('legal.' . $setting, 0));

            // Mehrsprachig: die in der Hauptsprache gewaehlte Seite wird durch ihre Uebersetzung ersetzt,
            // sofern es eine veroeffentlichte gibt - sonst bleibt die Originalseite.
            $translated = Translation::post_id($page_id);
            $page_id    = $translated !== $page_id && get_post_status($translated) === 'publish' ? $translated : $page_id;
            $url        = $page_id > 0 && get_post_status($page_id) === 'publish' ? (string) get_permalink($page_id) : '';

            $links[$key] = ['label' => $label, 'url' => $url];
        }

        return $links;
    }

    /**
     * Bestellstatus-Seite (oeffentlich ueber das geheime Token aus den
     * Kunden-Mails): direkt nach dem Bestellen mit "Vielen Dank"-Kopf, spaeter
     * als Uebersicht mit aktuellem Stand - Status, Zahlung, Sendungen mit
     * Tracking, stornierte Artikel, Rueckzahlung. Gueltig, solange die
     * Bestellung laeuft, und danach X Tage (Domain\Order\OrderLink).
     *
     * @param array<int, Order> $orders
     */
    private function render_confirmation(array $orders, bool $fresh = true): string
    {
        if ($orders === []) {
            return '<p>' . esc_html__('Order not found.', 'kontor-shop') . '</p>';
        }

        wp_enqueue_script('kontor-shop-checkout', KONTOR_SHOP_URL . 'assets/frontend/checkout.js', [], KONTOR_SHOP_VERSION, true);

        $first     = $orders[0];
        $multiple  = count($orders) > 1;
        $days      = PaymentDeadline::sanitize_days($this->settings->get('payment.payment_days', PaymentDeadline::DEFAULT_DAYS));
        $prepay    = $first->payment_method === PaymentMethod::PREPAYMENT;
        $running   = array_filter($orders, static fn (Order $order): bool => OrderLink::completed_at($order) === null) !== [];

        $html = '<div class="kontor-shop-order-confirmation">';

        if ($fresh) {
            $html .= '<div class="kontor-shop-order-confirmation__hero">'
                . '<span class="kontor-shop-order-confirmation__check" aria-hidden="true">&#10003;</span>'
                . '<h2>' . esc_html__('Thank you – we\'ve received your order!', 'kontor-shop') . '</h2>'
                . '<p class="kontor-shop-order-confirmation__mail">' . ((bool) $this->settings->get('notifications.templates.' . DefaultTemplates::ORDER_CONFIRMATION . '.enabled', true)
                    ? sprintf(
                        /* translators: %s: email address */
                        esc_html__('We\'ve sent a confirmation with all the details to %s. Didn\'t arrive? Please check your spam folder, too.', 'kontor-shop'),
                        '<strong>' . esc_html($first->customer_email) . '</strong>'
                    )
                    : esc_html__('Please save or print this page – it has all the details about your order and payment.', 'kontor-shop')
                ) . '</p></div>';
        } else {
            $html .= '<div class="kontor-shop-order-confirmation__hero is-status">'
                . '<h2>' . esc_html(sprintf(
                    /* translators: %s: order number(s) */
                    __('Your order %s', 'kontor-shop'),
                    implode(', ', array_map(static fn (Order $order): string => $order->order_number, $orders))
                )) . '</h2>'
                . '<p class="kontor-shop-order-confirmation__mail">' . esc_html(sprintf(
                    /* translators: %s: date/time */
                    __('Status as of %s', 'kontor-shop'),
                    wp_date('d.m.Y, H:i')
                )) . '</p></div>';
        }

        if ($multiple) {
            $html .= '<p class="kontor-shop-checkout__note">' . esc_html__(
                'Your products were split into several orders (different warehouses). Please pay each order separately with its own payment reference.',
                'kontor-shop'
            ) . '</p>';
        }

        foreach ($orders as $order) {
            $html .= $this->render_order_block($order, $multiple, $days);
        }

        $html .= $this->render_confirmation_details($first);

        if ($running && $first->status === OrderStatus::OPEN) {
            $html .= $this->render_next_steps($first, $prepay, $days);
        }

        $shop_page = absint($this->settings->get('general.shop_page_id', 0));
        $shop_url  = $shop_page > 0 ? get_permalink($shop_page) : false;

        $html .= '<div class="kontor-shop-order-confirmation__actions">'
            . '<button type="button" class="kontor-shop-btn kontor-shop-btn--ghost" data-ks-print>' . esc_html__('Print / save as PDF', 'kontor-shop') . '</button>'
            . ($shop_url ? '<a class="kontor-shop-btn" href="' . esc_url($shop_url) . '">' . esc_html__('Continue shopping', 'kontor-shop') . '</a>' : '')
            . '</div>';

        $html .= '<p class="kontor-shop-order-confirmation__foot">' . esc_html(sprintf(
            /* translators: %d: number of days */
            __('You can reach this page through the link in our emails – while your order is in progress and for %d days after.', 'kontor-shop'),
            OrderStatusLink::days($this->settings)
        )) . '</p>';

        /**
         * Filter kontor_shop/checkout/confirmation_footer: fertiges HTML
         * (escapen!) ganz unten auf der Bestellbestaetigung bzw.
         * Bestellstatus-Seite, z. B. Free nach Zustimmung: "Vereinsshop mit
         * Kontor Shop" (Notifications\PoweredBy). Standard: ''.
         *
         * @param string $html
         * @param Order  $order erste Bestellung der Seite
         */
        $credit = (string) apply_filters('kontor_shop/checkout/confirmation_footer', '', $first);

        if ($credit !== '') {
            $html .= '<p class="kontor-shop-order-confirmation__credit">' . $credit . '</p>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Fortschritt einer Bestellung als Schritte (Bestellt - Bezahlt - Versendet/Abgeholt).
     */
    private function render_status_steps(Order $order, Fulfilment $fulfilment): string
    {
        if ($order->status === OrderStatus::CANCELLED) {
            return '<p class="kontor-shop-status-banner is-cancelled" role="status">' . esc_html(sprintf(
                /* translators: %s: date */
                __('This order was cancelled on %s.', 'kontor-shop'),
                mysql2date('d.m.Y', (string) ($order->cancelled_at ?? $order->created_at))
            )) . '</p>';
        }

        $paid     = ItemCancellation::is_paid($order);
        $done     = in_array($order->status, [OrderStatus::SHIPPED, OrderStatus::PICKED_UP], true);
        $partial  = $fulfilment->is_partial();
        $invoice  = $order->payment_method !== PaymentMethod::PREPAYMENT;
        $out_text = $order->self_pickup ? __('Picked up', 'kontor-shop') : __('Shipped', 'kontor-shop');

        $steps = [
            [__('Ordered', 'kontor-shop'), true, $order->created_at],
        ];

        if (!$invoice) {
            $steps[] = [__('Paid', 'kontor-shop'), $paid, $order->paid_at];
        }

        $steps[] = [$partial ? ($order->self_pickup ? __('Partly picked up', 'kontor-shop') : __('Partly shipped', 'kontor-shop')) : $out_text, $done, $order->shipped_at, $partial];

        if ($invoice) {
            $steps[] = [__('Paid', 'kontor-shop'), $paid, $order->paid_at];
        }

        $html = '<ol class="kontor-shop-status">';

        foreach ($steps as $step) {
            $state = $step[1] ? 'is-done' : (!empty($step[3]) ? 'is-partial' : '');

            $html .= '<li class="' . $state . '"><span class="kontor-shop-status__dot" aria-hidden="true">' . ($step[1] ? '&#10003;' : '') . '</span>'
                . '<span class="kontor-shop-status__label">' . esc_html($step[0]) . '</span>'
                . ($step[1] && !empty($step[2]) ? '<small>' . esc_html(mysql2date('d.m.Y', (string) $step[2])) . '</small>' : '')
                . '<span class="kontor-shop-sr">' . esc_html($step[1] ? __('done', 'kontor-shop') : __('open', 'kontor-shop')) . '</span></li>';
        }

        return $html . '</ol>';
    }

    private function render_order_block(Order $order, bool $boxed, int $days): string
    {
        $items      = $this->orders->items_for_order((int) $order->id);
        $shipments  = $this->orders->shipments_for_order((int) $order->id);
        $fulfilment = new Fulfilment($order, $items);
        $by_id      = [];

        foreach ($shipments as $shipment) {
            $by_id[(int) $shipment->id] = $shipment;
        }

        $html = '<section class="kontor-shop-order-confirmation__order' . ($boxed ? ' is-boxed' : '') . '">';
        $html .= '<dl class="kontor-shop-order-confirmation__meta">'
            . '<div><dt>' . esc_html__('Order number', 'kontor-shop') . '</dt><dd><strong>' . esc_html($order->order_number) . '</strong></dd></div>'
            . ($order->created_at !== null
                ? '<div><dt>' . esc_html__('Ordered on', 'kontor-shop') . '</dt><dd>' . esc_html(mysql2date('d.m.Y, H:i', $order->created_at)) . '</dd></div>'
                : '')
            . '</dl>';

        $html .= $this->render_status_steps($order, $fulfilment);

        // Zahlung: Bankdaten nur, solange noch zu zahlen ist.
        if ($order->status === OrderStatus::OPEN && $order->payment_method === PaymentMethod::PREPAYMENT && $order->total > 0) {
            $account = $this->bank_accounts->resolve($order->bank_account_id);
            $due     = PaymentDeadline::due_date($order->created_at, $days);

            $html .= '<div class="kontor-shop-order-confirmation__payment">';
            $html .= '<h3>' . esc_html__('Pay now: by bank transfer (prepayment)', 'kontor-shop') . '</h3>';

            if ($due !== null) {
                $html .= '<p class="kontor-shop-order-confirmation__due">' . sprintf(
                    PaymentOptions::auto_cancel_active()
                        /* translators: %s: date */
                        ? esc_html__('Please transfer the amount by %s at the latest. The goods are reserved for you until then – after that, the order will be cancelled automatically.', 'kontor-shop')
                        /* translators: %s: date */
                        : esc_html__('Please transfer the amount by %s at the latest. The goods are reserved for you until then.', 'kontor-shop'),
                    '<strong>' . esc_html(mysql2date('d.m.Y', $due)) . '</strong>'
                ) . '</p>';
            }

            $html .= '<dl class="kontor-shop-order-confirmation__bank">';
            $html .= $this->copy_row(__('Amount', 'kontor-shop'), number_format_i18n($order->total, 2) . ' €', number_format($order->total, 2, ',', ''));
            $html .= $this->copy_row(__('Account holder', 'kontor-shop'), (string) ($account->recipient ?? ''));
            $html .= $this->copy_row('IBAN', (string) ($account->iban ?? ''));
            $html .= $this->copy_row('BIC', (string) ($account->bic ?? ''));
            $html .= $this->copy_row(__('Payment reference', 'kontor-shop'), $order->payment_reference);
            $html .= '</dl>';
            $html .= '<p class="kontor-shop-order-confirmation__hint">' . esc_html__(
                'Please be sure to include the payment reference with your transfer – it\'s the only way we can match your payment.',
                'kontor-shop'
            ) . '</p></div>';
        } elseif ($order->is_invoice() && $order->awaits_payment() && $order->total > 0) {
            $html .= $this->render_invoice_payment($order);
        } elseif ($order->is_cash_on_pickup() && $order->awaits_payment() && $order->total > 0) {
            $html .= '<div class="kontor-shop-order-confirmation__payment"><h3>' . esc_html__('Pay on pickup: in cash', 'kontor-shop') . '</h3><p>' . esc_html(sprintf(
                /* translators: %s: amount */
                __('Please bring %s € in cash when you pick up your order. We\'ll let you know by email as soon as it\'s ready.', 'kontor-shop'),
                number_format_i18n($order->total, 2)
            )) . '</p></div>';
        } elseif ($order->nothing_to_pay() && $order->status !== OrderStatus::CANCELLED) {
            $html .= '<div class="kontor-shop-order-confirmation__payment is-free"><h3>' . esc_html__('Nothing to pay', 'kontor-shop') . '</h3><p>' . esc_html($order->self_pickup
                ? __('Your discount code covers the full amount. We\'ll set your order aside for pickup.', 'kontor-shop')
                : __('Your discount code covers the full amount. We\'ll ship your order as soon as it\'s packed.', 'kontor-shop')) . '</p></div>';
        }

        if ($order->refund_total > 0) {
            $pending = $order->refund_pending();

            $html .= '<div class="kontor-shop-order-confirmation__payment is-refund"><h3>' . esc_html__('Refund', 'kontor-shop') . '</h3><p>' . esc_html($pending > 0
                ? sprintf(
                    /* translators: %s: amount */
                    __('We\'re transferring %s € back to you – this is being processed.', 'kontor-shop'),
                    number_format_i18n($pending, 2)
                )
                : sprintf(
                    /* translators: %s: amount */
                    __('%s € has been transferred back to you.', 'kontor-shop'),
                    number_format_i18n($order->refund_total, 2)
                )) . '</p></div>';
        }

        // Sendungen mit Tracking
        if ($shipments !== [] && !$order->self_pickup) {
            $html .= '<h3>' . esc_html__('Shipments', 'kontor-shop') . '</h3><ul class="kontor-shop-order-confirmation__shipments">';

            foreach ($shipments as $shipment) {
                $url   = $shipment->tracking_url();
                $label = trim(Carrier::label($shipment->carrier) . ($shipment->tracking_number !== null ? ' · ' . $shipment->tracking_number : ''));

                $html .= '<li><span>' . esc_html(sprintf(
                    /* translators: %s: date */
                    __('Shipped on %s', 'kontor-shop'),
                    mysql2date('d.m.Y', (string) $shipment->created_at)
                ))
                    . ($label !== '' ? ' – ' . esc_html($label) : '') . '</span>'
                    . ($url !== '' ? ' <a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html__('Track shipment', 'kontor-shop') . '</a>' : '') . '</li>';
            }

            $html .= '</ul>';
        }

        $html .= '<h3>' . esc_html__('Your products', 'kontor-shop') . '</h3>';
        $html .= '<ul class="kontor-shop-order-confirmation__items">';

        foreach ($items as $item) {
            $state = '';

            if ($item->is_cancelled()) {
                $state = __('cancelled', 'kontor-shop');
            } elseif ($fulfilment->is_shipped($item) && $order->status !== OrderStatus::CANCELLED) {
                $state = $order->self_pickup ? __('picked up', 'kontor-shop') : __('shipped', 'kontor-shop');

                if ($item->returned_qty > 0) {
                    $state = $item->is_fully_returned()
                        ? __('returned', 'kontor-shop')
                        /* translators: %d: pieces */
                        : $state . ', ' . sprintf(__('%d pcs returned', 'kontor-shop'), $item->returned_qty);
                }
            }

            $html .= '<li' . ($item->is_cancelled() ? ' class="is-cancelled"' : '') . '><span>' . esc_html($item->product_name)
                . ' <small>' . esc_html((string) $item->quantity) . ' &times; ' . esc_html(number_format_i18n($item->unit_price, 2)) . ' €'
                . ($state !== '' ? ' · <em>' . esc_html($state) . '</em>' : '') . '</small></span>'
                . '<span>' . esc_html(number_format_i18n($item->line_total, 2)) . ' €</span></li>';
        }

        $active = array_filter($items, static fn (OrderItem $item): bool => !$item->is_cancelled());

        $html .= '</ul><dl class="kontor-shop-checkout__totals">';
        $html .= '<div><dt>' . esc_html__('Order value', 'kontor-shop') . '</dt><dd>' . esc_html(number_format_i18n($order->goods_total(), 2)) . ' €</dd></div>';

        if ($order->has_discount()) {
            $html .= $this->discount_row((string) $order->discount_code, $order->discount_total);
        }

        $html .= '<div><dt>' . esc_html($order->self_pickup ? __('Shipping (self pickup)', 'kontor-shop') : __('Shipping', 'kontor-shop')) . '</dt><dd>'
            . esc_html(number_format_i18n($order->shipping_cost, 2)) . ' €</dd></div>';
        $html .= '<div class="kontor-shop-checkout__grand"><dt>' . esc_html__('Total', 'kontor-shop') . '</dt><dd>'
            . esc_html(number_format_i18n($order->total, 2)) . ' €</dd></div>'
            . $this->render_vat_lines(array_merge(
                array_map(static fn ($item): array => ['tax_rate' => $item->tax_rate, 'line_total' => $item->effective_total()], array_values($active)),
                $order->shipping_tax
            ))
            . '</dl>';

        return $html . '</section>';
    }

    /**
     * Auf Rechnung: vor dem Versand nur das Zahlungsziel, sobald alles raus
     * ist Faelligkeit und Bankdaten zum Kopieren.
     */
    private function render_invoice_payment(Order $order): string
    {
        $terms   = (new PaymentOptions($this->settings))->terms();
        $due     = $terms->due_date($order);
        $account = $this->bank_accounts->resolve($order->bank_account_id);

        $html = '<div class="kontor-shop-order-confirmation__payment">';
        $html .= '<h3>' . esc_html__('Payment: on invoice', 'kontor-shop') . '</h3>';

        if (!$order->is_dispatched()) {
            $html .= '<p>' . esc_html($terms->invoice_days > 0
                ? sprintf(
                    /* translators: %d: days */
                    __('You get the goods first – there\'s nothing to do right now. You pay by bank transfer within %d days of shipping; the payment details are also in the shipping email.', 'kontor-shop'),
                    $terms->invoice_days
                )
                : __('You get the goods first – there\'s nothing to do right now. You pay by bank transfer after receiving them; the payment details are also in the shipping email.', 'kontor-shop')) . '</p>';
        } elseif ($due !== null) {
            $html .= '<p class="kontor-shop-order-confirmation__due">' . sprintf(
                /* translators: %s: date */
                esc_html__('Please transfer the amount by %s at the latest.', 'kontor-shop'),
                '<strong>' . esc_html(mysql2date('d.m.Y', $due)) . '</strong>'
            ) . '</p>';
        }

        $html .= '<dl class="kontor-shop-order-confirmation__bank">';
        $html .= $this->copy_row(__('Amount', 'kontor-shop'), number_format_i18n($order->total, 2) . ' €', number_format($order->total, 2, ',', ''));
        $html .= $this->copy_row(__('Account holder', 'kontor-shop'), (string) ($account->recipient ?? ''));
        $html .= $this->copy_row('IBAN', (string) ($account->iban ?? ''));
        $html .= $this->copy_row('BIC', (string) ($account->bic ?? ''));
        $html .= $this->copy_row(__('Payment reference', 'kontor-shop'), $order->payment_reference);
        $html .= '</dl>';

        return $html . '<p class="kontor-shop-order-confirmation__hint">' . esc_html__(
            'Please be sure to include the payment reference with your transfer – it\'s the only way we can match your payment.',
            'kontor-shop'
        ) . '</p></div>';
    }

    /**
     * Zeile mit Kopier-Button (checkout.js); ohne JavaScript bleibt der Text markierbar.
     */
    private function copy_row(string $label, string $display, ?string $copy = null): string
    {
        if ($display === '') {
            return '';
        }

        return '<div><dt>' . esc_html($label) . '</dt><dd><span>' . esc_html($display) . '</span>'
            . ' <button type="button" class="kontor-shop-copy" data-ks-copy="' . esc_attr($copy ?? $display) . '" data-ks-copied="' . esc_attr__('Copied', 'kontor-shop') . '">'
            . esc_html__('Copy', 'kontor-shop') . '</button></dd></div>';
    }

    private function render_confirmation_details(Order $order): string
    {
        $contact = esc_html($order->customer_name) . '<br />' . esc_html($order->customer_email)
            . ($order->customer_phone !== null ? '<br />' . esc_html($order->customer_phone) : '');

        $delivery = $order->self_pickup
            ? esc_html__('Self pickup', 'kontor-shop')
            : nl2br(esc_html((string) $order->delivery_address));

        $html = '<div class="kontor-shop-order-confirmation__details">';
        $html .= '<section><h3>' . esc_html__('Your details', 'kontor-shop') . '</h3><p>' . $contact . '</p></section>';
        $html .= '<section><h3>' . esc_html($order->self_pickup ? __('Delivery', 'kontor-shop') : __('Shipping address', 'kontor-shop')) . '</h3><p>' . $delivery . '</p></section>';

        if ($order->billing_address !== null || $order->billing_name !== null) {
            $html .= '<section><h3>' . esc_html__('Billing address', 'kontor-shop') . '</h3><p>'
                . ($order->billing_name !== null ? esc_html($order->billing_name) . '<br />' : '')
                . nl2br(esc_html((string) $order->billing_address))
                . ($order->billing_vat_id !== null ? '<br />' . esc_html__('VAT ID:', 'kontor-shop') . ' ' . esc_html($order->billing_vat_id) : '')
                . '</p></section>';
        }

        return $html . '</div>';
    }

    private function render_next_steps(Order $order, bool $prepay, int $days): string
    {
        $steps = [];

        if ($prepay) {
            $due     = PaymentDeadline::due_date($order->created_at, $days);
            $steps[] = $due !== null
                ? sprintf(
                    /* translators: %s: date */
                    __('Transfer the amount with the payment reference by %s.', 'kontor-shop'),
                    mysql2date('d.m.Y', $due)
                )
                : __('Transfer the amount with the payment reference.', 'kontor-shop');
            $steps[] = $order->self_pickup
                ? __('As soon as your money has arrived, we\'ll set the goods aside for pickup.', 'kontor-shop')
                : __('As soon as your money has arrived, we\'ll ship your order.', 'kontor-shop');
        } else {
            $steps[] = $order->self_pickup
                ? __('We\'ll set the goods aside for you to pick up.', 'kontor-shop')
                : __('We\'ll ship your order.', 'kontor-shop');
            $invoice_days = (new PaymentOptions($this->settings))->terms()->invoice_days;
            $steps[]      = $invoice_days > 0
                ? sprintf(
                    /* translators: %d: days */
                    __('After receiving the goods, you transfer the amount with the payment reference – within %d days of shipping.', 'kontor-shop'),
                    $invoice_days
                )
                : __('After receiving the goods, you transfer the amount with the payment reference.', 'kontor-shop');
        }

        if (!$order->self_pickup) {
            $steps[] = __('You\'ll get another email from us when it ships.', 'kontor-shop');
        }

        $html = '<section class="kontor-shop-order-confirmation__steps"><h3>' . esc_html__('What happens next', 'kontor-shop') . '</h3><ol>';

        foreach ($steps as $step) {
            $html .= '<li>' . esc_html($step) . '</li>';
        }

        return $html . '</ol></section>';
    }
}
