<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Orders;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- eigene Tabellen des Plugins, kein WP-Objektcache-Bezug

use Kontor\Shop\Domain\Order\Actor;
use Kontor\Shop\Domain\Order\Fulfilment;
use Kontor\Shop\Domain\Order\ItemCancellation;
use Kontor\Shop\Domain\Order\ItemReturn;
use Kontor\Shop\Domain\Order\Order;
use Kontor\Shop\Domain\Order\OrderItem;
use Kontor\Shop\Domain\Order\OrderStatus;
use Kontor\Shop\Domain\Order\PaidWith;
use Kontor\Shop\Domain\Stock\MovementType;
use Kontor\Shop\Infrastructure\Logging\Logger;
use Kontor\Shop\Infrastructure\Repositories\OrderRepository;
use Kontor\Shop\Infrastructure\Repositories\StockRepository;
use Kontor\Shop\Modules\Notifications\NotificationsModule;

/**
 * Kassen-Aktionen an einer Bestellung: als bezahlt markieren, (Teil-)Storno
 * bzw. Retoure mit Bestandsbuchung und Rueckzahlung vormerken, Rueckzahlung
 * als erledigt buchen. Eine Stelle fuer Shop-Admin (Admin\OrdersPage) und
 * Kassen-Schnittstelle (Pro: Api\CashierApiModule), damit Statuswechsel,
 * Lagerbuchungen und Mails immer gleich ablaufen - egal, wo der Kassier sitzt.
 */
final class Cashier
{
    /** Bestandsbuchung je stornierter Position */
    public const STOCK_RESTOCK   = 'restock';
    public const STOCK_NONE      = 'none';
    public const STOCK_DEFECTIVE = 'defective';
    public const STOCK_STOLEN    = 'stolen';

    public function __construct(
        private readonly OrderRepository $orders = new OrderRepository(),
        private readonly StockRepository $stock = new StockRepository(),
    ) {
    }

    /**
     * @return array<int, string>
     */
    public static function stock_choices(): array
    {
        return [self::STOCK_RESTOCK, self::STOCK_NONE, self::STOCK_DEFECTIVE, self::STOCK_STOLEN];
    }

    /**
     * Zahlungseingang buchen. Offene Bestellungen werden "bezahlt"; eine auf
     * Rechnung schon versendete/abgeholte behaelt ihren Status und bekommt
     * nur den Zahlungseingang.
     */
    public function mark_paid(Order $order, Actor $actor, ?string $paid_with = null, string $note = '', ?string $paid_at = null): CashierResult
    {
        if (!$order->can_mark_paid()) {
            return CashierResult::fail(CashierResult::INVALID_STATE, __('This order can\'t be marked as paid.', 'kontor-shop'));
        }

        $to_status = $order->is_dispatched() ? $order->status : OrderStatus::PAID;
        $paid_with = $paid_with !== null && PaidWith::is_known($paid_with) ? $paid_with : null;
        $log       = ($order->is_dispatched() ? __('Payment received booked against the invoice.', 'kontor-shop') : __('Marked as paid.', 'kontor-shop'))
            . ($paid_with !== null ? ' (' . (PaidWith::labels()[$paid_with] ?? $paid_with) . ')' : '')
            . ($note !== '' ? ' ' . $note : '');

        try {
            $done = $this->orders->update_status((int) $order->id, $to_status, $actor->user_id, $log, [
                'paid_at'         => $paid_at ?? current_time('mysql'),
                'paid_by_user_id' => $actor->user_id,
                'paid_via'        => $actor->channel,
                'paid_by_name'    => $actor->name,
                'paid_with'       => $paid_with,
            ], $actor->label(), $order->status);
        } catch (\Throwable $e) {
            return $this->failed($e, 'Zahlung konnte nicht gebucht werden', $order);
        }

        if (!$done) {
            return CashierResult::fail(CashierResult::CONFLICT, __('The order has been changed in the meantime – please reload.', 'kontor-shop'));
        }

        $fresh = $this->orders->find((int) $order->id);

        try {
            do_action(NotificationsModule::HOOK_ORDER_PAID, $fresh);
        } catch (\Throwable $e) {
            Logger::exception($e, 'orders', 'Zahlungs-Mail fehlgeschlagen', ['order_id' => $order->id]);
        }

        return CashierResult::ok(['order' => $fresh]);
    }

