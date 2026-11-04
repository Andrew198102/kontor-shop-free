<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin;

use Kontor\Shop\Infrastructure\Orders\ExternalPayments;
use Kontor\Shop\Admin\Ui\Css;
use Kontor\Shop\Admin\Ui\Badge;
use Kontor\Shop\Admin\Ui\Kpis;
use Kontor\Shop\Admin\Ui\ListParams;
use Kontor\Shop\Admin\Ui\Views;
use Kontor\Shop\Admin\Ui\Pagination;
use Kontor\Shop\Domain\Order\Carrier;
use Kontor\Shop\Domain\Order\ItemReturn;
use Kontor\Shop\Domain\Order\Order;
use Kontor\Shop\Domain\Order\OrderItem;
use Kontor\Shop\Domain\Order\OrderStatus;
use Kontor\Shop\Domain\Order\PaidWith;
use Kontor\Shop\Domain\Order\PaymentTerms;
use Kontor\Shop\Domain\Order\PaymentReminder;
use Kontor\Shop\Domain\Order\WorkQueue;
use Kontor\Shop\Infrastructure\Notifications\MailHealth;
use Kontor\Shop\Infrastructure\Repositories\OrderRepository;
use Kontor\Shop\Infrastructure\Repositories\WarehouseRepository;
use Kontor\Shop\Admin\Ui\Flash;
use Kontor\Shop\Support\AddressFormatter;

/**
 * Bestellliste als Arbeitsplatz (docs/PLAN_bestellliste.md): Kennzahlen,
 * Arbeits-Ansichten (Domain\Order\WorkQueue), kompakte Zeilen mit Artikeln
 * (Akkordeon), Fortschritt und Zahlungsstand, Schnellansicht, ein Hauptknopf
 * + Menue je Zeile. Alle sichtbaren Bestellungen werden samt Positionen in
 * zwei Abfragen geladen; gefiltert, sortiert und geblaettert wird in PHP (ein
 * Vereinsshop hat Hunderte, nicht Hunderttausende Bestellungen). Die Aktionen
 * selbst (bezahlt, Versand, Erinnerung, Notiz …) fuehrt OrdersPage aus.
 */
final class OrdersList
{
    private const HISTORY = 6;

    /** @var array<int, array{name: string, color: string}>|null */
    private ?array $warehouse_info = null;

    /** @var array<int, Order> Bestellungen, deren Versand-Dialog nach der Liste ausgegeben wird */
    private array $dialogs = [];

    public function __construct(
        private readonly OrderRepository $orders,
        private readonly WarehouseRepository $warehouses,
        private readonly PaymentTerms $terms,
    ) {
    }

    /* ==================== Daten ==================== */

    /**
     * Sichtbare Bestellungen samt Positionen und Arbeitsstand.
     *
     * @param array{q?: string, from?: string} $filters
     * @param array<int, int>|null $scope Lager des Benutzers (null = alle Bestellungen sichtbar)
     * @return array<int, WorkQueue>
     */
    public function load(array $filters, ?array $scope): array
    {
        $orders = $this->orders->search($filters);
        $items  = $this->orders->items_for_orders(array_map(static fn (Order $o): int => (int) $o->id, $orders));
        $today  = current_time('Y-m-d');
        $result = [];

        foreach ($orders as $order) {
            $order_items = $items[(int) $order->id] ?? [];

            if ($scope !== null && array_filter($order_items, static fn (OrderItem $i): bool => in_array($i->warehouse_id, $scope, true)) === []) {
                continue;
            }

            $result[] = new WorkQueue($order, $order_items, $this->terms, $today, $scope);
        }

        return $result;
    }

    /**
     * @return array{view: string, q: string, range: string, ship: string, warehouse_id: int, member: string, orderby: string, order: string, ids: array<int, int>}
     */
    private function params(bool $is_cashier_only, bool $is_warehouse_only, array $all): array
    {
        $view = ListParams::key('view');
        $ids  = self::ids_param();

        if ($ids !== [] && !in_array($view, WorkQueue::views(), true)) {
            $view = WorkQueue::VIEW_ALL;
        }

        if (!in_array($view, WorkQueue::views(), true)) {
            $count = static fn (string $v): int => count(array_filter($all, static fn (WorkQueue $q): bool => $q->matches($v)));
            $view  = match (true) {
                $is_cashier_only && $count(WorkQueue::VIEW_PAY) > 0         => WorkQueue::VIEW_PAY,
                $is_warehouse_only && $count(WorkQueue::VIEW_SHIP) + $count(WorkQueue::VIEW_PICKUP) > 0 => $count(WorkQueue::VIEW_SHIP) > 0 ? WorkQueue::VIEW_SHIP : WorkQueue::VIEW_PICKUP,
                default                                                   => WorkQueue::VIEW_TODO,
            };
        }

        $range = ListParams::key('range');

        return [
            'view'         => $view,
            'q'            => ListParams::text('q'),
            'range'        => in_array($range, ['today', '7', '30', '90'], true) ? $range : '',
            'ship'         => in_array(ListParams::key('ship'), ['ship', 'pickup'], true) ? ListParams::key('ship') : '',
            'warehouse_id' => max(0, ListParams::int('warehouse_id')),
            'member'       => in_array(ListParams::key('member'), ['1', '0'], true) ? ListParams::key('member') : '',
            'orderby'      => ListParams::orderby(OrdersPage::SORTABLE, ''),
            'order'        => ListParams::order('desc'),
            'ids'          => $ids,
        ];
    }

    /**
     * Direkt-Filter aus Uebersicht/Widget: nur diese Bestellungen (?ids=1,2,3).
     *
     * @return array<int, int>
     */
    public static function ids_param(): array
    {
        return array_values(array_filter(array_map('absint', explode(',', ListParams::text('ids')))));
    }

    private static function range_from(string $range): string
    {
        return match ($range) {
            'today' => current_time('Y-m-d'),
            '7', '30', '90' => wp_date('Y-m-d', strtotime(current_time('Y-m-d') . ' -' . $range . ' days')),
            default => '',
        };
    }

    /* ==================== Liste ==================== */

