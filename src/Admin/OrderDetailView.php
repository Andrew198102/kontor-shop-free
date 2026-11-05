<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin;

use Kontor\Shop\Infrastructure\Orders\ExternalPayments;
use Kontor\Shop\Admin\Ui\Css;
use Kontor\Shop\Admin\Ui\Badge;
use Kontor\Shop\Admin\Ui\Flash;
use Kontor\Shop\Domain\Order\Carrier;
use Kontor\Shop\Domain\Order\Fulfilment;
use Kontor\Shop\Domain\Order\ItemCancellation;
use Kontor\Shop\Domain\Order\ItemReturn;
use Kontor\Shop\Domain\Order\Order;
use Kontor\Shop\Domain\Order\PaymentReminder;
use Kontor\Shop\Domain\Order\OrderItem;
use Kontor\Shop\Domain\Order\OrderStatus;
use Kontor\Shop\Domain\Order\PaidWith;
use Kontor\Shop\Domain\Order\PaymentTerms;
use Kontor\Shop\Domain\Order\PaymentMethod;
use Kontor\Shop\Domain\Order\Shipment;
use Kontor\Shop\Domain\Tax\TaxCalculator;
use Kontor\Shop\Infrastructure\Payment\BankAccounts;
use Kontor\Shop\Infrastructure\Repositories\OrderRepository;
use Kontor\Shop\Infrastructure\Repositories\WarehouseRepository;

/**
 * Detailseite einer Bestellung im Backoffice: vollständiger Inhalt, Kunde,
 * Lieferung, Zahlung, USt-Aufschluesselung, Statusverlauf und Aktionen
 * (bezahlt, versendet, stornieren, Bestaetigung erneut senden). Die
 * Autorisierung entscheidet OrdersPage (Kassier/Admin sehen alles,
 * Lagerverwalter nur die Positionen ihres Lagers, ohne Zahlungs-/Preisdaten);
 * die Aktionen selbst verarbeitet weiterhin OrdersPage::handle_action().
 */