    /**
     * Zahlung zuruecknehmen (Korrektur, z. B. falsche Bestellung als bezahlt
     * markiert): bezahlt -> offen, Zahlungsangaben werden geleert. Nur
     * solange nichts versendet/abgeholt ist. Keine Mail an den Kunden; der
     * Verlauf haelt fest, wer es wann warum gemacht hat.
     */
    public function undo_payment(Order $order, Actor $actor, string $reason = ''): CashierResult
    {
        if ($order->status !== OrderStatus::PAID || (new Fulfilment($order, $this->orders->items_for_order((int) $order->id)))->has_shipped_items()) {
            return CashierResult::fail(CashierResult::INVALID_STATE, __('Only paid orders that have not been shipped or picked up yet can be set back to open.', 'kontor-shop'));
        }

        $log = __('Payment taken back – the order is open again.', 'kontor-shop') . ($reason !== '' ? ' – ' . $reason : '');

        try {
            $done = $this->orders->update_status((int) $order->id, OrderStatus::OPEN, $actor->user_id, $log, [
                'paid_at'         => null,
                'paid_by_user_id' => null,
                'paid_via'        => null,
                'paid_by_name'    => null,
                'paid_with'       => null,
            ], $actor->label(), OrderStatus::PAID);
        } catch (\Throwable $e) {
            return $this->failed($e, 'Zahlung konnte nicht zurueckgenommen werden', $order);
        }

        if (!$done) {
            return CashierResult::fail(CashierResult::CONFLICT, __('The order has been changed in the meantime – please reload.', 'kontor-shop'));
        }

        return CashierResult::ok(['order' => $this->orders->find((int) $order->id)]);
    }

    /**
     * Storniert die ganze Bestellung ($item_ids = null) oder einzelne, noch
     * nicht versendete Positionen.
     *
     * Unbezahlt: der offene Betrag sinkt. Bezahlt: die Rueckzahlung wird
     * vorgemerkt ($refund_override = abweichender Betrag, null = Warenwert).
     * Ist nach einem Teil-Storno nichts mehr aktiv, gilt die ganze Bestellung
     * als storniert; ist der Rest schon raus, als versendet/abgeholt.
     *
     * @param array<int, int>|null  $item_ids
     * @param array<int, string>    $stock_choices Positions-ID => STOCK_* (fehlend = zurueck ins Lager)
     * @return CashierResult data: refund, total, everything, paid, items (stornierte Positionen)
     */
    public function cancel(Order $order, ?array $item_ids, array $stock_choices, string $reason, ?float $refund_override, bool $notify_customer, Actor $actor): CashierResult
    {
        return $item_ids === null
            ? $this->cancel_all($order, $stock_choices, $reason, $refund_override, $notify_customer, $actor)
            : $this->cancel_items($order, $item_ids, $stock_choices, $reason, $refund_override, $notify_customer, $actor);
    }