    /**
     * @param array<int, int> $my_warehouse_ids
     */
    public function render(bool $can_manage, bool $is_cashier, array $my_warehouse_ids): void
    {
        $scope    = $can_manage || $is_cashier ? null : $my_warehouse_ids;
        $can_pay  = $can_manage || $is_cashier;
        $can_ship = $can_manage || $my_warehouse_ids !== [];
        $all      = $this->load([], $scope);
        $p        = $this->params($is_cashier && !$can_manage && $my_warehouse_ids === [], !$can_manage && !$is_cashier && $my_warehouse_ids !== [], $all);
        $filtered = $p['q'] !== '' || $p['range'] !== '' ? $this->load(['q' => $p['q'], 'from' => self::range_from($p['range'])], $scope) : $all;
        $filtered = array_values(array_filter($filtered, fn (WorkQueue $q): bool => $this->passes($q, $p)
            && ($p['ids'] === [] || in_array((int) $q->order->id, $p['ids'], true))));
        $rows     = array_values(array_filter($filtered, static fn (WorkQueue $q): bool => $q->matches($p['view'])));
        $rows     = $this->sort($rows, $p);
        $per_page = ListParams::per_page();
        $page     = ListParams::page();
        $base     = ['page' => OrdersPage::SLUG];
        $keep     = [
            'view'         => $p['view'],
            'q'            => $p['q'],
            'range'        => $p['range'],
            'ship'         => $p['ship'],
            'warehouse_id' => $p['warehouse_id'] ?: '',
            'member'       => $p['member'],
            'orderby'      => $p['orderby'],
            'order'        => $p['orderby'] !== '' ? $p['order'] : '',
            'ids'          => implode(',', $p['ids']),
        ];
        $page_rows = array_slice($rows, ($page - 1) * $per_page, $per_page);
        $to_pack   = count(array_filter($all, static fn (WorkQueue $q): bool => $q->to_ship() || $q->to_pickup()));
        $pack_all  = $can_ship ? OrdersPage::pack_url(['pack' => 'all']) : '';
        $pack_sel  = $can_ship ? OrdersPage::pack_url(['pack' => 'sel']) : '';
        $context   = [
            'can_manage' => $can_manage,
            'can_pay'    => $can_pay,
            'can_ship'   => $can_ship,
            'q'          => $p['q'],
            'from'       => self::range_from($p['range']),
        ];

        echo '<div class="wrap ks-wrap ks-orders-wrap">';
        echo '<div class="ks-stock-head"><h1 class="wp-heading-inline">' . esc_html__('Orders', 'kontor-shop') . '</h1>';

        if ($pack_all !== '') {
            echo '<a class="button' . ($to_pack > 0 ? ' button-primary' : '') . '" href="' . esc_url($pack_all) . '">' . esc_html__('Pack', 'kontor-shop')
                . ($to_pack > 0 ? ' <span class="ks-count-pill">' . (int) $to_pack . '</span>' : '') . '</a>';
        }

        echo '<span class="ks-stock-head__spacer"></span>';

        /**
         * Aktion kontor_shop/orders/toolbar: weitere Knoepfe rechts im Kopf
         * der Bestellliste, z. B. Pro: "CSV exportieren". Ausgabe als HTML
         * (escapen!).
         *
         * @param array{can_manage: bool, can_pay: bool, can_ship: bool, q: string, from: string} $context
         *        Rechte des Benutzers, Suchbegriff und Zeitraum-Beginn (Y-m-d, '' = alles) der Liste
         */
        do_action('kontor_shop/orders/toolbar', $context);

        echo '</div><hr class="wp-header-end" />';

        // Nur der rote Fall (Mailversand kaputt) als Banner - alles andere zeigt die Ampel der Uebersicht.
        echo MailHealth::notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MailHealth::notice_html() liefert escaptes HTML
        Flash::render();

        $this->render_kpis($all, $p['view'], $can_ship);
        $this->render_views($filtered, $p['view'], $keep);
        $this->render_filters($p, $can_manage);

        if ($p['ids'] !== []) {
            echo '<p class="ks-direct-filter">' . esc_html(sprintf(
                /* translators: %d: count */
                _n('Filtered to %d order from the dashboard.', 'Filtered to %d orders from the dashboard.', count($p['ids']), 'kontor-shop'),
                count($p['ids'])
            )) . ' <a href="' . esc_url(OrdersPage::url()) . '">' . esc_html__('Show all orders', 'kontor-shop') . '</a></p>';
        }

        echo '<div class="ks-stock-bulk" data-ks-order-bulk hidden><strong data-ks-bulk-count></strong>';

        if ($can_pay && !ExternalPayments::active()) {
            echo '<button type="button" class="button button-small" data-ks-order-bulk-action="mark_paid">' . esc_html__('Mark as paid', 'kontor-shop') . '</button>';
        }

        if ($can_ship) {
            echo '<button type="button" class="button button-small" data-ks-order-bulk-action="ship">' . esc_html__('Mark as shipped/picked up', 'kontor-shop') . '</button>';
        }

        if ($pack_sel !== '') {
            echo '<a class="button button-small" href="' . esc_url($pack_sel) . '" data-ks-order-bulk-link="ids">' . esc_html__('Pack', 'kontor-shop') . '</a>';
        }

        /**
         * Aktion kontor_shop/orders/bulk_actions: weitere Knoepfe in der
         * Auswahl-Leiste der Bestellliste, z. B. Pro: "CSV der Auswahl".
         * Links mit data-ks-order-bulk-link="ids" bekommen die gewaehlten
         * Bestellungen als &ids=… angehaengt (orders.js). Ausgabe als HTML.
         *
         * @param array{can_manage: bool, can_pay: bool, can_ship: bool, q: string, from: string} $context wie kontor_shop/orders/toolbar
         */
        do_action('kontor_shop/orders/bulk_actions', $context);

        echo '<button type="button" class="button-link" data-ks-order-bulk-action="clear">' . esc_html__('Clear selection', 'kontor-shop') . '</button></div>';

        if ($page_rows === []) {
            echo '<div class="ks-empty"><p><strong>' . esc_html(
                $p['view'] === WorkQueue::VIEW_TODO && $keep['q'] === '' && $p['range'] === ''
                    ? ($all === [] ? __('No orders yet.', 'kontor-shop') : __('All done – nothing to do right now.', 'kontor-shop'))
                    : __('No orders found for this selection.', 'kontor-shop')
            ) . '</strong></p></div>';
        } else {
            echo '<div class="tablenav top">';
            Pagination::render(count($rows), $page, $per_page, $base, $keep);
            Pagination::per_page_form($per_page, $base, $keep);
            echo '<br class="clear" /></div>';

            $ids       = array_map(static fn (WorkQueue $q): int => (int) $q->order->id, $page_rows);
            $reminders = $this->orders->last_log_by_actor($ids, PaymentReminder::LOG_ACTOR);

            echo '<div class="ks-stock-tablewrap"><table class="wp-list-table widefat ks-table ks-orders-table"><thead><tr>';
            echo '<td class="manage-column check-column"><input type="checkbox" data-ks-order-all aria-label="' . esc_attr__('Select all', 'kontor-shop') . '" /></td>';
            echo ListParams::sortable_th(__('Order', 'kontor-shop'), 'number', $p['orderby'], $p['order'], $base, $keep, 'column-primary'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ListParams::sortable_th() liefert escaptes HTML
            echo ListParams::sortable_th(__('Customer', 'kontor-shop'), 'customer', $p['orderby'], $p['order'], $base, $keep); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ListParams::sortable_th() liefert escaptes HTML
            echo '<th class="ks-col-items">' . esc_html__('Product', 'kontor-shop') . '</th>';
            echo '<th>' . esc_html__('Progress', 'kontor-shop') . '</th>';
            echo '<th>' . esc_html__('Payment', 'kontor-shop') . '</th>';
            echo ListParams::sortable_th(__('Total', 'kontor-shop'), 'total', $p['orderby'], $p['order'], $base, $keep, 'ks-col-num'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ListParams::sortable_th() liefert escaptes HTML
            echo '<th class="ks-col-next">' . esc_html__('Next step', 'kontor-shop') . '</th>';
            echo '</tr></thead>';

            foreach ($page_rows as $queue) {
                echo $this->row_html($queue, $can_pay, $can_ship, $can_manage, $my_warehouse_ids, $reminders[(int) $queue->order->id] ?? null); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- row_html() liefert escaptes HTML
            }

            echo '</table></div>';

            echo '<div class="tablenav bottom">';
            Pagination::render(count($rows), $page, $per_page, $base, $keep);
            echo '<br class="clear" /></div>';
        }

        $this->render_shared_forms();
        $this->render_dialogs($can_manage, $my_warehouse_ids);
        echo '</div>';
    }

    /**
     * @param array{view: string, q: string, range: string, ship: string, warehouse_id: int, member: string, orderby: string, order: string} $p
     */
    private function passes(WorkQueue $queue, array $p): bool
    {
        $order = $queue->order;

        if ($p['ship'] === 'ship' && $order->self_pickup || $p['ship'] === 'pickup' && !$order->self_pickup) {
            return false;
        }

        if ($p['member'] !== '' && (int) $order->is_member !== (int) $p['member']) {
            return false;
        }

        return $p['warehouse_id'] === 0 || array_filter($queue->items, static fn (OrderItem $i): bool => $i->warehouse_id === $p['warehouse_id']) !== [];
    }

    /**
     * @param array<int, WorkQueue> $rows
     * @param array{view: string, orderby: string, order: string} $p
     * @return array<int, WorkQueue>
     */
    private function sort(array $rows, array $p): array
    {
        $dir = $p['order'] === 'asc' ? 1 : -1;

        usort($rows, static function (WorkQueue $a, WorkQueue $b) use ($p, $dir): int {
            $by_date = strcmp((string) $b->order->created_at, (string) $a->order->created_at);

            return match ($p['orderby']) {
                'number'   => $dir * strnatcmp($a->order->order_number, $b->order->order_number),
                'customer' => $dir * strcasecmp($a->order->customer_name, $b->order->customer_name),
                'total'    => $dir * ($a->order->total <=> $b->order->total),
                'created'  => -$dir * $by_date,
                default    => $p['view'] === WorkQueue::VIEW_TODO ? (($a->urgency() <=> $b->urgency()) ?: $by_date) : $by_date,
            };
        });

        return $rows;
    }

    /**
     * @param array<int, WorkQueue> $all
     */
    private function render_kpis(array $all, string $view, bool $can_ship): void
    {
        $of    = static fn (string $v): array => array_values(array_filter($all, static fn (WorkQueue $q): bool => $q->matches($v)));
        $pay   = $of(WorkQueue::VIEW_PAY);
        $late  = $of(WorkQueue::VIEW_OVERDUE);
        $ship  = $of(WorkQueue::VIEW_SHIP);
        $pick  = $of(WorkQueue::VIEW_PICKUP);
        $ref   = $of(WorkQueue::VIEW_REFUND);
        $euro  = static fn (float $v): string => number_format_i18n($v, 2) . ' €';
        $pcs   = array_sum(array_map(static fn (WorkQueue $q): int => array_sum(array_map(static fn (OrderItem $i): int => $i->quantity, $q->fulfilment()->open_items())), $ship));

        $tile = static fn (string $v, string $tone, int $n, string $extra, string $label, string $sub, bool $current): string
            => Kpis::tile(OrdersPage::url(['view' => $v]), $n > 0 ? $tone : Kpis::NEUTRAL, (string) $n, $label, $sub, $current, $n > 0 ? $extra : '');

        echo '<div class="ks-kpis">';
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- $tile() ist Kpis::tile() und liefert escaptes HTML
        echo $tile(WorkQueue::VIEW_PAY, 'warn', count($pay), $euro(array_sum(array_map(static fn (WorkQueue $q): float => $q->order->total, $pay))), __('Payment open', 'kontor-shop'), __('not paid yet', 'kontor-shop'), $view === WorkQueue::VIEW_PAY);
        echo $tile(WorkQueue::VIEW_OVERDUE, 'bad', count($late), '', __('overdue', 'kontor-shop'), $this->terms->prepayment_days > 0 || $this->terms->invoice_days > 0
            ? __('Payment deadline or due date passed', 'kontor-shop')
            : __('no payment deadline set', 'kontor-shop'), $view === WorkQueue::VIEW_OVERDUE);
        echo $tile(WorkQueue::VIEW_SHIP, 'info', count($ship), $pcs > 0 ? sprintf(
            /* translators: %d: pieces */
            __('%d pcs.', 'kontor-shop'),
            $pcs
        ) : '', __('To ship', 'kontor-shop'), $can_ship ? __('paid or on invoice, not shipped yet', 'kontor-shop') : __('in progress at the warehouse', 'kontor-shop'), $view === WorkQueue::VIEW_SHIP);
        echo $tile(WorkQueue::VIEW_PICKUP, 'info', count($pick), '', __('Ready for pickup', 'kontor-shop'), __('waiting for pickup', 'kontor-shop'), $view === WorkQueue::VIEW_PICKUP);
        echo $tile(WorkQueue::VIEW_REFUND, 'bad', count($ref), $euro(array_sum(array_map(static fn (WorkQueue $q): float => $q->order->refund_pending(), $ref))), __('Refund pending', 'kontor-shop'), __('Money back to customer', 'kontor-shop'), $view === WorkQueue::VIEW_REFUND);
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</div>';
    }

    /**
     * @param array<int, WorkQueue> $filtered
     * @param array<string, mixed> $keep
     */
    private function render_views(array $filtered, string $current, array $keep): void
    {
        $labels = [
            WorkQueue::VIEW_TODO      => __('To do', 'kontor-shop'),
            WorkQueue::VIEW_PAY       => __('Payment open', 'kontor-shop'),
            WorkQueue::VIEW_SHIP      => __('To ship', 'kontor-shop'),
            WorkQueue::VIEW_PICKUP    => __('Pickup', 'kontor-shop'),
            WorkQueue::VIEW_PARTIAL   => __('Partially out', 'kontor-shop'),
            WorkQueue::VIEW_REFUND    => __('Refund', 'kontor-shop'),
            WorkQueue::VIEW_DONE      => __('Done', 'kontor-shop'),
            WorkQueue::VIEW_CANCELLED => __('Cancelled', 'kontor-shop'),
            WorkQueue::VIEW_ALL       => __('All', 'kontor-shop'),
        ];

        if ($current === WorkQueue::VIEW_OVERDUE) {
            $labels = [WorkQueue::VIEW_OVERDUE => __('Overdue', 'kontor-shop')] + $labels;
        }

        $views = [];

        foreach ($labels as $view => $label) {
            $n = count(array_filter($filtered, static fn (WorkQueue $q): bool => $q->matches($view)));

            if ($n === 0 && in_array($view, [WorkQueue::VIEW_PARTIAL, WorkQueue::VIEW_REFUND, WorkQueue::VIEW_PICKUP], true) && $view !== $current) {
                continue;
            }

            $views[$view] = [
                'label' => $label,
                'url'   => ListParams::url(['page' => OrdersPage::SLUG], array_diff_key($keep, ['view' => 1, 'orderby' => 1, 'order' => 1]), ['view' => $view]),
                'count' => $n,
                'hot'   => $view === WorkQueue::VIEW_TODO,
            ];
        }

        Views::render($views, $current);
    }

    /**
     * @param array{view: string, q: string, range: string, ship: string, warehouse_id: int, member: string} $p
     */
    private function render_filters(array $p, bool $can_manage): void
    {
        echo '<form method="get" class="ks-filter-bar"><input type="hidden" name="page" value="' . esc_attr(OrdersPage::SLUG) . '" />';
        echo '<input type="hidden" name="view" value="' . esc_attr($p['view']) . '" />';
        echo $p['ids'] !== [] ? '<input type="hidden" name="ids" value="' . esc_attr(implode(',', $p['ids'])) . '" />' : '';
        echo '<input type="search" name="q" value="' . esc_attr($p['q']) . '" class="regular-text" placeholder="' . esc_attr__('Number, name, email, reference, or product …', 'kontor-shop')
            . '" aria-label="' . esc_attr__('Search orders', 'kontor-shop') . '" />';

        $select = static function (string $name, string $label, array $options, string $current): string {
            $html = '<select name="' . esc_attr($name) . '" aria-label="' . esc_attr($label) . '">';

            foreach ($options as $value => $text) {
                $html .= '<option value="' . esc_attr((string) $value) . '"' . selected($current, (string) $value, false) . '>' . esc_html($text) . '</option>';
            }

            return $html . '</select>';
        };

        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- $select() escapt alle Werte (esc_attr/esc_html, selected())
        echo $select('range', __('Period', 'kontor-shop'), [
            ''      => __('All time', 'kontor-shop'),
            'today' => __('Today', 'kontor-shop'),
            '7'     => __('Last 7 days', 'kontor-shop'),
            '30'    => __('Last 30 days', 'kontor-shop'),
            '90'    => __('Last 90 days', 'kontor-shop'),
        ], $p['range']);
        echo $select('ship', __('Shipping method', 'kontor-shop'), ['' => __('Shipping + pickup', 'kontor-shop'), 'ship' => __('shipping only', 'kontor-shop'), 'pickup' => __('pickup only', 'kontor-shop')], $p['ship']);

        if ($can_manage && count($this->warehouse_info()) > 1) {
            $options = ['0' => __('All warehouses', 'kontor-shop')];

            foreach ($this->warehouse_info() as $id => $info) {
                $options[(string) $id] = $info['name'];
            }

            echo $select('warehouse_id', __('Warehouse', 'kontor-shop'), $options, (string) $p['warehouse_id']);
        }

        echo $select('member', __('Customers', 'kontor-shop'), ['' => __('All customers', 'kontor-shop'), '1' => __('members only', 'kontor-shop'), '0' => __('non-members only', 'kontor-shop')], $p['member']);
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<button type="submit" class="button">' . esc_html__('Filter', 'kontor-shop') . '</button>';

        if ($p['q'] !== '' || $p['range'] !== '' || $p['ship'] !== '' || $p['warehouse_id'] > 0 || $p['member'] !== '') {
            echo ' <a href="' . esc_url(OrdersPage::url(['view' => $p['view']])) . '">' . esc_html__('Reset filter', 'kontor-shop') . '</a>';
        }

        echo '</form>';
    }

    /* ==================== Zeile ==================== */

    /**
     * @param array<int, int> $my_warehouse_ids
     */
    private function row_html(WorkQueue $queue, bool $can_pay, bool $can_ship, bool $can_manage, array $my_warehouse_ids, ?string $last_reminder): string
    {
        $order  = $queue->order;
        $id     = (int) $order->id;
        $step   = $queue->next_step($can_pay, $can_ship);
        $urgent = $queue->is_overdue() || $queue->refund_pending();
        $tone   = $urgent ? ' is-out' : ($queue->needs_action() ? ' is-low' : '');
        $days   = self::days_since($order->created_at);
        $place  = $order->self_pickup ? __('Self pickup', 'kontor-shop') : AddressFormatter::place($order->delivery_address);

        $html = '<tbody class="ks-order" data-ks-order="' . $id . '">';
        $html .= '<tr class="ks-order-row' . $tone . '">';
        $html .= '<th scope="row" class="check-column"><input type="checkbox" value="' . $id . '" data-ks-order-select aria-label="' . esc_attr(sprintf(
            /* translators: %s: order number */
            __('Select order %s', 'kontor-shop'),
            $order->order_number
        )) . '" /></th>';

        $html .= '<td class="column-primary"><div class="ks-order-id"><button type="button" class="ks-order-expand" data-ks-order-expand aria-expanded="false" aria-label="'
            . esc_attr__('Quick view', 'kontor-shop') . '">▸</button><div><strong><a class="row-title" href="' . esc_url(OrdersPage::url(['order' => $id])) . '">' . esc_html($order->order_number) . '</a></strong>'
            . ($order->is_member ? ' ' . Badge::html(__('Member', 'kontor-shop'), Badge::INFO) : '')
            . '<div class="ks-muted ks-order-sub"><span title="' . esc_attr(mysql2date('d.m.Y H:i', (string) $order->created_at)) . '">' . esc_html(self::ago($days)) . '</span>'
            . ($order->self_pickup ? ' · ' . esc_html__('Pickup', 'kontor-shop') : '') . '</div></div></div></td>';

        $html .= '<td><strong>' . esc_html($order->customer_name) . '</strong><div class="ks-muted ks-order-sub" title="' . esc_attr($order->customer_email) . '">' . esc_html($place !== '' ? $place : $order->customer_email) . '</div></td>';
        $html .= '<td class="ks-col-items">' . $this->items_html($queue) . '</td>';
        $html .= '<td>' . $this->progress_html($queue) . '</td>';
        $html .= '<td>' . $this->payment_html($queue, $days, $last_reminder) . '</td>';
        $html .= '<td class="ks-col-num"><strong>' . esc_html(number_format_i18n($order->total, 2)) . ' €</strong></td>';
        $html .= '<td class="ks-col-next"><div class="ks-order-actions">' . $this->primary_html($queue, $step, $can_manage, $my_warehouse_ids)
            . $this->menu_html($queue, $can_pay, $can_ship) . '</div></td>';
        $html .= '</tr>';
        $html .= '<tr class="ks-order-quick" hidden><td colspan="8">' . $this->quick_html($queue) . '</td></tr>';

        return $html . '</tbody>';
    }

    public static function days_since(?string $date): int
    {
        if ($date === null || $date === '') {
            return 0;
        }

        return max(0, (int) floor((strtotime(current_time('Y-m-d')) - strtotime(substr($date, 0, 10))) / DAY_IN_SECONDS));
    }

    private static function ago(int $days): string
    {
        return match (true) {
            $days === 0 => __('today', 'kontor-shop'),
            $days === 1 => __('yesterday', 'kontor-shop'),
            /* translators: %d: days */
            default     => sprintf(__('%d days ago', 'kontor-shop'), $days),
        };
    }

    /**
     * Artikel kompakt: eine Position direkt, mehrere als Akkordeon
     * ("3 Artikel · 5 Stück ▸" klappt die Liste auf).
     */
    private function items_html(WorkQueue $queue): string
    {
        $items = $queue->fulfilment()->active_items();

        if ($items === []) {
            return '<span class="ks-muted">' . esc_html__('all cancelled', 'kontor-shop') . '</span>';
        }

        $line = function (OrderItem $item) use ($queue): string {
            $shipped = $queue->fulfilment()->is_shipped($item);

            return '<li class="' . ($shipped ? 'is-shipped' : '') . '">' . $this->dot($item->warehouse_id)
                . esc_html(($item->quantity > 1 ? $item->quantity . '× ' : '') . $item->display_name())
                . ($shipped ? ' <span class="ks-order-check" title="' . esc_attr__('out', 'kontor-shop') . '">✓</span>' : '') . '</li>';
        };

        if (count($items) === 1) {
            return '<ul class="ks-order-items">' . $line($items[0]) . '</ul>';
        }

        $pieces = array_sum(array_map(static fn (OrderItem $i): int => $i->quantity, $items));
        $dots   = implode('', array_map(fn (int $wh): string => $this->dot($wh), array_values(array_unique(array_map(static fn (OrderItem $i): int => $i->warehouse_id, $items)))));

        return '<button type="button" class="ks-order-items-toggle" data-ks-items-toggle aria-expanded="false">' . $dots
            . esc_html(sprintf(
                /* translators: 1: number of line items, 2: items */
                __('%1$d products · %2$d items', 'kontor-shop'),
                count($items),
                $pieces
            )) . ' <span aria-hidden="true">▸</span></button>'
            . '<ul class="ks-order-items" hidden>' . implode('', array_map($line, $items)) . '</ul>';
    }

    private function progress_html(WorkQueue $queue): string
    {
        $order    = $queue->order;
        $progress = $queue->progress();

        if ($progress === 0) {
            return '<div class="ks-steps" aria-hidden="true"><i class="is-cancelled"></i></div><div class="ks-steps__label">' . esc_html__('cancelled', 'kontor-shop') . '</div>';
        }

        // Vorkasse: Bestellt → Bezahlt → Raus; auf Rechnung: Bestellt → Raus → Bezahlt.
        [$step2, $step3] = $queue->steps();
        $pay_state = $queue->is_overdue() ? 'is-bad' : 'is-current';
        $second    = $progress >= 2 ? 'is-done' : ($step2 === 'pay' ? $pay_state : 'is-current');
        $third     = $progress >= 3 ? 'is-done' : ($progress === 2 ? ($step3 === 'pay' ? $pay_state : 'is-current') : '');
        $out       = $order->self_pickup ? __('picked up', 'kontor-shop') : __('shipped', 'kontor-shop');
        $shipping  = match (true) {
            $queue->waits_for_other_warehouse() => __('your part is out', 'kontor-shop'),
            $queue->is_partial()                => __('partially out', 'kontor-shop'),
            $order->self_pickup                 => __('ready for pickup', 'kontor-shop'),
            default                             => __('ready to pack', 'kontor-shop'),
        };
        $label     = match (true) {
            $progress === 3 && $order->is_invoice() => $out . ' · ' . __('paid', 'kontor-shop'),
            $progress === 3                         => $out,
            $order->is_invoice() && $progress === 2 => $queue->is_overdue() ? __('Invoice overdue', 'kontor-shop') : __('Invoice open', 'kontor-shop'),
            $order->is_invoice()                    => $shipping,
            $progress === 1                         => $queue->is_overdue() ? __('Payment overdue', 'kontor-shop') : __('waiting for payment', 'kontor-shop'),
            default                                 => $shipping,
        };

        $html = '<div class="ks-steps" role="img" aria-label="' . esc_attr($label) . '"><i class="is-done"></i><b class="' . ($progress >= 2 ? 'is-done' : '') . '"></b><i class="' . $second . '"></i>'
            . '<b class="' . ($progress >= 3 ? 'is-done' : '') . '"></b><i class="' . $third . '"></i></div>'
            . '<div class="ks-steps__label">' . esc_html($label) . '</div>';

        $state = $queue->warehouse_state();

        if (count($state) > 1 && ($progress >= 2 || $order->can_ship())) {
            $html .= '<div class="ks-steps__wh">';

            foreach ($state as $wh => $done) {
                $html .= '<span class="' . ($done ? 'is-done' : '') . '">' . $this->dot($wh) . esc_html($this->warehouse_info()[$wh]['name'] ?? '#' . $wh) . ' ' . ($done ? '✓' : esc_html__('open', 'kontor-shop')) . '</span>';
            }

            $html .= '</div>';
        }

        return $html;
    }

    private function payment_html(WorkQueue $queue, int $days, ?string $last_reminder): string
    {
        $order = $queue->order;

        if ($order->status === OrderStatus::CANCELLED) {
            return '<span class="ks-muted">—</span>' . ($queue->refund_pending() ? '<br />' . $this->refund_badge($order) : '');
        }

        if ($order->awaits_payment()) {
            if ($order->is_invoice() && !$order->is_dispatched()) {
                return '<span class="ks-pay">' . esc_html__('On invoice', 'kontor-shop') . '<br /><span class="ks-muted">' . esc_html__('due after shipping', 'kontor-shop') . '</span></span>';
            }

            // Rechnung: offen seit dem Versand (Rechnungsdatum), Vorkasse: seit der Bestellung.
            $days  = $order->is_invoice() ? self::days_since($order->shipped_at) : $days;
            $due   = $queue->due_date();
            $since = $days === 0 ? __('open since today', 'kontor-shop') : sprintf(
                /* translators: %d: days */
                _n('open for %d day', 'open for %d days', $days, 'kontor-shop'),
                $days
            );
            $html  = '<span class="ks-pay' . ($queue->is_overdue() ? ' is-bad' : '') . '">'
                . ($order->is_invoice() ? esc_html__('Invoice', 'kontor-shop') . ' · ' : '') . esc_html($since);

            if ($due !== null) {
                $html .= '<br /><span class="' . ($queue->is_overdue() ? '' : 'ks-muted') . '">' . esc_html(sprintf(
                    /* translators: %s: date */
                    $queue->is_overdue() ? __('Deadline was %s', 'kontor-shop') : __('Deadline until %s', 'kontor-shop'),
                    mysql2date('d.m.', $due)
                )) . '</span>';
            }

            if ($last_reminder !== null) {
                $html .= '<br /><span class="ks-muted">' . esc_html(sprintf(
                    /* translators: %s: date */
                    __('reminded on %s', 'kontor-shop'),
                    mysql2date('d.m.', $last_reminder)
                )) . '</span>';
            }

            return $html . '</span>';
        }

        $with = $order->paid_with !== null ? (PaidWith::labels()[$order->paid_with] ?? $order->paid_with) : __('paid', 'kontor-shop');

        return '<span class="ks-pay">' . esc_html($with . ($order->paid_at !== null ? ' · ' . mysql2date('d.m.', $order->paid_at) : ''))
            . ($queue->refund_pending() ? '<br />' . $this->refund_badge($order) : '') . '</span>';
    }

    private function refund_badge(Order $order): string
    {
        return Badge::html(sprintf(
            /* translators: %s: amount */
            __('Refund %s €', 'kontor-shop'),
            number_format_i18n($order->refund_pending(), 2)
        ), Badge::BAD);
    }

    /**
     * @param array<int, int> $my_warehouse_ids
     */
    private function primary_html(WorkQueue $queue, ?string $step, bool $can_manage, array $my_warehouse_ids): string
    {
        $order = $queue->order;
        $id    = (int) $order->id;

        // Fuehrende Kassa gekoppelt: statt „Bezahlt ✓“ / „Rückzahlung erledigt“ ein Link in deren Kassa.
        if (($step === WorkQueue::STEP_PAY || $step === WorkQueue::STEP_REFUND) && ($owner = ExternalPayments::owner()) !== null) {
            $url  = ExternalPayments::order_url($order->payment_reference, $order);
            $text = sprintf(
                /* translators: %s: name of the connected system */
                __('book in %s', 'kontor-shop'),
                $owner['label']
            );

            return $url !== ''
                ? '<a class="button" href="' . esc_url($url) . '" target="_blank" rel="noopener" title="' . esc_attr__('Payments are booked in the cash register', 'kontor-shop') . '">' . esc_html($text) . ' ↗</a>'
                : '<span class="ks-muted">' . esc_html($text) . '</span>';
        }

        switch ($step) {
            case WorkQueue::STEP_PAY:
                return '<button type="button" class="button button-primary" data-ks-paid="' . $id . '" data-number="' . esc_attr($order->order_number) . '" data-total="'
                    . esc_attr(number_format_i18n($order->total, 2)) . '" data-reference="' . esc_attr($order->payment_reference) . '" data-created="' . esc_attr(substr((string) $order->created_at, 0, 10)) . '">'
                    . esc_html__('Paid ✓', 'kontor-shop') . '</button>';
            case WorkQueue::STEP_REFUND:
                return '<button type="button" class="button button-primary" data-ks-order-action="refund_done" data-order="' . $id . '" data-confirm="' . esc_attr(sprintf(
                    /* translators: 1: amount, 2: customer */
                    __('Refunded %1$s € to %2$s?', 'kontor-shop'),
                    number_format_i18n($order->refund_pending(), 2),
                    $order->customer_name
                )) . '">' . esc_html__('Refund done', 'kontor-shop') . '</button>';
            case WorkQueue::STEP_SHIP:
            case WorkQueue::STEP_PICKUP:
                $this->dialogs[] = $order;

                return ShipmentDialog::open_button($order, $queue->is_partial());
        }

        if ($queue->waits_for_other_warehouse()) {
            $open = array_keys($queue->fulfilment()->open_by_warehouse());

            return '<span class="ks-muted">' . esc_html(sprintf(
                /* translators: %s: warehouse names */
                __('waiting for warehouse %s', 'kontor-shop'),
                implode(', ', array_map(fn (int $wh): string => $this->warehouse_info()[$wh]['name'] ?? '#' . $wh, $open))
            )) . '</span>';
        }

        if ($order->can_ship() && $queue->fulfilment()->open_items() !== []) {
            return '<span class="ks-muted">' . esc_html__('in progress at the warehouse', 'kontor-shop') . '</span>';
        }

        if ($order->status === OrderStatus::OPEN) {
            return '<span class="ks-muted">' . esc_html__('waiting for payment', 'kontor-shop') . '</span>';
        }

        $shipments = $queue->is_done() && !$order->self_pickup ? $this->orders->shipments_for_order($id) : [];
        $last      = end($shipments) ?: null;

        if ($last !== null && $last->tracking_number !== null) {
            $url = Carrier::tracking_url($last->carrier, $last->tracking_number);

            return '<span class="ks-muted">' . esc_html(Carrier::label($last->carrier)) . '</span> '
                . ($url !== '' ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html__('track', 'kontor-shop') . '</a>' : esc_html($last->tracking_number));
        }

        return '<span class="ks-muted">' . esc_html(match (true) {
            $order->status === OrderStatus::CANCELLED => __('cancelled', 'kontor-shop'),
            $order->awaits_payment()                  => __('Payment open', 'kontor-shop'),
            default                                   => __('done', 'kontor-shop'),
        }) . '</span>';
    }

    private function menu_html(WorkQueue $queue, bool $can_pay, bool $can_ship): string
    {
        $order = $queue->order;
        $id    = (int) $order->id;
        $items = ['<a role="menuitem" href="' . esc_url(OrdersPage::url(['order' => $id])) . '">' . esc_html__('Open details', 'kontor-shop') . '</a>'];

        $pack = $can_ship && ($queue->to_ship() || $queue->to_pickup()) ? OrdersPage::pack_url(['pack' => $id]) : '';

        if ($pack !== '') {
            $items[] = '<a role="menuitem" href="' . esc_url($pack) . '">' . esc_html__('Packing view (mobile)', 'kontor-shop') . '</a>';
        }

        if ($can_pay && PaymentReminder::applies($order)) {
            $items[] = '<button type="button" role="menuitem" data-ks-order-action="payment_reminder" data-order="' . $id . '" data-confirm="' . esc_attr(sprintf(
                /* translators: %s: email */
                __('Send the payment reminder to %s now?', 'kontor-shop'),
                $order->customer_email
            )) . '">' . esc_html__('Send payment reminder', 'kontor-shop') . '</button>';
        }

        if ($can_pay && $order->status !== OrderStatus::CANCELLED) {
            $items[] = '<button type="button" role="menuitem" data-ks-order-action="resend_confirmation" data-order="' . $id . '">' . esc_html__('Resend confirmation', 'kontor-shop') . '</button>';
        }

        if ($can_pay && $order->can_ship() && $queue->fulfilment()->open_items() !== []) {
            $items[] = '<a role="menuitem" href="' . esc_url(OrdersPage::url(['order' => $id])) . '#ks-cancel-items">' . esc_html__('Cancel products …', 'kontor-shop') . '</a>';
        }

        if ($can_pay && $order->status !== OrderStatus::CANCELLED && ItemReturn::returnable_items($queue->fulfilment()) !== []) {
            $items[] = '<a role="menuitem" href="' . esc_url(OrdersPage::url(['order' => $id])) . '#ks-return">' . esc_html__('Record return …', 'kontor-shop') . '</a>';
        }

        // Sobald etwas raus ist: kein Storno mehr, nur Retoure.
        if ($can_pay && $queue->fulfilment()->can_cancel_order()) {
            $items[] = '<hr />';
            $items[] = '<a role="menuitem" class="is-danger" href="' . esc_url(OrdersPage::url(['cancel_order' => $id])) . '">' . esc_html__('Cancel order …', 'kontor-shop') . '</a>';
        }

        return '<span class="ks-menu-wrap"><button type="button" class="button ks-menu-button" data-ks-menu aria-haspopup="true" aria-expanded="false" aria-label="' . esc_attr__('More actions', 'kontor-shop') . '">⋯</button>'
            . '<span class="ks-menu" role="menu" hidden>' . implode('', $items) . '</span></span>';
    }

    /**
     * Aufklappbare Schnellansicht: Positionen, Lieferung, Verlauf und interne Notiz.
     */
    private function quick_html(WorkQueue $queue): string
    {
        $order = $queue->order;
        $id    = (int) $order->id;
        $ful   = $queue->fulfilment();

        $html = '<div class="ks-quick"><div class="ks-quick__col"><h4>' . esc_html__('Items', 'kontor-shop') . '</h4><table class="ks-quick__items">';

        foreach ($queue->items as $item) {
            $state = $item->is_cancelled()
                ? Badge::html(__('cancelled', 'kontor-shop'), Badge::MUTED)
                : ($ful->is_shipped($item) ? Badge::html($order->self_pickup ? __('picked up', 'kontor-shop') : __('out', 'kontor-shop'), Badge::OK) : Badge::html(__('open', 'kontor-shop'), Badge::WARN));

            $html .= '<tr' . ($item->is_cancelled() ? ' class="is-cancelled"' : '') . '><td class="ks-col-num">' . (int) $item->quantity . '×</td><td>' . esc_html($item->display_name()) . '</td>'
                . '<td>' . $this->dot($item->warehouse_id) . esc_html($this->warehouse_info()[$item->warehouse_id]['name'] ?? '') . '</td>'
                . '<td class="ks-col-num">' . esc_html(number_format_i18n($item->line_total, 2)) . ' €</td><td>' . $state . '</td></tr>';
        }

        $html .= '</table><p class="ks-muted">' . esc_html(sprintf(
            /* translators: 1: shipping, 2: payment reference */
            __('Shipping %1$s € · Payment reference %2$s', 'kontor-shop'),
            number_format_i18n($order->shipping_cost, 2),
            $order->payment_reference
        )) . '</p></div>';

        $address = $order->self_pickup ? __('Self pickup', 'kontor-shop') : (string) $order->delivery_address;

        $html .= '<div class="ks-quick__col"><h4>' . esc_html__('Delivery', 'kontor-shop') . '</h4><div class="ks-quick__address"><span data-ks-copy-source>' . nl2br(esc_html($address)) . '</span>'
            . ($order->self_pickup ? '' : '<button type="button" class="button button-small" data-ks-copy>' . esc_html__('Copy', 'kontor-shop') . '</button>') . '</div>'
            . '<p class="ks-muted"><a href="mailto:' . esc_attr($order->customer_email) . '">' . esc_html($order->customer_email) . '</a>'
            . ($order->customer_phone !== null ? ' · <a href="tel:' . esc_attr(preg_replace('/[^0-9+]/', '', $order->customer_phone) ?? '') . '">' . esc_html($order->customer_phone) . '</a>' : '') . '</p></div>';

        $log = array_slice(array_reverse($this->orders->status_log($id)), 0, self::HISTORY);

        $html .= '<div class="ks-quick__col"><h4>' . esc_html__('History & notes', 'kontor-shop') . '</h4><ul class="ks-quick__log">';

        foreach ($log as $entry) {
            $user = !empty($entry['user_id']) ? get_userdata((int) $entry['user_id']) : false;

            $html .= '<li><span class="ks-muted">' . esc_html(mysql2date('d.m. H:i', (string) $entry['created_at'])) . '</span> '
                . esc_html((string) ($entry['note'] ?: (OrderStatus::labels()[(string) $entry['to_status']] ?? '')))
                . ($user ? ' <span class="ks-muted">· ' . esc_html($user->display_name) . '</span>' : '') . '</li>';
        }

        $html .= '</ul><form method="post" action="' . esc_url(OrdersPage::list_url()) . '" class="ks-quick__note">'
            . '<input type="hidden" name="kontor_shop_order_nonce" value="' . esc_attr(wp_create_nonce(OrdersPage::NONCE_ACTION)) . '" />'
            . '<input type="hidden" name="order_id" value="' . $id . '" /><input type="hidden" name="order_action" value="add_note" />'
            . '<input type="text" name="note" id="ks-note-' . $id . '" maxlength="500" required placeholder="' . esc_attr__('Internal note, e.g. “Customer will call back”', 'kontor-shop') . '" aria-label="' . esc_attr__('Internal note', 'kontor-shop') . '" />'
            . '<button type="submit" class="button button-small">' . esc_html__('Save', 'kontor-shop') . '</button></form>'
            . '<p><a href="' . esc_url(OrdersPage::url(['order' => $id])) . '">' . esc_html__('Open all details →', 'kontor-shop') . '</a></p></div></div>';

        return $html;
    }

    private function dot(int $warehouse_id): string
    {
        return '<span class="ks-wh-dot ' . Css::wh($warehouse_id) . '" title="'
            . esc_attr($this->warehouse_info()[$warehouse_id]['name'] ?? '') . '"></span>';
    }

    /* ==================== Formulare / Dialoge ==================== */

    /**
     * Gemeinsame Formulare fuer Zeilen-Aktionen, "Bezahlt"-Popover und
     * Sammelaktionen (von orders.js befuellt) - so braucht nicht jede Zeile
     * ein eigenes Formular.
     */
    private function render_shared_forms(): void
    {
        $nonce = wp_create_nonce(OrdersPage::NONCE_ACTION);
        $url   = OrdersPage::list_url();

        echo '<form method="post" action="' . esc_url($url) . '" id="ks-order-action-form" hidden>'
            . '<input type="hidden" name="kontor_shop_order_nonce" value="' . esc_attr($nonce) . '" />'
            . '<input type="hidden" name="order_id" value="" /><input type="hidden" name="order_action" value="" /></form>';

        echo '<form method="post" action="' . esc_url($url) . '" id="ks-order-paid-form" hidden>'
            . '<input type="hidden" name="kontor_shop_order_nonce" value="' . esc_attr($nonce) . '" />'
            . '<input type="hidden" name="order_id" value="" /><input type="hidden" name="order_action" value="mark_paid" />'
            . '<input type="hidden" name="paid_with" value="" /><input type="hidden" name="paid_on" value="" /></form>';

        echo '<form method="post" action="' . esc_url($url) . '" id="ks-order-bulk-form" hidden>'
            . '<input type="hidden" name="kontor_shop_order_bulk_nonce" value="' . esc_attr(wp_create_nonce(OrdersPage::BULK_NONCE)) . '" />'
            . '<input type="hidden" name="bulk_action" value="" /></form>';
    }

    /**
     * @param array<int, int> $my_warehouse_ids
     */
    private function render_dialogs(bool $can_manage, array $my_warehouse_ids): void
    {
        $names = [];

        foreach ($this->warehouse_info() as $wid => $info) {
            $names[$wid] = $info['name'];
        }

        foreach ($this->dialogs as $order) {
            ShipmentDialog::render(
                $order,
                $this->orders->items_for_order((int) $order->id),
                $this->orders->shipments_for_order((int) $order->id),
                $can_manage ? null : $my_warehouse_ids,
                $names,
                OrdersPage::list_url()
            );
        }

        $this->dialogs = [];
    }

    /**
     * @return array<int, array{name: string, color: string}>
     */
    public function warehouse_info(): array
    {
        if ($this->warehouse_info === null) {
            $this->warehouse_info = [];

            foreach ($this->warehouses->all() as $warehouse) {
                $this->warehouse_info[(int) $warehouse->id] = ['name' => $warehouse->name, 'color' => $warehouse->display_color()];
            }
        }

        return $this->warehouse_info;
    }
}
