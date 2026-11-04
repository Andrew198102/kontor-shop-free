<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Orders;

use Kontor\Shop\Domain\Order\Carrier;
use Kontor\Shop\Domain\Order\Fulfilment;
use Kontor\Shop\Domain\Order\Order;
use Kontor\Shop\Domain\Order\OrderItem;
use Kontor\Shop\Domain\Order\OrderStatus;
use Kontor\Shop\Domain\Order\Shipment;
use Kontor\Shop\Infrastructure\Logging\Logger;
use Kontor\Shop\Infrastructure\Repositories\OrderRepository;
use Kontor\Shop\Infrastructure\Repositories\WarehouseRepository;
use Kontor\Shop\Modules\Notifications\NotificationsModule;

/**
 * Versand bzw. Abholung erfassen - fuer den Shop-Admin ("Versand erfassen",
 * Sammelaktion) und den KI-Assistenten des Shop-Teams gleich. Je Lager mit
 * ausgewaehlten Positionen entsteht eine Sendung mit eigenem Versanddienst/
 * Trackingnummer. Sind danach alle Positionen raus, wechselt die Bestellung
 * auf versendet/abgeholt, sonst bleibt sie "bezahlt" und gilt als teilweise
 * versendet. Alles in einer Transaktion: entweder ganz oder gar nicht.
 *
 * Wer welches Lager versenden darf, entscheidet der Aufrufer ($allowed).
 * Einzelne Positionen/Lager auswaehlen (Teilversand) geht nur, wenn
 * allows_partial() es erlaubt (kontor_shop/shipping/allow_partial) - sonst
 * gehen immer alle offenen Positionen raus, die der Benutzer versenden darf.
 * Schon teilweise versendete Bestellungen lassen sich so trotzdem fertig
 * versenden.
 */
final class ShipmentRecorder
{
    private WarehouseRepository $warehouses;

    public function __construct(private readonly OrderRepository $orders)
    {
        $this->warehouses = new WarehouseRepository();
    }

    /**
     * Duerfen einzelne Positionen bzw. Lager einer Bestellung getrennt
     * versendet werden?
     */
    public static function allows_partial(Order $order): bool
    {
        /**
         * Filter kontor_shop/shipping/allow_partial: Teilversand erlauben -
         * im Dialog "Versand erfassen" Positionen/Lager einzeln waehlen
         * (z. B. Pro, auch fuer die Packansicht). Ohne: es gehen immer alle
         * offenen Positionen raus, die der Benutzer versenden darf.
         * Standard: false.
         *
         * @param bool  $allow
         * @param Order $order
         */
        return (bool) apply_filters('kontor_shop/shipping/allow_partial', false, $order);
    }

