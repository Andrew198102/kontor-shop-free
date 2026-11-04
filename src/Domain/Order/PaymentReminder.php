<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Order;

/**
 * Zahlungserinnerung (Knopf "Zahlungserinnerung senden"): fuer offene
 * Vorkasse-Bestellungen und fuer versendete, noch nicht bezahlte
 * Bestellungen auf Rechnung. Wann zuletzt erinnert wurde, steht im
 * Statusverlauf (LOG_ACTOR; die letzte Erinnerung vor einem Autostorno unter
 * FINAL_LOG_ACTOR) - die Listen zeigen das an. Automatische Erinnerungen
 * kommen mit Pro.
 *
 * Reine Logik ohne WordPress.
 */
final class PaymentReminder
{
    public const LOG_ACTOR       = 'payment_reminder';
    public const FINAL_LOG_ACTOR = 'payment_final_reminder';

    /**
     * Kann (von Hand) erinnert werden? Vorkasse offen, oder auf Rechnung
     * versendet und noch nicht bezahlt (vor dem Versand gibt es noch nichts
     * zu zahlen).
     */
    public static function applies(Order $order): bool
    {
        return self::is_open_prepayment($order)
            || ($order->is_invoice() && $order->is_dispatched() && $order->awaits_payment());
    }

    public static function is_open_prepayment(Order $order): bool
    {
        return $order->status === OrderStatus::OPEN && $order->payment_method === PaymentMethod::PREPAYMENT;
    }
}
