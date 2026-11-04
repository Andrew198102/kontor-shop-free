<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Order;

/**
 * Zahlungsfristen je Zahlungsart:
 *
 *  - Vorkasse: X Tage ab Bestellung (PaymentDeadline, mit Autostorno).
 *  - Auf Rechnung: Zahlungsziel Y Tage ab Versand/Abholung der ganzen
 *    Bestellung (Rechnungsdatum). Solange noch etwas im Lager liegt, ist
 *    nichts faellig. Ueberfaellig loest nur Erinnerungen aus, nie Storno.
 *
 * Reine Logik ohne WordPress.
 */
final class PaymentTerms
{
    public const DEFAULT_INVOICE_DAYS = 14;
    public const MAX_INVOICE_DAYS     = 90;

    public function __construct(
        public readonly int $prepayment_days,
        public readonly int $invoice_days = self::DEFAULT_INVOICE_DAYS,
    ) {
    }

    /** 0 = kein Zahlungsziel (keine Faelligkeit, keine Erinnerung). */
    public static function sanitize_invoice_days(mixed $value): int
    {
        return max(0, min(self::MAX_INVOICE_DAYS, (int) $value));
    }

    /** Frist in Tagen fuer diese Bestellung. */
    public function days_for(Order $order): int
    {
        return $order->is_invoice() ? $this->invoice_days : $this->prepayment_days;
    }

    /**
     * Letzter Zahlungstag (Y-m-d) oder null (keine Frist / noch nicht faellig).
     */
    public function due_date(Order $order): ?string
    {
        // Bar bei Abholung: bezahlt wird bei der Uebergabe - keine Frist.
        if ($order->is_cash_on_pickup()) {
            return null;
        }

        if (!$order->is_invoice()) {
            return PaymentDeadline::due_date($order->created_at, $this->prepayment_days);
        }

        return $order->is_dispatched() ? PaymentDeadline::due_date($order->shipped_at, $this->invoice_days) : null;
    }

    /**
     * @param string $today Y-m-d (Ortszeit des Shops)
     */
    public function is_overdue(Order $order, string $today): bool
    {
        if ($order->is_cash_on_pickup()) {
            return false;
        }

        if (!$order->is_invoice()) {
            return PaymentDeadline::is_overdue($order, $this->prepayment_days, $today);
        }

        $due = $this->due_date($order);

        return $due !== null && $order->awaits_payment() && $today > $due;
    }
}
