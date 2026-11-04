<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Order;

/**
 * Oeffentlicher Link zur Bestellstatus-Seite (geheimes Token aus der
 * Bestellbestaetigung): gueltig, solange die Bestellung noch laeuft, und
 * danach noch X Tage (Einstellung) ab dem Abschluss - Versand, Abholung oder
 * Storno. Reine Logik ohne WordPress.
 */
final class OrderLink
{
    public const DEFAULT_DAYS = 30;
    public const MAX_DAYS     = 365;

    public static function sanitize_days(mixed $value): int
    {
        return max(1, min(self::MAX_DAYS, (int) $value));
    }

    /**
     * Wann ist die Bestellung abgeschlossen (null = laeuft noch)?
     */
    public static function completed_at(Order $order): ?string
    {
        return match ($order->status) {
            OrderStatus::SHIPPED, OrderStatus::PICKED_UP => $order->shipped_at ?? $order->created_at,
            OrderStatus::CANCELLED => $order->cancelled_at ?? $order->created_at,
            default => null,
        };
    }

    /**
     * Mehrere Teil-Bestellungen teilen sich ein Token: gueltig, solange eine
     * davon laeuft oder vor weniger als $days Tagen abgeschlossen wurde.
     * Eine offene Rueckzahlung haelt den Link ebenfalls offen.
     *
     * @param array<int, Order> $orders
     */
    public static function is_valid(array $orders, int $days, int $now): bool
    {
        foreach ($orders as $order) {
            $completed = self::completed_at($order);

            if ($completed === null || $order->refund_pending() > 0) {
                return true;
            }

            $time = strtotime($completed);

            if ($time !== false && $now - $time <= $days * 86400) {
                return true;
            }
        }

        return false;
    }
}