final class OrderDetailView
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly WarehouseRepository $warehouses,
        private readonly BankAccounts $bank_accounts,
        private readonly PaymentTerms $terms = new PaymentTerms(0, 0),
    ) {
    }

    /**
     * @param array<int, int> $my_warehouse_ids nur relevant, wenn $full false ist
     */
    public function render(Order $order, bool $full, bool $can_manage, bool $can_pay, bool $can_ship, array $my_warehouse_ids, ?string $notice): void
    {
        $statuses  = OrderStatus::labels();
        $items     = $this->orders->items_for_order((int) $order->id);
        $wh_names  = [];

        foreach ($this->warehouses->all() as $warehouse) {
            $wh_names[$warehouse->id] = $warehouse->name;
        }

        $all_items  = $items;
        $shipments  = $this->orders->shipments_for_order((int) $order->id);
        $fulfilment = new Fulfilment($order, $all_items);
        $by_id      = [];

        foreach ($shipments as $shipment) {
            $by_id[(int) $shipment->id] = $shipment;
        }

        if (!$full) {
            $items = array_values(array_filter($items, static fn ($item): bool => in_array($item->warehouse_id, $my_warehouse_ids, true)));
        }

        $list_url = OrdersPage::list_url();

        echo '<div class="wrap ks-wrap">';
        echo '<h1>' . sprintf(
            /* translators: %s: order number */
            esc_html__('Order %s', 'kontor-shop'),
            esc_html($order->order_number)
        ) . ' ' . Badge::order_status($order->status) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Badge liefert escaptes HTML
            . ($order->can_ship() && $fulfilment->is_partial()
                ? ' ' . Badge::html($order->self_pickup ? __('partially picked up', 'kontor-shop') : __('partially shipped', 'kontor-shop'), Badge::WARN) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Badge liefert escaptes HTML
                : '') . '</h1>';
        echo '<p><a href="' . esc_url($list_url) . '">&larr; ' . esc_html__('Back to order list', 'kontor-shop') . '</a></p>';

        echo \Kontor\Shop\Infrastructure\Notifications\MailHealth::notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MailHealth::notice_html() liefert escaptes HTML
        echo \Kontor\Shop\Infrastructure\Logging\Logger::notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Logger::notice_html() liefert escaptes HTML

        Flash::render();

        if ($full) {
            echo self::owner_notice_html($order->payment_reference, $order); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- owner_notice_html() liefert escaptes HTML
        }

        echo '<div class="ks-order-cols">';

        // ---- Kunde & Lieferung
        echo '<div class="ks-order-col">';
        echo '<h2>' . esc_html__('Customer & delivery', 'kontor-shop') . '</h2>';
        echo '<table class="widefat striped"><tbody>';
        $this->row(__('Name', 'kontor-shop'), esc_html($order->customer_name));
        $this->row(__('Email', 'kontor-shop'), '<a href="mailto:' . esc_attr($order->customer_email) . '">' . esc_html($order->customer_email) . '</a>');
        if ($order->customer_phone !== null) {
            $this->row(__('Phone', 'kontor-shop'), '<a href="tel:' . esc_attr(preg_replace('/[^\d+]/', '', $order->customer_phone)) . '">' . esc_html($order->customer_phone) . '</a>');
        }

        $this->row(__('Member', 'kontor-shop'), $order->is_member ? esc_html__('yes', 'kontor-shop') : esc_html__('no', 'kontor-shop'));
        $this->row(
            __('Delivery method', 'kontor-shop'),
            $order->self_pickup ? esc_html__('Self pickup', 'kontor-shop') : nl2br(esc_html((string) $order->delivery_address))
        );
        if ($full) {
            $this->row(
                __('Invoice to', 'kontor-shop'),
                $order->billing_address !== null
                    ? nl2br(esc_html($order->billing_address)) . ($order->billing_vat_id !== null ? '<br /><small>UID: ' . esc_html($order->billing_vat_id) . '</small>' : '')
                    : '<em>' . esc_html__('same as shipping address', 'kontor-shop') . '</em>'
            );
        }

        echo '</tbody></table></div>';

        // ---- Zahlung & Zeiten
        if ($full) {
            $account = $this->bank_accounts->resolve($order->bank_account_id);

            echo '<div class="ks-order-col">';
            echo '<h2>' . esc_html__('Payment & history', 'kontor-shop') . '</h2>';
            echo '<table class="widefat striped"><tbody>';
            $this->row(
                __('Payment method', 'kontor-shop'),
                esc_html(PaymentMethod::label($order->payment_method))
            );

            if ($account !== null) {
                $this->row(__('Bank account', 'kontor-shop'), esc_html($account->display_name()) . '<br /><small>' . esc_html($account->recipient . ' / ' . $account->iban . ' / ' . $account->bic) . '</small>');
            }

            $this->row(__('Payment reference', 'kontor-shop'), '<code>' . esc_html($order->payment_reference) . '</code>');

            if ($order->refund_total > 0) {
                $pending = $order->refund_pending();

                $this->row(
                    __('Refund', 'kontor-shop'),
                    $pending > 0
                        /* translators: %s: open refund amount */
                        ? Badge::html(sprintf(__('open: %s €', 'kontor-shop'), number_format_i18n($pending, 2)), Badge::BAD)
                            . ' <small>' . esc_html__('transfer to the customer, then click “Refund done” below', 'kontor-shop') . '</small>'
                        /* translators: %s: refunded amount */
                        : Badge::html(sprintf(__('%s € done', 'kontor-shop'), number_format_i18n($order->refund_total, 2)), Badge::OK)
                );
            }
            $this->row(__('Ordered on', 'kontor-shop'), esc_html(mysql2date('d.m.Y, H:i', (string) $order->created_at)));

            $due = $order->awaits_payment() ? $this->terms->due_date($order) : null;

            if ($due !== null) {
                $this->row(
                    $order->is_invoice() ? __('Payment due date', 'kontor-shop') : __('Payment deadline', 'kontor-shop'),
                    esc_html(mysql2date('d.m.Y', $due))
                        . ($this->terms->is_overdue($order, current_time('Y-m-d'))
                            ? ' ' . Badge::html($order->is_invoice()
                                ? __('overdue – check payment or send a reminder', 'kontor-shop')
                                : __('overdue – check payment or cancel', 'kontor-shop'), Badge::BAD)
                            : '')
                );
            } elseif ($order->is_invoice() && $order->awaits_payment()) {
                $this->row(__('Payment due date', 'kontor-shop'), esc_html(sprintf(
                    /* translators: %d: days */
                    __('%d days from shipment of the whole order', 'kontor-shop'),
                    $this->terms->invoice_days
                )));
            }
            $this->row(__('Paid on', 'kontor-shop'), esc_html($order->paid_at ?? '—') . ($order->paid_via !== null ? $this->paid_via($order) : $this->by($order->paid_by_user_id)));
            $this->row(__('Shipped/picked up on', 'kontor-shop'), esc_html($order->shipped_at ?? '—') . $this->by($order->shipped_by_user_id));
            $this->row(__('Cancelled on', 'kontor-shop'), esc_html($order->cancelled_at ?? '—'));
            $this->row(__('Consent to terms and conditions/privacy policy', 'kontor-shop'), esc_html($order->legal_accepted_at ?? '—'));
            echo '</tbody></table></div>';
        }

        echo '</div>';

        // ---- Positionen
        echo '<h2>' . esc_html__('Items', 'kontor-shop') . '</h2>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Product', 'kontor-shop') . '</th><th>' . esc_html__('Warehouse', 'kontor-shop') . '</th><th>' . esc_html__('Quantity', 'kontor-shop') . '</th><th>' . esc_html__('Shipping', 'kontor-shop') . '</th>';

        if ($full) {
            echo '<th>' . esc_html__('Unit price', 'kontor-shop') . '</th><th>' . esc_html__('VAT', 'kontor-shop') . '</th><th>' . esc_html__('Total', 'kontor-shop') . '</th>';
        }

        echo '</tr></thead><tbody>';

        $tax_lines = [];

        foreach ($items as $item) {
            if (!$item->is_cancelled()) {
                $tax_lines[] = ['tax_rate' => $item->tax_rate, 'line_total' => $item->effective_total()];
            }

            $ship_state = match (true) {
                $item->is_cancelled() => Badge::html(__('cancelled', 'kontor-shop'), Badge::MUTED) . ' <small>'
                    . esc_html(mysql2date('d.m.Y', (string) $item->cancelled_at) . ($item->cancel_note !== null ? ' · ' . $item->cancel_note : '')) . '</small>',
                $order->status === OrderStatus::CANCELLED => '<span class="ks-muted">—</span>',
                $fulfilment->is_shipped($item) => Badge::html(__('out', 'kontor-shop'), Badge::OK) . ' <small>'
                    . esc_html(ShipmentDialog::shipment_summary($item->shipment_id !== null ? ($by_id[$item->shipment_id] ?? null) : null, $order->self_pickup)) . '</small>',
                $order->can_ship() => Badge::html(__('open', 'kontor-shop'), Badge::WARN),
                default => '<span class="ks-muted">' . esc_html__('after payment', 'kontor-shop') . '</span>',
            };

            if ($item->returned_qty > 0) {
                $ship_state .= '<br />' . Badge::html(sprintf(
                    /* translators: %d: pieces */
                    __('%d returned', 'kontor-shop'),
                    $item->returned_qty
                ), Badge::MUTED) . ' <small>' . esc_html(mysql2date('d.m.Y', (string) $item->returned_at)) . '</small>';
            }

            $strike = $item->is_cancelled() ? ' class="ks-struck"' : '';

            echo '<tr><td' . $strike . '>' . esc_html($item->product_name) . '</td><td><span class="ks-wh-dot ' . esc_attr(Css::wh($item->warehouse_id)) . '"></span>' . esc_html($wh_names[$item->warehouse_id] ?? '—') . '</td><td' . $strike . '>' . esc_html((string) $item->quantity) . '</td><td>' . $ship_state . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $strike ist ein festes Attribut, $ship_state aus Badge/esc_html

            if ($full) {
                $list_note = abs($item->list_price - $item->unit_price) > 0.001
                    ? ' <small class="ks-struck">' . esc_html(number_format_i18n($item->list_price, 2)) . '</small>'
                    : '';

                echo '<td>' . esc_html(number_format_i18n($item->unit_price, 2)) . ' €' . $list_note . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $list_note ist mit esc_html gebaut
                echo '<td>' . ($item->tax_rate !== null ? esc_html(number_format_i18n($item->tax_rate, 2) . ' %') : '—') . '</td>';
                echo '<td' . $strike . '>' . esc_html(number_format_i18n($item->line_total, 2)) . ' €</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $strike ist ein festes Attribut
            }

            echo '</tr>';
        }

        echo '</tbody>';

        if ($full) {
            echo '<tfoot>';
            echo '<tr><td colspan="6" class="ks-right">' . esc_html__('Order value', 'kontor-shop') . '</td><td>' . esc_html(number_format_i18n($order->goods_total(), 2)) . ' €</td></tr>';

            if ($order->has_discount()) {
                /**
                 * Filter kontor_shop/admin/discount_code_url: Link vom Rabatt in der
                 * Bestellung zum Rabattcode (Pro: Seite "Rabatte"). Standard null =
                 * der Code steht als reiner Text da (auch alte Bestellungen in Free).
                 *
                 * @param string|null $url  Ziel-URL oder null
                 * @param string      $code Rabattcode der Bestellung (orders.discount_code)
                 */
                $code_url = apply_filters('kontor_shop/admin/discount_code_url', null, (string) $order->discount_code);

                echo '<tr><td colspan="6" class="ks-right">' . esc_html(sprintf(
                    /* translators: %s: discount code */
                    __('Discount (%s)', 'kontor-shop'),
                    (string) $order->discount_code
                )) . (is_string($code_url) && $code_url !== '' ? ' <a href="' . esc_url($code_url) . '">' . esc_html__('View code', 'kontor-shop') . '</a>' : '') . '</td><td>&minus;'
                    . esc_html(number_format_i18n($order->discount_total, 2)) . ' €</td></tr>';
            }
            echo '<tr><td colspan="6" class="ks-right">' . esc_html__('Shipping', 'kontor-shop') . '</td><td>' . esc_html(number_format_i18n($order->shipping_cost, 2)) . ' €</td></tr>';
            echo '<tr><td colspan="6" class="ks-right"><strong>' . esc_html__('Total', 'kontor-shop') . '</strong></td><td><strong>' . esc_html(number_format_i18n($order->total, 2)) . ' €</strong></td></tr>';

            foreach (TaxCalculator::breakdown(array_merge($tax_lines, $order->shipping_tax)) as $group) {
                echo '<tr><td colspan="6" class="ks-right ks-muted">' . esc_html(sprintf(
                    /* translators: %s: tax rate */
                    __('included VAT %s %% (incl. share of shipping)', 'kontor-shop'),
                    number_format_i18n($group['rate'], 2)
                )) . '</td><td class="ks-muted">' . esc_html(number_format_i18n($group['vat'], 2)) . ' €</td></tr>';
            }

            echo '</tfoot>';
        }

        echo '</table>';

        $this->render_shipments($shipments, $wh_names);

        /**
         * Aktion kontor_shop/admin/order_detail/documents: Belege zur
         * Bestellung ausgeben (z. B. Rechnungen und Gutschriften,
         * Modules\Invoices). $full = Benutzer darf Zahlungen buchen.
         *
         * @param Order $order
         * @param bool  $full
         */
        do_action('kontor_shop/admin/order_detail/documents', $order, $full);

        // ---- Aktionen
        $allowed  = $can_manage ? null : $my_warehouse_ids;
        $can_ship = $can_ship && $order->can_ship() && $fulfilment->open_items_for($allowed) !== [];

        $returnable = $full && $order->status !== OrderStatus::CANCELLED ? ItemReturn::returnable_items($fulfilment) : [];

        $this->render_actions($order, $can_manage, $can_pay, $can_ship, $full, $fulfilment->is_partial(), $full && $fulfilment->can_cancel_order(), $returnable !== []);

        if ($returnable !== []) {
            $this->render_return_dialog($order, $fulfilment, $returnable);
        }

        $can_cancel_items = $full && in_array($order->status, [OrderStatus::OPEN, OrderStatus::PAID], true) && $fulfilment->cancellable_items() !== [];

        if ($can_cancel_items) {
            $this->render_cancel_items_dialog($order, $fulfilment->cancellable_items(), count($fulfilment->active_items()));
        }

        if ($can_ship) {
            ShipmentDialog::render($order, $all_items, $shipments, $allowed, $wh_names, $this->detail_url($order, false));
        }

        // ---- Statusverlauf
        if ($full) {
            $this->render_log((int) $order->id);
        }

        echo '</div>';
    }

    /**
     * @param array<int, Shipment> $shipments
     * @param array<int, string> $wh_names
     */
    private function render_shipments(array $shipments, array $wh_names): void
    {
        if ($shipments === []) {
            return;
        }

        echo '<h2>' . esc_html__('Shipments', 'kontor-shop') . '</h2>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Date', 'kontor-shop') . '</th><th>' . esc_html__('Warehouse', 'kontor-shop')
            . '</th><th>' . esc_html__('Carrier / tracking', 'kontor-shop') . '</th><th>' . esc_html__('Note', 'kontor-shop') . '</th><th>' . esc_html__('By', 'kontor-shop') . '</th></tr></thead><tbody>';

        foreach ($shipments as $shipment) {
            $url      = $shipment->tracking_url();
            $tracking = $shipment->type === Shipment::TYPE_PICKED_UP
                ? esc_html__('picked up', 'kontor-shop')
                : esc_html(Carrier::label($shipment->carrier) ?: '—')
                    . ($shipment->tracking_number !== null
                        ? ' · ' . ($url !== '' ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($shipment->tracking_number) . '</a>' : esc_html($shipment->tracking_number))
                        : '');

            echo '<tr><td>' . esc_html(mysql2date('d.m.Y H:i', (string) $shipment->created_at)) . '</td><td>' . esc_html($wh_names[$shipment->warehouse_id ?? 0] ?? '—')
                . '</td><td>' . $tracking . '</td><td>' . esc_html($shipment->note ?? '') . '</td><td>' . esc_html($this->user_name($shipment->user_id)) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $tracking ist mit esc_html/esc_url gebaut
        }

        echo '</tbody></table>';
    }

    /**
     * Dialog "Artikel stornieren": einzelne, noch nicht versendete Positionen.
     *
     * @param array<int, OrderItem> $cancellable
     */
    private function render_cancel_items_dialog(Order $order, array $cancellable, int $active_count): void
    {
        $id      = 'ks-cancel-items-' . (int) $order->id;
        $paid    = ItemCancellation::is_paid($order);
        $max     = max(0.0, round($order->total - $order->refund_total, 2));

        echo '<dialog class="ks-dialog" id="' . esc_attr($id) . '" aria-labelledby="' . esc_attr($id) . '-title">';
        echo '<form method="post" action="' . esc_url($this->detail_url($order, false)) . '" class="ks-dialog__form" data-ks-cancel-items data-shipping="' . esc_attr((string) $order->shipping_cost)
            . '" data-active="' . esc_attr((string) $active_count) . '" data-max="' . esc_attr((string) $max) . '">';
        wp_nonce_field(OrdersPage::NONCE_ACTION, 'kontor_shop_order_nonce');
        echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $order->id) . '" />';
        echo '<input type="hidden" name="order_action" value="cancel_items" />';

        /* translators: %s: order number */
        echo '<header class="ks-dialog__head"><h2 id="' . esc_attr($id) . '-title">' . esc_html(sprintf(__('Cancel products – %s', 'kontor-shop'), $order->order_number)) . '</h2>'
            . '<button type="button" class="ks-dialog__close" data-ks-dialog-close aria-label="' . esc_attr__('Close', 'kontor-shop') . '">&times;</button></header>';

        echo '<div class="ks-dialog__body">';
        echo '<p class="description">' . esc_html($paid
            ? __('The order is paid: the goods value of the cancelled products will be noted as a refund to the customer. Products that have already shipped can’t be cancelled here.', 'kontor-shop')
            : __('The order is not paid yet: the amount due is reduced by the goods value of the cancelled products. Products that have already shipped can’t be cancelled here.', 'kontor-shop')) . '</p>';

        echo '<ul class="ks-ship-items">';

        foreach ($cancellable as $item) {
            // Mit Rabattcode zaehlt der bezahlte Wert der Position (nach ihrem Rabattanteil).
            echo '<li><label><input type="checkbox" name="cancel_items[]" value="' . esc_attr((string) $item->id) . '" data-amount="' . esc_attr((string) $item->paid_total()) . '" /> '
                . esc_html($item->quantity . ' × ' . $item->product_name . ' (' . number_format_i18n($item->paid_total(), 2) . ' €'
                    . ($item->discount > 0 ? ' ' . __('after discount', 'kontor-shop') : '') . ')') . '</label>';
            echo '<br /><select name="item_' . esc_attr((string) $item->id) . '" aria-label="' . esc_attr__('Stock', 'kontor-shop') . '" class="ks-item-stock-select">';
            echo '<option value="restock">' . esc_html__('Goods back to warehouse', 'kontor-shop') . '</option>';
            echo '<option value="defective">' . esc_html__('not returned: defective / damaged', 'kontor-shop') . '</option>';
            echo '<option value="stolen">' . esc_html__('not returned: lost / stolen', 'kontor-shop') . '</option>';
            echo '<option value="none">' . esc_html__('no stock booking', 'kontor-shop') . '</option>';
            echo '</select></li>';
        }

        echo '</ul>';

        echo '<p><label for="' . esc_attr($id) . '-reason">' . esc_html__('Reason (appears in the email to the customer)', 'kontor-shop') . '</label><br />';
        echo '<input type="text" id="' . esc_attr($id) . '-reason" name="cancel_reason" class="large-text" placeholder="' . esc_attr__('e.g. sorry, sold out / no longer available', 'kontor-shop') . '" /></p>';

        if ($paid) {
            echo '<p><label for="' . esc_attr($id) . '-refund">' . esc_html__('Refund to the customer', 'kontor-shop') . '</label><br />';
            echo '<input type="text" inputmode="decimal" id="' . esc_attr($id) . '-refund" name="refund_amount" class="small-text" value="0,00" data-ks-refund /> € ';
            echo '<span class="description">' . esc_html__('Suggestion = goods value of the selected products (if all products are cancelled, incl. shipping). You can adjust it.', 'kontor-shop') . '</span></p>';
        } else {
            echo '<p class="description" data-ks-new-total="' . esc_attr((string) $order->total) . '">' . esc_html__('New amount due:', 'kontor-shop') . ' <strong data-ks-new-total-value>'
                . esc_html(number_format_i18n($order->total, 2)) . ' €</strong></p>';
        }

        echo '<p><label><input type="checkbox" name="notify_customer" value="1" checked /> ' . esc_html__('Notify customer by email', 'kontor-shop') . '</label></p>';
        echo '</div>';

        echo '<footer class="ks-dialog__foot"><button type="button" class="button" data-ks-dialog-close>' . esc_html__('Cancel', 'kontor-shop') . '</button> '
            . '<button type="submit" class="button button-primary" data-ks-confirm-click="' . esc_attr__('Really cancel the selected products?', 'kontor-shop') . '">'
            . esc_html__('Cancel selected products', 'kontor-shop') . '</button></footer>';
        echo '</form></dialog>';
    }

    /**
     * Dialog "Retoure erfassen": je versendeter Position die zurueckgekommene
     * Stueckzahl und was mit der Ware passiert; Rueckzahlung (bezahlt) bzw.
     * neuer Rechnungsbetrag (unbezahlt) wird live vorgeschlagen (admin.js).
     *
     * @param array<int, OrderItem> $returnable
     */
    private function render_return_dialog(Order $order, Fulfilment $fulfilment, array $returnable): void
    {
        $id   = 'ks-return-' . (int) $order->id;
        $paid = $order->is_paid();
        $max  = max(0.0, round($order->total - $order->refund_total, 2));

        // Stueck, die insgesamt noch zurueckkommen koennen - sind alle gewaehlt und nichts mehr offen, wird der Versand mit erstattet.
        $remaining = $fulfilment->open_items() === []
            ? array_sum(array_map(static fn (OrderItem $item): int => $item->quantity - $item->returned_qty, $fulfilment->active_items()))
            : -1;

        echo '<dialog class="ks-dialog" id="' . esc_attr($id) . '" aria-labelledby="' . esc_attr($id) . '-title">';
        echo '<form method="post" action="' . esc_url($this->detail_url($order, false)) . '" class="ks-dialog__form" data-ks-return data-shipping="' . esc_attr((string) $order->shipping_cost)
            . '" data-remaining="' . esc_attr((string) $remaining) . '" data-max="' . esc_attr((string) $max) . '">';
        wp_nonce_field(OrdersPage::NONCE_ACTION, 'kontor_shop_order_nonce');
        echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $order->id) . '" />';
        echo '<input type="hidden" name="order_action" value="return_items" />';

        /* translators: %s: order number */
        echo '<header class="ks-dialog__head"><h2 id="' . esc_attr($id) . '-title">' . esc_html(sprintf(__('Record return – %s', 'kontor-shop'), $order->order_number)) . '</h2>'
            . '<button type="button" class="ks-dialog__close" data-ks-dialog-close aria-label="' . esc_attr__('Close', 'kontor-shop') . '">&times;</button></header>';

        echo '<div class="ks-dialog__body">';
        echo '<p class="description">' . esc_html($paid
            ? __('The order is paid: the goods value of the returned products will be noted as a refund to the customer. If everything comes back, shipping is included.', 'kontor-shop')
            : __('The invoice is not paid yet: the open amount is reduced by the goods value of the returned products. If everything comes back, shipping is dropped too.', 'kontor-shop')) . '</p>';

        echo '<ul class="ks-ship-items">';

        foreach ($returnable as $item) {
            $left  = ItemReturn::returnable_quantity($item, $fulfilment);
            $field = $id . '-qty-' . (int) $item->id;

            $unit_paid = round($item->paid_total() / max(1, $item->quantity), 2);

            echo '<li><label for="' . esc_attr($field) . '">' . esc_html($item->product_name . ' (' . number_format_i18n($unit_paid, 2) . ' €/Stk.'
                . ($item->discount > 0 ? ' ' . __('after discount', 'kontor-shop') : '') . ')') . '</label><br />';
            echo '<input type="number" id="' . esc_attr($field) . '" name="return_qty[' . esc_attr((string) $item->id) . ']" min="0" max="' . esc_attr((string) $left) . '" value="0" class="small-text" inputmode="numeric" data-ks-return-qty data-unit="'
                . esc_attr((string) $unit_paid) . '" /> ' . esc_html(sprintf(
                    /* translators: 1: units still returnable, 2: delivered */
                    __('of %1$d pcs. back (delivered: %2$d)', 'kontor-shop'),
                    $left,
                    $item->quantity
                ));
            echo '<br /><select name="return_stock_' . esc_attr((string) $item->id) . '" aria-label="' . esc_attr__('Stock', 'kontor-shop') . '" class="ks-gap-top">';
            echo '<option value="restock">' . esc_html__('Goods OK: back to warehouse', 'kontor-shop') . '</option>';
            echo '<option value="defective">' . esc_html__('defective / damaged (not sellable)', 'kontor-shop') . '</option>';
            echo '<option value="stolen">' . esc_html__('did not arrive / lost', 'kontor-shop') . '</option>';
            echo '<option value="none">' . esc_html__('no stock booking', 'kontor-shop') . '</option>';
            echo '</select></li>';
        }

        echo '</ul>';

        echo '<p><label for="' . esc_attr($id) . '-reason">' . esc_html__('Note (appears in the email to the customer)', 'kontor-shop') . '</label><br />';
        echo '<input type="text" id="' . esc_attr($id) . '-reason" name="return_reason" class="large-text" placeholder="' . esc_attr__('e.g. withdrawal, doesn’t fit, exchange', 'kontor-shop') . '" /></p>';

        if ($paid) {
            echo '<p><label for="' . esc_attr($id) . '-refund">' . esc_html__('Refund to the customer', 'kontor-shop') . '</label><br />';
            echo '<input type="text" inputmode="decimal" id="' . esc_attr($id) . '-refund" name="refund_amount" class="small-text" value="0,00" data-ks-refund /> € ';
            echo '<span class="description">' . esc_html__('Suggestion = goods value of the return (if everything comes back: incl. shipping). You can adjust it, e.g. for reduced value; 0 = no refund.', 'kontor-shop') . '</span></p>';
        } else {
            echo '<p class="description" data-ks-new-total="' . esc_attr((string) $order->total) . '">' . esc_html__('New invoice amount:', 'kontor-shop') . ' <strong data-ks-new-total-value>'
                . esc_html(number_format_i18n($order->total, 2)) . ' €</strong></p>';
        }

        echo '<p><label><input type="checkbox" name="notify_customer" value="1" checked /> ' . esc_html__('Notify customer by email (“Return received”)', 'kontor-shop') . '</label></p>';
        echo '</div>';

        echo '<footer class="ks-dialog__foot"><button type="button" class="button" data-ks-dialog-close>' . esc_html__('Cancel', 'kontor-shop') . '</button> '
            . '<button type="submit" class="button button-primary" data-ks-confirm-click="' . esc_attr__('Book the return now?', 'kontor-shop') . '">'
            . esc_html__('Book return', 'kontor-shop') . '</button></footer>';
        echo '</form></dialog>';
    }

    private function render_actions(Order $order, bool $can_manage, bool $can_pay, bool $can_ship, bool $full, bool $partial = false, bool $can_cancel = false, bool $can_return = false): void
    {
        echo '<h2>' . esc_html__('Actions', 'kontor-shop') . '</h2><div class="ks-actions ks-actions--center">';

        // Zahlungen bucht die fuehrende Kassa (Gegenstelle) - hier nur fuer WP-Admins als Notausgang (unten).
        $owned = ExternalPayments::active();

        if ($can_pay && !$owned) {
            $this->action_form($order, 'mark_paid', __('Mark as paid', 'kontor-shop'));
        }

        if ($full && PaymentReminder::applies($order)) {
            $this->action_form($order, 'payment_reminder', __('Send payment reminder', 'kontor-shop'), sprintf(
                /* translators: %s: email */
                __('Send the payment reminder to %s now?', 'kontor-shop'),
                $order->customer_email
            ));
        }

        // Nach dem Versand (auch teilweise) kein Storno mehr - nur noch Retoure.
        if ($can_cancel) {
            echo '<a class="button" href="' . esc_url(OrdersPage::url(['cancel_order' => (int) $order->id])) . '">' . esc_html__('Cancel order', 'kontor-shop') . '</a>';
        }

        if ($can_ship && $order->self_pickup) {
            $this->action_form($order, 'ready_for_pickup', __('Ready for pickup', 'kontor-shop'), sprintf(
                /* translators: %s: email */
                __('Send %s the email “Ready for pickup” now?', 'kontor-shop'),
                $order->customer_email
            ));
        }

        if ($can_ship) {
            echo ShipmentDialog::open_button($order, $partial); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ShipmentDialog::open_button() liefert escaptes HTML
        }

        if ($full && in_array($order->status, [OrderStatus::OPEN, OrderStatus::PAID], true)
            && (new Fulfilment($order, $this->orders->items_for_order((int) $order->id)))->cancellable_items() !== []) {
            echo '<button type="button" class="button" data-ks-dialog-open="ks-cancel-items-' . (int) $order->id . '">' . esc_html__('Cancel products …', 'kontor-shop') . '</button>';
        }

        // Nach dem Versand: Retoure statt Storno.
        if ($can_return) {
            echo '<button type="button" class="button" data-ks-dialog-open="ks-return-' . (int) $order->id . '">' . esc_html__('Record return …', 'kontor-shop') . '</button>';
        }

        if ($full && $order->refund_pending() > 0 && !$owned) {
            /* translators: %s: refund amount */
            $this->action_form($order, 'refund_done', sprintf(__('Refund %s € done', 'kontor-shop'), number_format_i18n($order->refund_pending(), 2)), __('Did you transfer the amount back to the customer?', 'kontor-shop'), true);
        }

        // Korrektur: versehentlich als bezahlt markiert (nur solange nichts raus ist).
        if ($full && !$owned && $order->status === OrderStatus::PAID
            && !(new Fulfilment($order, $this->orders->items_for_order((int) $order->id)))->has_shipped_items()) {
            $this->action_form($order, 'undo_paid', __('Undo payment', 'kontor-shop'), __('Set this order back to “open”? Use this if it was marked as paid by mistake. The customer is not notified.', 'kontor-shop'));
        }

        if ($can_manage) {
            $this->action_form($order, 'delete', __('Delete order', 'kontor-shop'), __('Permanently delete this order? Products that have not been shipped go back to the warehouse. This can’t be undone.', 'kontor-shop'));
        }

        echo '</div>';

        if ($owned && current_user_can('manage_options') && ($can_pay || ($full && $order->refund_pending() > 0))) {
            $this->render_owner_override($order, $can_pay);
        }

        if (!$full) {
            return;
        }

        echo '<h3>' . esc_html__('Confirmation email', 'kontor-shop') . '</h3>';
        echo '<div class="ks-actions ks-actions--wide">';

        /* translators: %s: customer email address */
        $this->action_form($order, 'resend_confirmation', sprintf(__('Resend to %s', 'kontor-shop'), $order->customer_email));

        echo '<form method="post" action="' . esc_url($this->detail_url($order, false)) . '" class="ks-inline-form ks-inline-form--tight">';
        wp_nonce_field(OrdersPage::NONCE_ACTION, 'kontor_shop_order_nonce');
        echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $order->id) . '" />';
        echo '<input type="hidden" name="order_action" value="resend_confirmation" />';
        echo '<input type="email" name="resend_to" required class="regular-text" placeholder="' . esc_attr__('other email address', 'kontor-shop') . '" />';
        echo '<button type="submit" class="button">' . esc_html__('Forward to this address', 'kontor-shop') . '</button>';
        echo '</form></div>';
    }

    private function render_log(int $order_id): void
    {
        echo '<h2>' . esc_html__('Status history', 'kontor-shop') . '</h2>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Time', 'kontor-shop') . '</th><th>' . esc_html__('Status', 'kontor-shop')
            . '</th><th>' . esc_html__('By', 'kontor-shop') . '</th><th>' . esc_html__('Note', 'kontor-shop') . '</th></tr></thead><tbody>';

        $labels = OrderStatus::labels();

        foreach ($this->orders->status_log($order_id) as $entry) {
            $user = !empty($entry['user_id']) ? get_userdata((int) $entry['user_id']) : false;
            $by   = !empty($entry['actor']) && !in_array($entry['actor'], [PaymentReminder::LOG_ACTOR, PaymentReminder::FINAL_LOG_ACTOR], true)
                ? (string) $entry['actor']
                : ($user ? $user->display_name : (!empty($entry['actor']) ? __('System', 'kontor-shop') : '—'));

            echo '<tr><td>' . esc_html((string) $entry['created_at']) . '</td><td>' . esc_html($labels[(string) $entry['to_status']] ?? (string) $entry['to_status'])
                . '</td><td>' . esc_html($by) . '</td><td>' . esc_html((string) ($entry['note'] ?? '')) . '</td></tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Hinweis „Zahlungen werden im <Gegenstelle> gebucht“ mit Link zur Bestellung in der Kassa der Gegenstelle ('' = keine fuehrende Kassa).
     */
    public static function owner_notice_html(string $reference, ?Order $order = null): string
    {
        $owner = ExternalPayments::owner();

        if ($owner === null) {
            return '';
        }

        $url = ExternalPayments::order_url($reference, $order);

        return '<div class="notice notice-info inline ks-owner-notice"><p><strong>' . esc_html(sprintf(
            /* translators: %s: name of the counterpart system, e.g. club treasury */
            __('Payments are booked in %s.', 'kontor-shop'),
            $owner['label']
        )) . '</strong> ' . esc_html__('As soon as the cashier books it there, the order shows as “paid” here and can be shipped.', 'kontor-shop')
            . ($url !== '' ? ' <a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html__('Open in the cashier system ↗', 'kontor-shop') . '</a>' : '')
            . '</p></div>';
    }

    /**
     * Notausgang fuer WP-Admins, falls die fuehrende Kassa nicht erreichbar ist (docs/API_Kassen-Schnittstelle.md).
     */
    private function render_owner_override(Order $order, bool $can_pay): void
    {
        $owner = ExternalPayments::owner();
        $label = $owner['label'] ?? __('Counterpart system', 'kontor-shop');

        echo '<details class="ks-owner-override"><summary>' . esc_html(sprintf(
            /* translators: %s: name of the counterpart system */
            __('%s not reachable? Book here anyway …', 'kontor-shop'),
            $label
        )) . '</summary><p class="description">' . esc_html(sprintf(
            /* translators: %s: name of the counterpart system */
            __('Normally the cashier books in %s. A booking made here appears there in the history as “booked in shop”.', 'kontor-shop'),
            $label
        )) . '</p><div class="ks-actions">';

        if ($can_pay) {
            /* translators: %s: order total */
            $this->action_form($order, 'mark_paid', sprintf(__('Book as paid here anyway (%s €)', 'kontor-shop'), number_format_i18n($order->total, 2)),
                __('Really book as paid in the shop, even though payments are otherwise booked in the leading cashier system?', 'kontor-shop'), false, ['owner_override' => '1']);
        }

        if ($order->refund_pending() > 0) {
            /* translators: %s: refund amount */
            $this->action_form($order, 'refund_done', sprintf(__('Here anyway: refund %s € done', 'kontor-shop'), number_format_i18n($order->refund_pending(), 2)),
                __('Really book as refunded in the shop, even though payments are otherwise booked in the leading cashier system?', 'kontor-shop'), false, ['owner_override' => '1']);
        }

        echo '</div></details>';
    }

    /** @param array<string, string> $extra zusaetzliche versteckte Felder */
    private function action_form(Order $order, string $action, string $label, ?string $confirm = null, bool $primary = false, array $extra = []): void
    {
        $confirm_attr = $confirm !== null ? ' data-ks-confirm="' . esc_attr($confirm) . '"' : '';

        echo '<form method="post" action="' . esc_url($this->detail_url($order, $action === 'delete')) . '" class="ks-inline-form ks-inline-form--tight"' . $confirm_attr . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $confirm_attr ist mit esc_attr gebaut
        wp_nonce_field(OrdersPage::NONCE_ACTION, 'kontor_shop_order_nonce');
        echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $order->id) . '" />';
        echo '<input type="hidden" name="order_action" value="' . esc_attr($action) . '" />';

        foreach ($extra as $name => $value) {
            echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" />';
        }

        echo '<button type="submit" class="button' . ($primary ? ' button-primary' : '') . '">' . esc_html($label) . '</button>';
        echo '</form>';
    }

    /**
     * Nach der Aktion wieder auf die Detailseite; nach dem Loeschen auf die Liste.
     */
    private function detail_url(Order $order, bool $to_list): string
    {
        return $to_list
            ? OrdersPage::list_url()
            : OrdersPage::url(['order' => (int) $order->id]);
    }

    private function row(string $label, string $html): void
    {
        echo '<tr><th class="ks-th-38">' . esc_html($label) . '</th><td>' . $html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Aufrufer uebergeben escaptes HTML (esc_html/Badge)
    }

    private function user_name(?int $user_id): string
    {
        $user = $user_id ? get_userdata($user_id) : false;

        return $user ? (string) $user->display_name : '—';
    }

    /**
     * Ueber die Kassen-Schnittstelle gebucht: " (Maria Muster über Vereinskassa, bar)".
     */
    private function paid_via(Order $order): string
    {
        /**
         * Filter kontor_shop/orders/channel_label: lesbarer Name des Buchungswegs
         * (orders.paid_via, z. B. "api:ab12cd34" -> "Vereinskassa").
         * Standard: der gespeicherte Wert unveraendert.
         *
         * @param string $label   Anzeigename
         * @param string $channel gespeicherter Buchungsweg
         * @param Order  $order   die Bestellung
         */
        $via   = (string) apply_filters('kontor_shop/orders/channel_label', (string) $order->paid_via, (string) $order->paid_via, $order);
        /* translators: %s: name of the connected system */
        $parts = [($order->paid_by_name !== null ? $order->paid_by_name . ' ' : '') . sprintf(__('via %s', 'kontor-shop'), $via)];

        if ($order->paid_with !== null) {
            $parts[] = PaidWith::labels()[$order->paid_with] ?? $order->paid_with;
        }

        return ' <small>(' . esc_html(implode(', ', $parts)) . ')</small>';
    }

    private function by(?int $user_id): string
    {
        $user = $user_id ? get_userdata($user_id) : false;

        return $user ? ' <small>(' . esc_html($user->display_name) . ')</small>' : '';
    }
}