    /**
     * Retoure: versendete Ware kommt (ganz oder teilweise) zurueck. Bezahlt:
     * Rueckzahlung wird vorgemerkt ($refund_override = abweichender Betrag).
     * Unbezahlt (auf Rechnung): der offene Betrag sinkt. Der Status bleibt
     * (versendet/abgeholt bzw. offen/bezahlt bei Teilsendungen).
     *
     * @param array<int, int>    $quantities    Positions-ID => zurueckgekommene Stueck
     * @param array<int, string> $stock_choices Positions-ID => STOCK_* (fehlend = zurueck ins Lager)
     * @return CashierResult data: refund, total, everything, paid, lines (array{item, quantity})
     */
    public function return_items(Order $order, array $quantities, array $stock_choices, string $reason, ?float $refund_override, bool $notify_customer, Actor $actor): CashierResult
    {
        if ($order->status === OrderStatus::CANCELLED) {
            return CashierResult::fail(CashierResult::INVALID_STATE, __('A cancelled order can\'t have a return.', 'kontor-shop'));
        }

        $fulfilment = new Fulfilment($order, $this->orders->items_for_order((int) $order->id));
        $lines      = [];
        $by_id      = [];

        foreach (ItemReturn::returnable_items($fulfilment) as $item) {
            $quantity = (int) ($quantities[(int) $item->id] ?? 0);

            if ($quantity <= 0) {
                continue;
            }

            $max = ItemReturn::returnable_quantity($item, $fulfilment);

            if ($quantity > $max) {
                return CashierResult::fail(CashierResult::AMOUNT_INVALID, sprintf(
                    /* translators: 1: product, 2: pieces */
                    __('At most %2$d pieces of “%1$s” can be returned.', 'kontor-shop'),
                    $item->product_name,
                    $max
                ));
            }

            $lines[]                  = ['item' => $item, 'quantity' => $quantity];
            $by_id[(int) $item->id]   = $quantity;
        }

        if ($lines === []) {
            return CashierResult::fail(CashierResult::NO_ITEMS, __('Please enter the returned quantity for at least one shipped product.', 'kontor-shop'));
        }

        $everything = ItemReturn::returns_everything($fulfilment, $by_id);
        $plan       = ItemReturn::plan($order, $lines, $everything, $refund_override);
        $user_id    = $actor->user_id;

        global $wpdb;

        $wpdb->query('START TRANSACTION');

        try {
            $summary = [];

            foreach ($lines as $line) {
                /** @var OrderItem $item */
                $item = $line['item'];

                if (!$this->orders->add_returned((int) $item->id, $line['quantity'], $item->returned_qty)) {
                    $wpdb->query('ROLLBACK');

                    return CashierResult::fail(CashierResult::CONFLICT, __('The order has been changed in the meantime – please reload.', 'kontor-shop'));
                }

                $summary[] = $this->book_return_stock($order, $item, $line['quantity'], $stock_choices[(int) $item->id] ?? self::STOCK_RESTOCK, $user_id);
            }

            $this->orders->update_order_fields((int) $order->id, [
                'items_total'    => $plan['items_total'],
                'total'          => $plan['total'],
                'refund_total'   => round($order->refund_total + $plan['refund'], 2),
                'discount_total' => $plan['discount_total'],
            ]);

            $this->orders->add_log_note((int) $order->id, $user_id, __('Return:', 'kontor-shop') . ' ' . implode('; ', $summary)
                . ($reason !== '' ? ' – ' . $reason : '')
                . ($plan['refund'] > 0 ? ' – ' . sprintf(/* translators: %s: refunded amount */ __('Refund saved: %s €', 'kontor-shop'), number_format_i18n($plan['refund'], 2)) : '')
                . (!$plan['paid'] ? ' – ' . sprintf(/* translators: %s: new order total */ __('new amount: %s €', 'kontor-shop'), number_format_i18n($plan['total'], 2)) : ''), $actor->label());

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');

            return $this->failed($e, 'Retoure fehlgeschlagen', $order);
        }

        $fresh = $this->orders->find((int) $order->id);

        if ($fresh !== null) {
            try {
                do_action(NotificationsModule::HOOK_ORDER_RETURN, $fresh, $lines, $plan['refund'], $reason, $notify_customer);
            } catch (\Throwable $e) {
                Logger::exception($e, 'orders', 'Retouren-Mail fehlgeschlagen', ['order_id' => $order->id]);
            }
        }

        return CashierResult::ok([
            'refund'     => $plan['refund'],
            'total'      => $plan['total'],
            'everything' => $everything,
            'paid'       => $plan['paid'],
            'lines'      => $lines,
        ]);
    }

    /**
     * Bestand einer Retoure: "zurueck" bucht die Stueck als Retoure wieder
     * ein, "defekt/verloren" zusaetzlich gleich wieder aus - so bleibt im
     * Bestandsprotokoll nachvollziehbar, was zurueckkam.
     *
     * @return string Kurzbeschreibung fuer den Statusverlauf
     */
    private function book_return_stock(Order $order, OrderItem $item, int $quantity, string $choice, ?int $user_id): string
    {
        $note = sprintf(
            /* translators: %s: order number */
            __('Return for order %s', 'kontor-shop'),
            $order->order_number
        );
        $label = $quantity . 'x ' . $item->product_name . ': ';

        switch ($choice) {
            case self::STOCK_NONE:
                return $label . __('not booked back', 'kontor-shop');

            case self::STOCK_DEFECTIVE:
            case self::STOCK_STOLEN:
                $type = $choice === self::STOCK_DEFECTIVE ? MovementType::DEFECTIVE : MovementType::STOLEN;

                $this->stock->record($item->product_id, MovementType::RETURN, $quantity, (int) $order->id, $note, $user_id, $item->variant_id);
                $this->stock->record($item->product_id, $type, -$quantity, (int) $order->id, $note, $user_id, $item->variant_id);

                return $label . (MovementType::labels()[$type] ?? $type);

            default:
                $this->stock->record($item->product_id, MovementType::RETURN, $quantity, (int) $order->id, $note, $user_id, $item->variant_id);

                return $label . __('back to warehouse', 'kontor-shop');
        }
    }

