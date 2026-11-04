<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin;

use Kontor\Shop\Infrastructure\Orders\ExternalPayments;
use Kontor\Shop\Admin\Ui\Badge;
use Kontor\Shop\Admin\Ui\Flash;
use Kontor\Shop\Admin\Ui\ListParams;
use Kontor\Shop\Core\Capabilities;
use Kontor\Shop\Domain\Order\Actor;
use Kontor\Shop\Domain\Order\Fulfilment;
use Kontor\Shop\Domain\Order\ItemCancellation;
use Kontor\Shop\Domain\Order\Order;
use Kontor\Shop\Domain\Order\PaidWith;
use Kontor\Shop\Domain\Order\PaymentTerms;
use Kontor\Shop\Infrastructure\Orders\Cashier;
use Kontor\Shop\Infrastructure\Orders\ShipmentRecorder;
use Kontor\Shop\Infrastructure\Payment\BankAccounts;
use Kontor\Shop\Infrastructure\Repositories\OrderRepository;
use Kontor\Shop\Infrastructure\Repositories\StockRepository;
use Kontor\Shop\Infrastructure\Repositories\WarehouseRepository;
use Kontor\Shop\Modules\Notifications\NotificationsModule;
use Kontor\Shop\Modules\Payments\PaymentOptions;
use Kontor\Shop\Settings\Repository;

/**
 * Bestellübersicht (Abschnitt 8/10): gemeinsame Liste, nach Rolle gefiltert.
 * Kassier sieht alle Bestellungen mit Zahlungsstatus-Aktionen (Abschnitt 8),
 * Lagerverwalter nur Bestellungen mit mindestens einem Artikel aus dem
 * eigenen Lager, mit Versand-/Abholaktionen (Abschnitt 7), Shop-Admin sieht
 * alles inkl. aller Aktionen. Eine Bestellung kann Artikel aus mehreren
 * Lagern enthalten (1 Artikel = 1 Lager, aber Bestellungen sind nicht
 * lagergebunden), daher pro Bestellung eine eigene Sichtbarkeits-/
 * Aktions-Pruefung statt einer reinen Lager-ID-Filterung in der Liste.
 */
final class OrdersPage
{
    public const SLUG         = 'kontor-shop-orders';
    public const NONCE_ACTION = 'kontor_shop_order_action';
    public const BULK_NONCE   = 'kontor_shop_order_bulk';
    public const SORTABLE     = ['created', 'number', 'customer', 'total'];

    /** @var string Art der zuletzt zurueckgegebenen Meldung (success|error|warning) */
    private string $notice_type = 'success';

    private OrderRepository $orders;
    private WarehouseRepository $warehouses;
    private StockRepository $stock;
    private OrderDetailView $detail_view;
    private Cashier $cashier;
    private PaymentTerms $terms;
    private OrdersList $list;
    private NotificationsModule $notifications;

    public function __construct(Repository $settings)
    {
        $this->terms      = (new PaymentOptions($settings))->terms();
        $this->orders     = new OrderRepository();
        $this->warehouses = new WarehouseRepository();
        $this->stock      = new StockRepository();
        $this->cashier    = new Cashier($this->orders, $this->stock);
        $this->detail_view = new OrderDetailView($this->orders, $this->warehouses, new BankAccounts($settings), $this->terms);
        $this->list        = new OrdersList($this->orders, $this->warehouses, $this->terms);
        $this->notifications = new NotificationsModule($settings);
    }

