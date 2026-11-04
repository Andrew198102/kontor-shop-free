<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Order;

/**
 * Was eine (Teil-)Stornierung finanziell bedeutet. Reine Logik ohne WordPress.
 *
 *  - Noch nicht bezahlt: der offene Betrag sinkt um den Warenwert der
 *    stornierten Positionen (Kunde zahlt nur noch den Rest).
 *  - Bereits bezahlt: Summen bleiben (so wurde bezahlt), die Differenz wird
 *    als Rueckzahlung vorgemerkt - bis jemand sie als erledigt markiert.
 *  - Wird damit alles storniert, faellt auch der Versand weg.
 *  - Mit Rabattcode zaehlt der bezahlte Wert der Positionen (nach ihrem
 *    Rabattanteil); unbezahlt sinkt der ausgewiesene Rabatt mit.
 */
final class ItemCancellation
{
    /**
     * @param array<int, OrderItem> $cancelled
     */
    public static function goods_value(array $cancelled): float
    {
        return round(array_sum(array_map(static fn (OrderItem $item): float => $item->paid_total(), $cancelled)), 2);
    }

    /**
     * @param array<int, OrderItem> $cancelled
     */
    public static function discount_value(array $cancelled): float
    {
        return round(array_sum(array_map(static fn (OrderItem $item): float => $item->discount, $cancelled)), 2);
    }

    /**
     * @param array<int, OrderItem> $cancelled  jetzt stornierte Positionen
     * @param bool $everything  danach ist keine Position mehr aktiv
     * @param float|null $refund_override  abweichender Rueckzahlungsbetrag (nur bei bezahlten Bestellungen)
     * @return array{items_total: float, total: float, refund: float, amount: float, discount_total: float}
     */
    public static function plan(Order $order, array $cancelled, bool $everything, ?float $refund_override = null): array
    {
        $goods  = self::goods_value($cancelled);
        $amount = round($goods + ($everything ? $order->shipping_cost : 0.0), 2);

        if (self::is_paid($order)) {
            $max    = max(0.0, round($order->total - $order->refund_total, 2));
            $refund = $refund_override !== null ? $refund_override : $amount;

            return [
                'items_total' => $order->items_total,
                'total'       => $order->total,
                'refund'      => round(min(max(0.0, $refund), $max), 2),
                'amount'      => $amount,
                'discount_total' => $order->discount_total,
            ];
        }

        return [
            'items_total' => max(0.0, round($order->items_total - $goods, 2)),
            'total'       => max(0.0, round($order->total - $amount, 2)),
            'refund'      => 0.0,
            'amount'      => $amount,
            'discount_total' => max(0.0, round($order->discount_total - self::discount_value($cancelled), 2)),
        ];
    }

    public static function is_paid(Order $order): bool
    {
        return $order->is_paid();
    }
}