    /**
     * @param array<int, int>|null $allowed Lager, die der Benutzer versenden darf (null = alle)
     * @param array<int, int>|null $item_ids gewaehlte Positionen (null = alle offenen, die er versenden darf; ohne Teilversand immer null)
     * @param array<int, int> $only_warehouses nur diese Lager (leer = keine Einschraenkung; ohne Teilversand immer leer)
     * @param array<int|string, string> $carriers Lager-ID => Versanddienst
     * @param array<int|string, string> $trackings Lager-ID => Trackingnummer
     * @return CashierResult data: message, complete, carriers (verwendete Versanddienste)
     */
    public function record(Order $order, ?array $allowed, ?array $item_ids, array $only_warehouses, array $carriers, array $trackings, string $note, ?int $user_id, bool $notify): CashierResult
    {
        if (!self::allows_partial($order)) {
            $item_ids        = null;
            $only_warehouses = [];
        }

        $target_status = $order->self_pickup ? OrderStatus::PICKED_UP : OrderStatus::SHIPPED;
        $shippable     = (new Fulfilment($order, $this->orders->items_for_order((int) $order->id)))->open_items_for($allowed);

        if (!$order->can_ship()) {
            return CashierResult::fail(CashierResult::INVALID_STATE, __('This order can’t be shipped (yet) – it has to be paid first.', 'kontor-shop'));
        }

        $selected = array_values(array_filter(
            $shippable,
            static fn (OrderItem $item): bool => ($item_ids === null || in_array((int) $item->id, $item_ids, true))
                && ($only_warehouses === [] || in_array($item->warehouse_id, $only_warehouses, true))
        ));

        if ($selected === []) {
            return CashierResult::fail(CashierResult::NO_ITEMS, $shippable === []
                ? __('There’s nothing left for you to ship in this order.', 'kontor-shop')
                : __('Please check at least one product that is going out now.', 'kontor-shop'));
        }

        $groups = [];

        foreach ($selected as $item) {
            $groups[$item->warehouse_id][] = (int) $item->id;
        }

        $type    = $order->self_pickup ? Shipment::TYPE_PICKED_UP : Shipment::TYPE_SHIPPED;
        $names   = $this->warehouse_names();
        $created = [];
        $summary = [];
        $used    = [];

        global $wpdb;

        $wpdb->query('START TRANSACTION'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Transaktionssteuerung, kein Cache-Bezug

        try {
            foreach ($groups as $warehouse_id => $group_item_ids) {
                $carrier  = $order->self_pickup ? null : Carrier::sanitize(sanitize_key((string) ($carriers[$warehouse_id] ?? '')));
                $tracking = $order->self_pickup ? '' : sanitize_text_field((string) ($trackings[$warehouse_id] ?? ''));

                $created[] = $this->orders->create_shipment(
                    (int) $order->id,
                    (int) $warehouse_id,
                    $type,
                    $carrier,
                    $tracking !== '' ? $tracking : null,
                    $note !== '' ? $note : null,
                    $user_id,
                    $group_item_ids
                );

                if ($carrier !== null) {
                    $used[] = $carrier;
                }

                $summary[] = sprintf(
                    /* translators: 1: number of line items, 2: warehouse name, 3: shipping carrier/tracking */
                    __('%1$d item(s) from warehouse %2$s%3$s', 'kontor-shop'),
                    count($group_item_ids),
                    $names[$warehouse_id] ?? '#' . $warehouse_id,
                    $carrier !== null ? ' (' . Carrier::label($carrier) . ($tracking !== '' ? ' ' . $tracking : '') . ')' : ''
                );
            }

            $after = new Fulfilment($order, $this->orders->items_for_order((int) $order->id));
            $label = $order->self_pickup ? __('Picked up', 'kontor-shop') : __('Shipped', 'kontor-shop');
            $log   = $label . ': ' . implode('; ', $summary) . ($note !== '' ? ' – ' . $note : '');

            if ($after->is_complete()) {
                $this->orders->update_status((int) $order->id, $target_status, $user_id, $log, [
                    'shipped_at'         => current_time('mysql'),
                    'shipped_by_user_id' => $user_id,
                ]);
            } else {
                $this->orders->add_log_note((int) $order->id, $user_id, __('Partial', 'kontor-shop') . ' ' . $log);
            }

            $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Transaktionssteuerung, kein Cache-Bezug
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Transaktionssteuerung, kein Cache-Bezug

            $reference = Logger::exception($e, 'orders', 'Versand erfassen fehlgeschlagen', ['order_id' => $order->id]);

            return CashierResult::fail(CashierResult::FAILED, sprintf(
                /* translators: %s: error reference number */
                __('The shipment couldn’t be saved (nothing was changed). Error code: %s', 'kontor-shop'),
                $reference
            ));
        }

        if ($notify) {
            $fresh = $this->orders->find((int) $order->id);

            foreach ($this->orders->shipments_for_order((int) $order->id) as $shipment) {
                if ($fresh !== null && in_array((int) $shipment->id, $created, true)) {
                    try {
                        do_action(NotificationsModule::HOOK_ORDER_SHIPPED, $fresh, $shipment); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Konstante mit Praefix kontor_shop/
                    } catch (\Throwable $e) {
                        Logger::exception($e, 'orders', 'Versandmail fehlgeschlagen', ['order_id' => $order->id]);
                    }
                }
            }
        }

        return CashierResult::ok([
            'message'  => $this->message($order, $after, $names),
            'complete' => $after->is_complete(),
            'carriers' => array_values(array_unique($used)),
        ]);
    }

    /**
     * @param array<int, string> $names
     */
    private function message(Order $order, Fulfilment $after, array $names): string
    {
        if ($after->is_complete()) {
            return $order->self_pickup
                ? __('Pickup recorded – the order has been fully picked up.', 'kontor-shop')
                : __('Shipment recorded – the order has been fully shipped.', 'kontor-shop');
        }

        $open_names = array_unique(array_map(
            static fn (int $warehouse_id): string => $names[$warehouse_id] ?? '#' . $warehouse_id,
            array_keys($after->open_by_warehouse())
        ));

        return sprintf(
            /* translators: 1: number of open line items, 2: warehouse names */
            __('Partial shipment recorded. Still open: %1$d item(s) from warehouse %2$s – until then, the order is partially shipped.', 'kontor-shop'),
            count($after->open_items()),
            implode(', ', $open_names)
        );
    }

    /**
     * @return array<int, string>
     */
    private function warehouse_names(): array
    {
        $names = [];

        foreach ($this->warehouses->all() as $warehouse) {
            $names[(int) $warehouse->id] = $warehouse->name;
        }

        return $names;
    }
}