    /**
     * Aktionen ausfuehren (Einzel- und Sammelaktionen); laeuft auf load-{hook},
     * vor jeder Ausgabe, damit nach dem Speichern umgeleitet werden kann.
     */
    public function handle_request(): void
    {
        $can_manage           = Capabilities::can_manage();
        $is_cashier           = Capabilities::is_cashier();
        $is_warehouse_manager = Capabilities::is_warehouse_manager();

        if (!$can_manage && !$is_cashier && !$is_warehouse_manager) {
            wp_die(esc_html__('No permission.', 'kontor-shop'));
        }

        $my_warehouse_ids = $this->my_warehouse_ids($can_manage);

        $this->handle_bulk($can_manage, $is_cashier, $my_warehouse_ids);

        $notice = $this->handle_action($can_manage, $is_cashier);

        if ($notice !== null) {
            // Meldung nur nach check_admin_referer() in handle_action(); GET-Werte bestimmen nur das Ziel der Umleitung.
            $order_id    = absint($_POST['order_id'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in OrdersPage::handle_action
            $from_detail = absint($_GET['order'] ?? 0) > 0 && sanitize_key((string) wp_unslash($_POST['order_action'] ?? '')) !== 'delete'; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Nonce geprueft in OrdersPage::handle_action, order nur Anzeige-Parameter

            $target      = $from_detail && $order_id > 0 ? self::url(['order' => $order_id]) : self::list_url();

            /**
             * Filter kontor_shop/orders/redirect_after_action: wohin es nach
             * einer Aktion in der Bestellverwaltung zurueckgeht, z. B. Pro:
             * zurueck in die Packansicht (?pack=…). Standard: Detailansicht
             * der Bestellung bzw. die Liste mit ihren Filtern.
             *
             * @param string $target
             * @param array{order_id: int, from_detail: bool} $context
             */
            $target = (string) apply_filters('kontor_shop/orders/redirect_after_action', $target, ['order_id' => $order_id, 'from_detail' => $from_detail]);

            Flash::redirect($target, $notice, $this->notice_type);
        }
    }

    public function render(): void
    {
        $can_manage = Capabilities::can_manage();
        $is_cashier = Capabilities::is_cashier();
        $is_warehouse_manager = Capabilities::is_warehouse_manager();

        if (!$can_manage && !$is_cashier && !$is_warehouse_manager) {
            wp_die(esc_html__('No permission.', 'kontor-shop'));
        }

        $my_warehouse_ids = $this->my_warehouse_ids($can_manage);

        $cancel_id = isset($_GET['cancel_order']) ? absint($_GET['cancel_order']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige-Parameter

        if ($cancel_id > 0 && ($can_manage || $is_cashier)) {
            $cancel_order = $this->orders->find($cancel_id);

            if ($cancel_order instanceof Order && (new Fulfilment($cancel_order, $this->orders->items_for_order($cancel_id)))->can_cancel_order()) {
                $this->render_cancel_page($cancel_order);

                return;
            }
        }

        /**
         * Filter kontor_shop/orders/render_view: eine eigene Ansicht statt
         * Detail/Liste ausgeben (z. B. Pro: die Packansicht bei ?pack=…) und
         * true zurueckgeben. Standard: false (Detail bzw. Liste).
         *
         * @param bool $handled
         * @param array{can_manage: bool, is_cashier: bool, warehouse_ids: array<int, int>} $context
         *        warehouse_ids: Lager des Benutzers (leer bei Shop-Admins = alle)
         */
        if (apply_filters('kontor_shop/orders/render_view', false, ['can_manage' => $can_manage, 'is_cashier' => $is_cashier, 'warehouse_ids' => $my_warehouse_ids]) === true) {
            return;
        }

        $detail_id = isset($_GET['order']) ? absint($_GET['order']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige-Parameter

        if ($detail_id > 0) {
            $detail = $this->orders->find($detail_id);
            $full   = $can_manage || $is_cashier;

            if ($detail instanceof Order && ($full || $this->order_touches_warehouses($detail_id, $my_warehouse_ids))) {
                $this->detail_view->render(
                    $detail,
                    $full,
                    $can_manage,
                    $full && $detail->can_mark_paid(),
                    ($can_manage || $this->order_touches_warehouses($detail_id, $my_warehouse_ids)) && $detail->can_ship(),
                    $my_warehouse_ids,
                    null
                );

                return;
            }
        }

        $this->list->render($can_manage, $is_cashier, $my_warehouse_ids);
    }

    /**
     * @param array<string, mixed> $args
     */
    public static function url(array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('admin.php'));
    }

    /**
     * Link auf die Packansicht - oder '', wenn es keine gibt (dann keinen
     * Packen-Knopf zeigen).
     *
     * @param array<string, mixed> $args ['pack' => 'all'] | ['pack' => 'sel', 'ids' => '1,2'] | ['pack' => Bestell-ID]
     */
    public static function pack_url(array $args): string
    {
        /**
         * Filter kontor_shop/orders/pack_url: Link auf die Packansicht fuers
         * Handy (z. B. Pro). Ohne Zuhoerer gibt es keine Packen-Knoepfe und
         * -Links, und {{pack_url}} im Versandauftrag fuehrt auf die
         * Bestellung. Standard: '' (keine Packansicht).
         *
         * @param string               $url
         * @param array<string, mixed> $args pack: 'all', 'sel' (+ ids) oder eine Bestell-ID
         */
        return (string) apply_filters('kontor_shop/orders/pack_url', '', $args);
    }

    /**
     * Listen-Link mit den aktuellen Ansicht/Filtern/Sortierung/Seite - dorthin
     * geht es nach einer Aktion zurueck.
     */
    public static function list_url(): string
    {
        $keep = [];

        foreach (['view', 'range', 'ship', 'member', 'orderby', 'order'] as $key) {
            $keep[$key] = ListParams::key($key);
        }

        $keep['q']            = ListParams::text('q');
        $keep['ids']          = ListParams::text('ids');
        $keep['warehouse_id'] = ListParams::int('warehouse_id');
        $keep['paged']        = ListParams::int('paged');

        return ListParams::url(['page' => self::SLUG], $keep);
    }

    /**
     * @return array<int, int>
     */
    private function my_warehouse_ids(bool $can_manage): array
    {
        return $can_manage ? [] : (Capabilities::warehouse_scope() ?? []);
    }

    /**
     * @param array<int, int> $warehouse_ids
     */
    private function order_touches_warehouses(int $order_id, array $warehouse_ids): bool
    {
        if ($warehouse_ids === []) {
            return false;
        }

        foreach ($this->orders->items_for_order($order_id) as $item) {
            if (in_array($item->warehouse_id, $warehouse_ids, true)) {
                return true;
            }
        }

        return false;
    }

    private function fail(string $message): string
    {
        $this->notice_type = 'error';

        return $message;
    }

    private function handle_action(bool $can_manage, bool $is_cashier): ?string
    {
        if (sanitize_text_field(wp_unslash((string) ($_SERVER['REQUEST_METHOD'] ?? ''))) !== 'POST' || !isset($_POST['kontor_shop_order_nonce'])) {
            return null;
        }

        check_admin_referer(self::NONCE_ACTION, 'kontor_shop_order_nonce');

        $order_id = absint($_POST['order_id'] ?? 0);
        $action   = sanitize_key((string) ($_POST['order_action'] ?? ''));
        $order    = $order_id > 0 ? $this->orders->find($order_id) : null;

        if (!$order instanceof Order) {
            return $this->fail(__('Order not found.', 'kontor-shop'));
        }

        if ($action === 'mark_paid') {
            return $this->handle_mark_paid($order, $can_manage, $is_cashier);
        }

        if ($action === 'cancel') {
            return $this->handle_cancel($order, $can_manage, $is_cashier);
        }

        if ($action === 'cancel_items') {
            return $this->handle_cancel_items($order, $can_manage, $is_cashier);
        }

        if ($action === 'return_items') {
            return $this->handle_return($order, $can_manage, $is_cashier);
        }

        if ($action === 'refund_done') {
            return $this->handle_refund_done($order, $can_manage, $is_cashier);
        }

        if ($action === 'undo_paid') {
            return $this->handle_undo_paid($order, $can_manage, $is_cashier);
        }

        if ($action === 'ship' || $action === 'mark_shipped' || $action === 'mark_picked_up') {
            return $this->handle_ship($order, $can_manage, false);
        }

        if ($action === 'delete') {
            return $this->handle_delete($order, $can_manage);
        }

        if ($action === 'ready_for_pickup') {
            return $this->handle_ready_for_pickup($order, $can_manage, $is_cashier);
        }

        if ($action === 'payment_reminder') {
            return $this->handle_reminder($order, $can_manage, $is_cashier);
        }

        if ($action === 'add_note') {
            return $this->handle_note($order, $can_manage, $is_cashier);
        }

        if ($action === 'resend_confirmation') {
            return $this->handle_resend($order, $can_manage, $is_cashier);
        }

        return null;
    }

    /**
     * Fuehrende Kassa (Gegenstelle) gekoppelt: Zahlungen nur dort - ausser WP-Admin nutzt bewusst den Notausgang.
     * Gibt die Fehlermeldung zurueck oder null, wenn gebucht werden darf.
     */
    private function owner_blocks(): ?string
    {
        $owner = ExternalPayments::owner();

        if ($owner === null || (current_user_can('manage_options') && sanitize_text_field(wp_unslash((string) ($_POST['owner_override'] ?? ''))) === '1')) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in OrdersPage::handle_action bzw. handle_bulk
            return null;
        }

        return $this->fail(sprintf(
            /* translators: %s: name of the connected system */
            __('Payments are booked in %s – please book them there.', 'kontor-shop'),
            $owner['label']
        ));
    }

    /** Vermerk fuer Buchungen ueber den Notausgang. */
    private function override_note(): string
    {
        $owner = ExternalPayments::owner();

        return $owner !== null ? sprintf(
            /* translators: %s: name of the connected system */
            __('Booked in the shop (emergency exit; payments otherwise go through %s).', 'kontor-shop'),
            $owner['label']
        ) : '';
    }

    private function handle_mark_paid(Order $order, bool $can_manage, bool $is_cashier): ?string
    {
        if (!$can_manage && !$is_cashier) {
            wp_die(esc_html__('No permission.', 'kontor-shop'));
        }

        if (($blocked = $this->owner_blocks()) !== null) {
            return $blocked;
        }

        $paid_with = sanitize_key((string) wp_unslash($_POST['paid_with'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in OrdersPage::handle_action bzw. handle_bulk
        $paid_on   = sanitize_text_field(wp_unslash((string) ($_POST['paid_on'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in OrdersPage::handle_action bzw. handle_bulk
        $today     = current_time('Y-m-d');
        $paid_at   = null;

        // Zahlungseingang an einem frueheren Tag (z. B. Kontoauszug von gestern): nicht in der Zukunft, nicht vor der Bestellung.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $paid_on) === 1 && $paid_on !== $today) {
            if ($paid_on > $today || $paid_on < substr((string) $order->created_at, 0, 10)) {
                return $this->fail(__('The payment date must be between the order date and today.', 'kontor-shop'));
            }

            $paid_at = $paid_on . ' ' . current_time('H:i:s');
        }

        $result = $this->cashier->mark_paid(
            $order,
            Actor::user(get_current_user_id() ?: null),
            PaidWith::is_known($paid_with) ? $paid_with : null,
            trim(($paid_at !== null ? sprintf(
                /* translators: %s: date */
                __('Payment received on %s.', 'kontor-shop'),
                mysql2date('d.m.Y', $paid_at)
            ) : '') . ' ' . $this->override_note()),
            $paid_at
        );

        if (!$result->ok) {
            return $this->fail($result->message);
        }

        return sprintf(
            $order->pays_on_handover()
                /* translators: %s: order number */
                ? __('%s marked as paid – the customer gets the payment confirmation.', 'kontor-shop')
                /* translators: %s: order number */
                : __('%s marked as paid – the customer gets the payment confirmation, and the warehouse gets the shipping request.', 'kontor-shop'),
            $order->order_number
        );
    }

    /**
     * Zahlungserinnerung von Hand (zusaetzlich zur automatischen).
     */
    private function handle_reminder(Order $order, bool $can_manage, bool $is_cashier): ?string
    {
        if (!$can_manage && !$is_cashier) {
            wp_die(esc_html__('No permission.', 'kontor-shop'));
        }

        if (!$this->notifications->send_payment_reminder($order, get_current_user_id() ?: null)) {
            return $this->fail(__('A payment reminder is only available for open prepayment orders and shipped invoices that haven’t been paid yet.', 'kontor-shop'));
        }

        return sprintf(
            /* translators: 1: order number, 2: email */
            __('%1$s: Payment reminder sent to %2$s.', 'kontor-shop'),
            $order->order_number,
            $order->customer_email
        );
    }

    /**
     * "Abholbereit": Kunde einer Abhol-Bestellung per Mail benachrichtigen
     * (Shop-Manager, Kassier oder Lagerverwalter eines betroffenen Lagers).
     */
    private function handle_ready_for_pickup(Order $order, bool $can_manage, bool $is_cashier): ?string
    {
        if (!$can_manage && !$is_cashier && !$this->order_touches_warehouses((int) $order->id, $this->my_warehouse_ids(false))) {
            wp_die(esc_html__('No permission.', 'kontor-shop'));
        }

        if (!$order->self_pickup || !$order->can_ship()) {
            return $this->fail(__('“Ready for pickup” is only available for pickup orders that are paid (or on invoice) and not yet picked up.', 'kontor-shop'));
        }

        if (!$this->notifications->send_ready_for_pickup($order, get_current_user_id() ?: null)) {
            return $this->fail(__('The email “Ready for pickup” is switched off under Settings → Email templates, or nothing is left to pick up.', 'kontor-shop'));
        }

        return sprintf(
            /* translators: 1: order number, 2: email */
            __('%1$s: “Ready for pickup” sent to %2$s.', 'kontor-shop'),
            $order->order_number,
            $order->customer_email
        );
    }

    /**
     * Interne Notiz im Statusverlauf (Schnellansicht der Liste).
     */
    private function handle_note(Order $order, bool $can_manage, bool $is_cashier): ?string
    {
        if (!$can_manage && !$is_cashier && !$this->order_touches_warehouses((int) $order->id, $this->my_warehouse_ids(false))) {
            wp_die(esc_html__('No permission.', 'kontor-shop'));
        }

        $note = trim(sanitize_textarea_field(wp_unslash((string) ($_POST['note'] ?? '')))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in OrdersPage::handle_action

        if ($note === '') {
            return $this->fail(__('The note is empty.', 'kontor-shop'));
        }

        $this->orders->add_log_note((int) $order->id, get_current_user_id() ?: null, __('Note:', 'kontor-shop') . ' ' . mb_substr($note, 0, 500));

        return sprintf(
            /* translators: %s: order number */
            __('%s: Note saved.', 'kontor-shop'),
            $order->order_number
        );
    }

    /**
     * Bestellbestaetigung erneut senden - an den Kaeufer oder an eine andere
     * Adresse (z. B. weitergeleitet an den Kassier). Der Vorgang steht im
     * Statusverlauf der Bestellung.
     */
    private function handle_resend(Order $order, bool $can_manage, bool $is_cashier): ?string
    {
        if (!$can_manage && !$is_cashier) {
            wp_die(esc_html__('No permission.', 'kontor-shop'));
        }

        $to = sanitize_email(wp_unslash((string) ($_POST['resend_to'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in OrdersPage::handle_action

        if (isset($_POST['resend_to']) && $to === '') { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in OrdersPage::handle_action
            return $this->fail(__('Please enter a valid email address.', 'kontor-shop'));
        }

        $recipient = $to !== '' && is_email($to) ? $to : $order->customer_email;

        do_action(NotificationsModule::HOOK_RESEND_CONFIRMATION, $order, $to !== '' ? $to : null); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Konstante mit Praefix kontor_shop/

        $this->orders->add_log_note((int) $order->id, get_current_user_id() ?: null, sprintf(
            /* translators: %s: email address */
            __('Confirmation email resent to %s.', 'kontor-shop'),
            $recipient
        ));

        return sprintf(
            /* translators: %s: email address */
            __('The confirmation email was sent to %s.', 'kontor-shop'),
            $recipient
        );
    }

    /**
     * Endgueltiges Loeschen (nur Shop-Admin, mit Bestaetigungsdialog). Fuer
     * Testbestellungen und manuelle Datenpflege - Bestell-/Rechnungsdaten
     * unterliegen meist einer Aufbewahrungspflicht, daher nie automatisch.
     */
    private function handle_delete(Order $order, bool $can_manage): ?string
    {
        if (!$can_manage) {
            wp_die(esc_html__('No permission.', 'kontor-shop'));
        }

        // Noch nicht versendete Ware zurueck ins Lager, sonst fehlt sie dauerhaft im Bestand.
        $restocked = $this->cashier->restock_before_delete($order, Actor::user(get_current_user_id() ?: null));

        $this->orders->delete((int) $order->id);

        return sprintf(
            /* translators: %s: order number */
            __('Order %s was deleted.', 'kontor-shop'),
            $order->order_number
        ) . ($restocked > 0 ? ' ' . __('Products that had not been shipped were booked back to the warehouse.', 'kontor-shop') : '');
    }

    private function handle_cancel(Order $order, bool $can_manage, bool $is_cashier): ?string
    {
        if (!$can_manage && !$is_cashier) {
            wp_die(esc_html__('No permission.', 'kontor-shop'));
        }

        $result = $this->cashier->cancel(
            $order,
            null,
            $this->posted_stock_choices($order),
            sanitize_text_field(wp_unslash((string) ($_POST['cancel_reason'] ?? ''))), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in OrdersPage::handle_action
            $this->posted_amount('refund_amount'),
            !empty($_POST['notify_customer']), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in OrdersPage::handle_action
            Actor::user(get_current_user_id() ?: null)
        );

        if (!$result->ok) {
            return $this->fail($result->message);
        }

        return $result->data['refund'] > 0
            ? sprintf(
                /* translators: %s: refund amount */
                __('Order cancelled. A refund of %s € is pending – please transfer it, then mark it as done in the order.', 'kontor-shop'),
                number_format_i18n($result->data['refund'], 2)
            )
            : __('Order cancelled, stock booked as specified.', 'kontor-shop');
    }

    /**
     * Einzelne (noch nicht versendete) Positionen stornieren - z. B. Artikel
     * doch nicht lieferbar. Unbezahlt: der offene Betrag sinkt. Bezahlt: die
     * Rueckzahlung wird vorgemerkt (Details siehe Infrastructure\Orders\Cashier).
     */
    private function handle_cancel_items(Order $order, bool $can_manage, bool $is_cashier): ?string
    {
        if (!$can_manage && !$is_cashier) {
            wp_die(esc_html__('No permission.', 'kontor-shop'));
        }

        $result = $this->cashier->cancel(
            $order,
            array_map('absint', (array) ($_POST['cancel_items'] ?? [])), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in OrdersPage::handle_action
            $this->posted_stock_choices($order),
            sanitize_text_field(wp_unslash((string) ($_POST['cancel_reason'] ?? ''))), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in OrdersPage::handle_action
            $this->posted_amount('refund_amount'),
            !empty($_POST['notify_customer']), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in OrdersPage::handle_action
            Actor::user(get_current_user_id() ?: null)
        );

        if (!$result->ok) {
            return $this->fail($result->message);
        }

        if ($result->data['refund'] > 0) {
            return sprintf(
                /* translators: %s: refund amount */
                __('Product cancelled. A refund of %s € is pending – please transfer it, then mark it as done.', 'kontor-shop'),
                number_format_i18n($result->data['refund'], 2)
            );
        }

        return $result->data['paid']
            ? __('Product cancelled (no refund).', 'kontor-shop')
            : sprintf(
                /* translators: %s: new open amount */
                __('Product cancelled. New open amount: %s €.', 'kontor-shop'),
                number_format_i18n($result->data['total'], 2)
            );
    }

    /**
     * Retoure erfassen: zurueckgekommene Stueck versendeter Positionen,
     * Bestand je Position, Rueckzahlung bzw. neuer Rechnungsbetrag
     * (Details: Infrastructure\Orders\Cashier::return_items).
     */
    private function handle_return(Order $order, bool $can_manage, bool $is_cashier): ?string
    {
        if (!$can_manage && !$is_cashier) {
            wp_die(esc_html__('No permission.', 'kontor-shop'));
        }

        $quantities = [];

        foreach (array_map('absint', (array) wp_unslash($_POST['return_qty'] ?? [])) as $item_id => $quantity) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in OrdersPage::handle_action
            $quantities[absint($item_id)] = $quantity;
        }

        $result = $this->cashier->return_items(
            $order,
            $quantities,
            $this->posted_stock_choices($order, 'return_stock_'),
            sanitize_text_field(wp_unslash((string) ($_POST['return_reason'] ?? ''))), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in OrdersPage::handle_action
            $this->posted_amount('refund_amount'),
            !empty($_POST['notify_customer']), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in OrdersPage::handle_action
            Actor::user(get_current_user_id() ?: null)
        );

        if (!$result->ok) {
            return $this->fail($result->message);
        }

        if ($result->data['refund'] > 0) {
            return sprintf(
                /* translators: %s: refund amount */
                __('Return recorded. A refund of %s € is pending – please transfer it, then mark it as done.', 'kontor-shop'),
                number_format_i18n($result->data['refund'], 2)
            );
        }

        return $result->data['paid']
            ? __('Return recorded (no refund).', 'kontor-shop')
            : sprintf(
                /* translators: %s: new open invoice amount */
                __('Return recorded. New open invoice amount: %s €.', 'kontor-shop'),
                number_format_i18n($result->data['total'], 2)
            );
    }

    /**
     * Rueckzahlung an den Kunden ist erfolgt.
     */
    /**
     * "Zahlung zuruecknehmen": versehentlich als bezahlt markierte Bestellung wieder auf offen.
     */
    private function handle_undo_paid(Order $order, bool $can_manage, bool $is_cashier): ?string
    {
        if (!$can_manage && !$is_cashier) {
            wp_die(esc_html__('No permission.', 'kontor-shop'));
        }

        if (($blocked = $this->owner_blocks()) !== null) {
            return $blocked;
        }

        $result = $this->cashier->undo_payment($order, Actor::user(get_current_user_id() ?: null));

        if (!$result->ok) {
            return $this->fail($result->message);
        }

        return __('Payment taken back – the order is open again. The customer was not notified. If the warehouse already received a shipping order, tell them not to ship.', 'kontor-shop');
    }

    private function handle_refund_done(Order $order, bool $can_manage, bool $is_cashier): ?string
    {
        if (!$can_manage && !$is_cashier) {
            wp_die(esc_html__('No permission.', 'kontor-shop'));
        }

        if (($blocked = $this->owner_blocks()) !== null) {
            return $blocked;
        }

        $result = $this->cashier->record_refund($order, null, Actor::user(get_current_user_id() ?: null), $this->override_note());

        return $result->ok ? __('Refund marked as done.', 'kontor-shop') : $this->fail($result->message);
    }

    /**
     * Pro Position gewaehlte Bestandsbuchung aus dem Storno-Formular (item_<id>).
     *
     * @return array<int, string>
     */
    private function posted_stock_choices(Order $order, string $field_prefix = 'item_'): array
    {
        $choices = [];

        foreach ($this->orders->items_for_order((int) $order->id) as $item) {
            $choice = sanitize_key((string) wp_unslash($_POST[$field_prefix . $item->id] ?? Cashier::STOCK_RESTOCK)); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in OrdersPage::handle_action

            $choices[(int) $item->id] = in_array($choice, Cashier::stock_choices(), true) ? $choice : Cashier::STOCK_RESTOCK;
        }

        return $choices;
    }

    /**
     * Betrag aus einem Formularfeld ("12,50" oder "12.50"); leer = null (Vorschlag verwenden).
     */
    private function posted_amount(string $field): ?float
    {
        $raw = sanitize_text_field(wp_unslash((string) ($_POST[$field] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in OrdersPage::handle_action

        if ($raw === '') {
            return null;
        }

        // Deutsches Format (1.234,50) oder Punkt als Dezimaltrenner (12.50).
        $normalized = str_contains($raw, ',') ? str_replace(['.', ','], ['', '.'], $raw) : $raw;

        return round((float) preg_replace('/[^\d.\-]/', '', $normalized), 2);
    }

    /**
     * Zwischenschritt vor dem Stornieren: pro Position waehlen, ob der
     * Bestand zurueckgebucht wird oder die Ware defekt/verloren ist.
     */
    private function render_cancel_page(Order $order): void
    {
        $action_url = self::url();

        echo '<div class="wrap ks-wrap">';
        echo '<h1>' . sprintf(
            /* translators: %s: order number */
            esc_html__('Cancel order %s', 'kontor-shop'),
            esc_html($order->order_number)
        ) . '</h1>';
        echo '<p>' . esc_html__(
            'What happened to the products in this order? Your choice determines how stock is booked.',
            'kontor-shop'
        ) . '</p>';

        echo '<form method="post" action="' . esc_url($action_url) . '">';
        wp_nonce_field(self::NONCE_ACTION, 'kontor_shop_order_nonce');
        echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $order->id) . '" />';
        echo '<input type="hidden" name="order_action" value="cancel" />';

        echo '<table class="widefat striped ks-w-820"><thead><tr>';
        echo '<th>' . esc_html__('Product', 'kontor-shop') . '</th><th>' . esc_html__('Quantity', 'kontor-shop') . '</th><th>' . esc_html__('Stock', 'kontor-shop') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($this->orders->items_for_order((int) $order->id) as $item) {
            if ($item->is_cancelled()) {
                continue; // schon einzeln storniert und verbucht
            }

            // Bereits versendete Positionen sind beim Kunden - standardmaessig nicht zurueckbuchen.
            $shipped = $item->shipment_id !== null;

            echo '<tr><td>' . esc_html($item->product_name)
                . ($shipped ? ' ' . Badge::html(__('already shipped', 'kontor-shop'), Badge::WARN) : '') // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Badge::html liefert escaptes HTML
                . '</td><td>' . esc_html((string) $item->quantity) . '</td><td>';
            echo '<select name="item_' . esc_attr((string) $item->id) . '">';
            echo '<option value="restock">' . esc_html__('Back to the warehouse (goods are fine)', 'kontor-shop') . '</option>';
            echo '<option value="defective">' . esc_html__('Not returned: defective / damaged', 'kontor-shop') . '</option>';
            echo '<option value="stolen">' . esc_html__('Not returned: lost / stolen', 'kontor-shop') . '</option>';
            echo '<option value="none"' . selected($shipped, true, false) . '>' . esc_html__('Don’t book back (no stock booking)', 'kontor-shop') . '</option>';
            echo '</select></td></tr>';
        }

        echo '</tbody></table>';

        echo '<p><label for="ks-cancel-reason"><strong>' . esc_html__('Reason (optional, appears in the email to the customer)', 'kontor-shop') . '</strong></label><br />';
        echo '<input type="text" id="ks-cancel-reason" name="cancel_reason" class="large-text ks-w-820" placeholder="' . esc_attr__('e.g. at the customer’s request', 'kontor-shop') . '" /></p>';

        if (ItemCancellation::is_paid($order)) {
            $suggested = max(0.0, round($order->total - $order->refund_total, 2));

            echo '<p><label for="ks-cancel-refund"><strong>' . esc_html__('Refund to the customer', 'kontor-shop') . '</strong></label><br />';
            echo '<input type="text" inputmode="decimal" id="ks-cancel-refund" name="refund_amount" class="small-text" value="' . esc_attr(number_format($suggested, 2, ',', '')) . '" /> € ';
            echo '<span class="description">' . esc_html__('The order is paid: the amount is set aside as a pending refund until you mark it as done. 0 = no refund.', 'kontor-shop') . '</span></p>';
        }

        echo '<p><label><input type="checkbox" name="notify_customer" value="1" checked /> '
            . esc_html__('Notify customer of the cancellation by email', 'kontor-shop') . '</label></p>';
        echo '<p><button type="submit" class="button button-primary">' . esc_html__('Cancel now', 'kontor-shop') . '</button> ';
        echo '<a class="button" href="' . esc_url($action_url) . '">' . esc_html__('Cancel', 'kontor-shop') . '</a></p>';
        echo '</form></div>';
    }

    /**
     * Versand/Abholung erfassen - positionsgenau je Lager (Dialog
     * "Versand erfassen"). Je Lager mit angekreuzten Positionen entsteht eine
     * Sendung mit eigenem Versanddienst/Trackingnummer. Sind danach alle
     * Positionen raus, wechselt die Bestellung auf versendet/abgeholt, sonst
     * bleibt sie "bezahlt" und gilt als teilweise versendet.
     *
     * @param bool $all_open true = alle offenen Positionen, die der Benutzer versenden darf (Sammelaktion)
     */
    private function handle_ship(Order $order, bool $can_manage, bool $all_open): ?string
    {
        $allowed = $can_manage ? null : $this->my_warehouse_ids(false);

        if (!$can_manage && !$this->order_touches_warehouses((int) $order->id, (array) $allowed)) {
            wp_die(esc_html__('No permission.', 'kontor-shop'));
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce geprueft in OrdersPage::handle_action bzw. handle_bulk
        $requested = $all_open ? null : array_map('absint', (array) ($_POST['items'] ?? []));
        // Nur die im Assistenten angekreuzten Lager (leer = keine Einschraenkung, z. B. Sammelaktion).
        $only_wh   = $all_open ? [] : array_values(array_filter(array_map('absint', explode(',', sanitize_text_field(wp_unslash((string) ($_POST['ship_warehouses'] ?? '')))))));
        $carriers  = array_map('sanitize_key', (array) wp_unslash($_POST['carrier'] ?? []));
        $trackings = array_map('sanitize_text_field', (array) wp_unslash($_POST['tracking'] ?? []));
        $note      = sanitize_text_field(wp_unslash((string) ($_POST['shipment_note'] ?? '')));
        $notify    = $all_open || !empty($_POST['notify_customer']);
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        $user_id   = get_current_user_id() ?: null;

        $result = (new ShipmentRecorder($this->orders))->record($order, $allowed, $requested, $only_wh, $carriers, $trackings, $note, $user_id, $notify);

        if (!$result->ok) {
            return $this->fail($result->message);
        }

        if ($user_id !== null) {
            foreach ((array) $result->data['carriers'] as $carrier) {
                update_user_meta($user_id, ShipmentDialog::USER_META_CARRIER, $carrier);
            }
        }

        return (string) $result->data['message'];
    }

    /**
     * Sammelaktionen "bezahlt" / "versendet bzw. abgeholt". Jede Bestellung wird
     * einzeln auf Berechtigung und erlaubten Statuswechsel geprueft; nicht
     * passende werden uebersprungen und in der Rueckmeldung gezaehlt.
     *
     * @param array<int, int> $my_warehouse_ids
     */
    private function handle_bulk(bool $can_manage, bool $is_cashier, array $my_warehouse_ids): void
    {
        if (sanitize_text_field(wp_unslash((string) ($_SERVER['REQUEST_METHOD'] ?? ''))) !== 'POST' || !isset($_POST['kontor_shop_order_bulk_nonce'])) {
            return;
        }

        check_admin_referer(self::BULK_NONCE, 'kontor_shop_order_bulk_nonce');

        $action = sanitize_key((string) ($_POST['bulk_action'] ?? ''));
        $action = $action !== '' ? $action : sanitize_key((string) ($_POST['bulk_action2'] ?? ''));
        $ids    = array_values(array_filter(array_map('absint', (array) ($_POST['ids'] ?? []))));
        $back   = self::list_url();

        if ($action === '' || $ids === []) {
            Flash::redirect($back, $action === '' ? __('Please select a bulk action.', 'kontor-shop') : __('Please check some orders in the list first.', 'kontor-shop'), 'warning');
        }

        $done    = 0;
        $skipped = 0;
        /** @var array<string, string> $failed Bestellnummer => Grund (Buchung abgelehnt, z. B. Datum, fuehrende Kassa) */
        $failed  = [];

        foreach ($ids as $id) {
            $target = $this->orders->find($id);

            if (!$target instanceof Order) {
                $skipped++;

                continue;
            }

            if ($action === 'mark_paid') {
                if (($can_manage || $is_cashier) && $target->can_mark_paid() && !ExternalPayments::active()) {
                    $this->bulk_result($this->handle_mark_paid($target, $can_manage, $is_cashier), $target, $done, $failed);
                } else {
                    $skipped++;
                }

                continue;
            }

            if ($action === 'ship') {
                if (($can_manage || $this->order_touches_warehouses($id, $my_warehouse_ids)) && $target->can_ship()) {
                    $this->bulk_result($this->handle_ship($target, $can_manage, true), $target, $done, $failed);
                } else {
                    $skipped++;
                }

                continue;
            }

            Flash::redirect($back, __('Unknown bulk action.', 'kontor-shop'), 'error');
        }

        $message = sprintf(
            /* translators: %d: number of updated orders */
            _n('%d order updated.', '%d orders updated.', $done, 'kontor-shop'),
            $done
        );

        if ($skipped > 0) {
            $message .= ' ' . sprintf(
                /* translators: %d: number of skipped orders */
                _n('%d skipped (status or permission doesn’t match).', '%d skipped (status or permission doesn’t match).', $skipped, 'kontor-shop'),
                $skipped
            );
        }

        if ($failed !== []) {
            $first   = reset($failed);
            $message .= ' ' . sprintf(
                /* translators: 1: number of failed orders, 2: order numbers, 3: reason of the first failure */
                _n('%1$d failed (%2$s): %3$s', '%1$d failed (%2$s): %3$s', count($failed), 'kontor-shop'),
                count($failed),
                implode(', ', array_keys($failed)),
                $first
            );
        }

        Flash::redirect($back, $message, $failed !== [] ? 'error' : ($done > 0 ? 'success' : 'warning'));
    }

    /**
     * Ergebnis einer Einzelbuchung in der Sammelaktion zaehlen: fail() setzt
     * notice_type auf "error" - dann zaehlt die Bestellung als fehlgeschlagen,
     * nicht als erledigt.
     *
     * @param array<string, string> $failed
     */
    private function bulk_result(?string $message, Order $order, int &$done, array &$failed): void
    {
        if ($this->notice_type === 'error') {
            $failed[$order->order_number] = (string) $message;
            $this->notice_type            = 'success';

            return;
        }

        $done++;
    }
}