    /**
     * Rueckzahlung an den Kunden ist (ganz oder teilweise) erfolgt.
     *
     * @param float|null $amount null = die ganze offene Rueckzahlung
     */
    public function record_refund(Order $order, ?float $amount, Actor $actor, string $note = ''): CashierResult
    {
        $pending = $order->refund_pending();

        if ($pending <= 0) {
            return CashierResult::fail(CashierResult::NO_REFUND_PENDING, __('There is no refund open for this order.', 'kontor-shop'));
        }

        $amount = $amount === null ? $pending : round($amount, 2);

        if ($amount <= 0 || $amount > $pending + 0.001) {
            return CashierResult::fail(CashierResult::AMOUNT_INVALID, sprintf(
                /* translators: %s: amount */
                __('The amount must be between €0.01 and the open refund (%s €).', 'kontor-shop'),
                number_format_i18n($pending, 2)
            ), ['refund_pending' => $pending]);
        }

        try {
            $done = $this->orders->add_refunded((int) $order->id, $order->refunded_total, round($order->refunded_total + $amount, 2));
        } catch (\Throwable $e) {
            return $this->failed($e, 'Rueckzahlung konnte nicht gebucht werden', $order);
        }

        if (!$done) {
            return CashierResult::fail(CashierResult::CONFLICT, __('The order has been changed in the meantime – please reload.', 'kontor-shop'));
        }

        $this->orders->add_log_note((int) $order->id, $actor->user_id, sprintf(
            /* translators: %s: amount */
            __('Refund of %s € to the customer completed.', 'kontor-shop'),
            number_format_i18n($amount, 2)
        ) . ($note !== '' ? ' ' . $note : ''), $actor->label());

        return CashierResult::ok(['amount' => $amount, 'refund_pending' => max(0.0, round($pending - $amount, 2))]);
    }

    /**
     * @param array<int, string> $stock_choices
     */
    private function cancel_all(Order $order, array $stock_choices, string $reason, ?float $refund_override, bool $notify_customer, Actor $actor): CashierResult
    {
        if (!OrderStatus::can_transition($order->status, OrderStatus::CANCELLED)) {
            return CashierResult::fail(CashierResult::INVALID_STATE, __('This order can\'t be cancelled.', 'kontor-shop'));
        }

        $all_items = $this->orders->items_for_order((int) $order->id);

        // Was beim Kunden ist, laeuft ueber Retoure - nie ueber Storno.
        if ((new Fulfilment($order, $all_items))->has_shipped_items()) {
            return CashierResult::fail(CashierResult::INVALID_STATE, __('Orders that have already been (partly) shipped can\'t be cancelled – only returned. Products that are still open can be cancelled individually.', 'kontor-shop'));
        }

        $items = array_values(array_filter($all_items, static fn (OrderItem $item): bool => !$item->is_cancelled()));
        $paid  = ItemCancellation::is_paid($order);
        $plan  = ItemCancellation::plan($order, $items, true, $paid ? $refund_override : null);

        global $wpdb;

        $wpdb->query('START TRANSACTION');

        try {
            $summary = [];

            // Pro Position entscheiden, was mit der Ware passiert ist (siehe book_cancel_stock).
            foreach ($items as $item) {
                $summary[] = $this->book_cancel_stock($order, $item, $stock_choices[(int) $item->id] ?? self::STOCK_RESTOCK, $actor->user_id);
            }

            if ($plan['refund'] > 0) {
                $this->orders->update_order_fields((int) $order->id, ['refund_total' => round($order->refund_total + $plan['refund'], 2)]);
                $summary[] = sprintf(/* translators: %s: refunded amount */ __('Refund saved: %s €', 'kontor-shop'), number_format_i18n($plan['refund'], 2));
            }

            $done = $this->orders->update_status(
                (int) $order->id,
                OrderStatus::CANCELLED,
                $actor->user_id,
                __('Cancelled.', 'kontor-shop') . ($reason !== '' ? ' ' . $reason . '.' : '') . ($summary !== [] ? ' ' . implode('; ', $summary) : ''),
                ['cancelled_at' => current_time('mysql')],
                $actor->label(),
                $order->status
            );

            if (!$done) {
                $wpdb->query('ROLLBACK');

                return CashierResult::fail(CashierResult::CONFLICT, __('The order has been changed in the meantime – please reload.', 'kontor-shop'));
            }

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');

            return $this->failed($e, 'Storno fehlgeschlagen', $order);
        }

        $this->notify_cancellation($order, $items, $plan['refund'], $reason, $notify_customer, true);

        return CashierResult::ok([
            'refund'     => $plan['refund'],
            'total'      => $plan['total'],
            'everything' => true,
            'paid'       => $paid,
            'items'      => $items,
        ]);
    }

