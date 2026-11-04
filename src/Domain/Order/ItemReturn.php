<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Order;

/**
 * Retoure: Ware, die schon beim Kunden war, kommt zurueck (nach dem Versand
 * gibt es keinen Storno mehr - siehe Fulfilment::can_cancel_order). Reine
 * Logik ohne WordPress; gebucht wird in Infrastructure\Orders\Cashier.
 *
 *  - Retournierbar ist nur Versendetes/Abgeholtes, auch in Teilmengen.
 *  - Bezahlt: Summen bleiben (so wurde bezahlt), der Warenwert wird als
 *    Rueckzahlung vorgemerkt (abweichender Betrag moeglich).
 *  - Noch nicht bezahlt (auf Rechnung): der offene Betrag sinkt.
 *  - Kommt damit alles zurueck und ist nichts mehr offen, faellt auch der
 *    Versand weg (Widerruf der ganzen Bestellung).
 *  - Mit Rabattcode zaehlt der bezahlte Wert je Stueck (nach Rabattanteil).
 */
final class ItemReturn
{
    /** Noch retournierbare Stueck dieser Position. */
    public static function returnable_quantity(OrderItem $item, Fulfilment $fulfilment): int
    {
        if ($item->is_cancelled() || !$fulfilment->is_shipped($item)) {
            return 0;
        }

        return max(0, $item->quantity - $item->returned_qty);
    }

    /**
     * @return array<int, OrderItem> Positionen mit noch retournierbaren Stueck
     */
    public static function returnable_items(Fulfilment $fulfilment): array
    {
        return array_values(array_filter(
            $fulfilment->active_items(),
            static fn (OrderItem $item): bool => self::returnable_quantity($item, $fulfilment) > 0
        ));
    }

    /**
     * Ist nach dieser Retoure alles zurueck? Jede aktive Position komplett
     * retourniert (einschliesslich $lines) und nichts mehr offen.
     *
     * @param array<int, int> $lines Positions-ID => Stueck in dieser Retoure
     */
    public static function returns_everything(Fulfilment $fulfilment, array $lines): bool
    {
        $active = $fulfilment->active_items();

        if ($active === [] || $fulfilment->open_items() !== []) {
            return false;
        }

        foreach ($active as $item) {
            if ($item->returned_qty + ($lines[(int) $item->id] ?? 0) < $item->quantity) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, array{item: OrderItem, quantity: int}> $lines
     */
    public static function goods_value(array $lines): float
    {
        return round(array_sum(array_map(
            static fn (array $line): float => $line['item']->paid_value_of($line['quantity'], $line['item']->returned_qty),
            $lines
        )), 2);
    }

    /**
     * @param array<int, array{item: OrderItem, quantity: int}> $lines
     */
    public static function discount_value(array $lines): float
    {
        return round(array_sum(array_map(
            static fn (array $line): float => $line['item']->discount_of($line['quantity'], $line['item']->returned_qty),
            $lines
        )), 2);
    }

    /**
     * @param array<int, array{item: OrderItem, quantity: int}> $lines
     * @param float|null $refund_override abweichende Rueckzahlung (nur bezahlt)
     * @return array{items_total: float, total: float, refund: float, amount: float, paid: bool, discount_total: float}
     */
    public static function plan(Order $order, array $lines, bool $everything, ?float $refund_override = null): array
    {
        $goods  = self::goods_value($lines);
        $amount = round($goods + ($everything ? $order->shipping_cost : 0.0), 2);

        if ($order->is_paid()) {
            $max    = max(0.0, round($order->total - $order->refund_total, 2));
            $refund = $refund_override ?? $amount;

            return [
                'items_total' => $order->items_total,
                'total'       => $order->total,
                'refund'      => round(min(max(0.0, $refund), $max), 2),
                'amount'      => $amount,
                'paid'        => true,
                'discount_total' => $order->discount_total,
            ];
        }

        return [
            'items_total' => max(0.0, round($order->items_total - $goods, 2)),
            'total'       => max(0.0, round($order->total - $amount, 2)),
            'refund'      => 0.0,
            'amount'      => $amount,
            'paid'        => false,
            'discount_total' => max(0.0, round($order->discount_total - self::discount_value($lines), 2)),
        ];
    }
}
