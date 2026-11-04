<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Order;

/**
 * Zahlungsfrist fuer Vorkasse-Bestellungen: so viele Tage nach der
 * Bestellung bleibt die (beim Bestellen bereits ausgebuchte) Ware fuer den
 * Kunden zurueckgelegt. Danach gilt eine offene Bestellung als ueberfaellig
 * (Liste, Uebersicht, Bestellstatus). Erinnern und stornieren macht das
 * Backoffice von Hand; mit Pro auch automatisch (Erinnerung, letzte
 * Erinnerung, Nachfrist, Autostorno). Reine Logik ohne WordPress.
 */
final class PaymentDeadline
{
    public const DEFAULT_DAYS = 14;
    public const MAX_DAYS     = 90;

    /** 0 = keine Frist. */
    public static function sanitize_days(mixed $value): int
    {
        return max(0, min(self::MAX_DAYS, (int) $value));
    }

    /**
     * Letzter Zahlungstag (Y-m-d) oder null (keine Frist / Datum unbekannt).
     */
    public static function due_date(?string $created_at, int $days): ?string
    {
        if ($days <= 0 || $created_at === null || $created_at === '') {
            return null;
        }

        $created = strtotime(substr($created_at, 0, 10) . ' 00:00:00 UTC');

        return $created === false ? null : gmdate('Y-m-d', $created + $days * 86400);
    }

    /**
     * Ueberfaellig = noch offen, Vorkasse und der letzte Zahlungstag ist vorbei.
     *
     * @param string $today Y-m-d (Ortszeit des Shops)
     */
    public static function is_overdue(Order $order, int $days, string $today): bool
    {
        if ($order->status !== OrderStatus::OPEN || $order->payment_method !== PaymentMethod::PREPAYMENT) {
            return false;
        }

        $due = self::due_date($order->created_at, $days);

        return $due !== null && $today > $due;
    }
}