    /**
     * @param array<int, int>    $item_ids
     * @param array<int, string> $stock_choices
     */
    private function cancel_items(Order $order, array $item_ids, array $stock_choices, string $reason, ?float $refund_override, bool $notify_customer, Actor $actor): CashierResult
    {
        if (!in_array($order->status, [OrderStatus::OPEN, OrderStatus::PAID], true)) {
            return CashierResult::fail(CashierResult::INVALID_STATE, __('No more products can be cancelled in this order.', 'kontor-shop'));
        }

        $fulfilment = new Fulfilment($order, $this->orders->items_for_order((int) $order->id));
        $requested  = array_map('intval', $item_ids);
        $selected   = array_values(array_filter($fulfilment->cancellable_items(), static fn (OrderItem $item): bool => in_array((int) $item->id, $requested, true)));

        if ($selected === []) {
            return CashierResult::fail(CashierResult::NO_ITEMS, __('Please enter at least one product (not yet shipped) to cancel.', 'kontor-shop'));
        }

        $paid       = ItemCancellation::is_paid($order);
        $everything = count($selected) === count($fulfilment->active_items());
        $plan       = ItemCancellation::plan($order, $selected, $everything, $paid ? $refund_override : null);
        $user_id    = $actor->user_id;

        global $wpdb;

        $wpdb->query('START TRANSACTION');

        try {
            $summary = [];

            foreach ($selected as $item) {
                $summary[] = $this->book_cancel_stock($order, $item, $stock_choices[(int) $item->id] ?? self::STOCK_RESTOCK, $user_id);
            }

            $this->orders->cancel_items((int) $order->id, array_map(static fn (OrderItem $item): int => (int) $item->id, $selected), $reason !== '' ? $reason : null);
            $this->orders->update_order_fields((int) $order->id, [
                'items_total'    => $plan['items_total'],
                'total'          => $plan['total'],
                'refund_total'   => round($order->refund_total + $plan['refund'], 2),
                'discount_total' => $plan['discount_total'],
            ]);

            $log = __('Product cancelled:', 'kontor-shop') . ' ' . implode('; ', $summary)
                . ($reason !== '' ? ' – ' . $reason : '')
                . ($plan['refund'] > 0 ? ' – ' . sprintf(/* translators: %s: refunded amount */ __('Refund saved: %s €', 'kontor-shop'), number_format_i18n($plan['refund'], 2)) : '')
                . (!$paid ? ' – ' . sprintf(/* translators: %s: new order total */ __('new amount: %s €', 'kontor-shop'), number_format_i18n($plan['total'], 2)) : '');

            $after = new Fulfilment($order, $this->orders->items_for_order((int) $order->id));

            if ($after->is_fully_cancelled()) {
                $this->orders->update_status((int) $order->id, OrderStatus::CANCELLED, $user_id, $log, ['cancelled_at' => current_time('mysql')], $actor->label());
            } elseif ($order->can_ship() && $after->is_complete()) {
                // Der Rest ist schon raus: Bestellung ist damit abgeschlossen.
                $this->orders->update_status((int) $order->id, $order->self_pickup ? OrderStatus::PICKED_UP : OrderStatus::SHIPPED, $user_id, $log, [
                    'shipped_at'         => current_time('mysql'),
                    'shipped_by_user_id' => $user_id,
                ], $actor->label());
            } else {
                $this->orders->add_log_note((int) $order->id, $user_id, $log, $actor->label());
            }

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');

            return $this->failed($e, 'Artikel-Storno fehlgeschlagen', $order);
        }

        $this->notify_cancellation($order, $selected, $plan['refund'], $reason, $notify_customer, $everything);

        return CashierResult::ok([
            'refund'     => $plan['refund'],
            'total'      => $plan['total'],
            'everything' => $everything,
            'paid'       => $paid,
            'items'      => $selected,
        ]);
    }

    /**
     * Bestand einer stornierten Position verbuchen. Der Verkauf wurde beim
     * Checkout als negative Bewegung gebucht; "zurueck" bucht sie gegen,
     * "defekt/verloren" bucht sie zusaetzlich wieder aus - so bleibt im
     * Bestandsprotokoll nachvollziehbar, was geschehen ist.
     *
     * @return string Kurzbeschreibung fuer den Statusverlauf
     */
    /**
     * Vor dem endgueltigen Loeschen einer Bestellung: Ware, die noch nicht
     * versendet und nicht storniert ist, zurueck ins Lager buchen - sonst
     * bliebe der Verkauf im Bestand stehen, obwohl es die Bestellung nicht
     * mehr gibt. Ohne Statuswechsel und ohne Mails (die Bestellung
     * verschwindet gleich); die Buchungen bleiben im Lager-Protokoll.
     *
     * @return int Anzahl zurueckgebuchter Positionen
     */
    public function restock_before_delete(Order $order, Actor $actor): int
    {
        if (!in_array($order->status, [OrderStatus::OPEN, OrderStatus::PAID], true)) {
            return 0;
        }

        $items = (new Fulfilment($order, $this->orders->items_for_order((int) $order->id)))->cancellable_items();
        $note  = sprintf(
            /* translators: %s: order number */
            __('Order %s deleted – goods back to the warehouse', 'kontor-shop'),
            $order->order_number
        );

        foreach ($items as $item) {
            $this->stock->record($item->product_id, MovementType::CANCEL_RESTOCK, $item->quantity, (int) $order->id, $note, $actor->user_id, $item->variant_id);
        }

        return count($items);
    }

    private function book_cancel_stock(Order $order, OrderItem $item, string $choice, ?int $user_id): string
    {
        $note = sprintf(
            /* translators: %s: order number */
            __('Cancellation for order %s', 'kontor-shop'),
            $order->order_number
        );

        switch ($choice) {
            case self::STOCK_NONE:
                return $item->quantity . 'x ' . $item->product_name . ': ' . __('not booked back', 'kontor-shop');

            case self::STOCK_DEFECTIVE:
            case self::STOCK_STOLEN:
                $type = $choice === self::STOCK_DEFECTIVE ? MovementType::DEFECTIVE : MovementType::STOLEN;

                $this->stock->record($item->product_id, MovementType::CANCEL_RESTOCK, $item->quantity, (int) $order->id, $note, $user_id, $item->variant_id);
                $this->stock->record($item->product_id, $type, -$item->quantity, (int) $order->id, $note, $user_id, $item->variant_id);

                return $item->quantity . 'x ' . $item->product_name . ': ' . (MovementType::labels()[$type] ?? $type);

            default:
                $this->stock->record($item->product_id, MovementType::CANCEL_RESTOCK, $item->quantity, (int) $order->id, $note, $user_id, $item->variant_id);

                return $item->quantity . 'x ' . $item->product_name . ': ' . __('back to warehouse', 'kontor-shop');
        }
    }

    /**
     * Mails zur Stornierung: an den Kunden (falls gewuenscht) und intern
     * (z. B. an die Kassa, damit die Rueckzahlung erledigt wird).
     *
     * @param array<int, OrderItem> $items
     */
    private function notify_cancellation(Order $order, array $items, float $refund, string $reason, bool $notify_customer, bool $full): void
    {
        $fresh = $this->orders->find((int) $order->id);

        if ($fresh === null) {
            return;
        }

        try {
            do_action(NotificationsModule::HOOK_ORDER_CANCELLATION, $fresh, $items, $refund, $reason, $notify_customer, $full);
        } catch (\Throwable $e) {
            Logger::exception($e, 'orders', 'Storno-Mail fehlgeschlagen', ['order_id' => $order->id]);
        }
    }

    private function failed(\Throwable $e, string $what, Order $order): CashierResult
    {
        $reference = Logger::exception($e, 'orders', $what, ['order_id' => $order->id]);

        return CashierResult::fail(CashierResult::FAILED, sprintf(
            /* translators: %s: error reference number */
            __('The action failed and was rolled back (nothing was changed). Error code: %s - details in the log.', 'kontor-shop'),
            $reference
        ), ['reference' => $reference]);
    }
}
