<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Order;

/**
 * Bestellung als State Machine (Abschnitt 5.1): offen -> bezahlt ->
 * versendet/abgeholt. Stornierung ist nur vor dem Versand vorgesehen
 * (Abschnitt 8: "Storno-Aktion fuer offene Bestellungen") - nach Versand/
 * Abholung gilt die Bestellung als abgeschlossen.
 *
 * Die Tabelle beschreibt den Vorkasse-Ablauf. Auf Rechnung geht eine
 * offene Bestellung direkt in den Versand (Order::can_ship) und wird danach
 * bezahlt, ohne dass sich der Status aendert (Order::awaits_payment).
 * Sobald ein Artikel raus ist, gibt es keinen Gesamtstorno mehr, nur noch
 * Retoure (Fulfilment::can_cancel_order).
 */
final class OrderStatus
{
    public const OPEN      = 'open';
    public const PAID      = 'paid';
    public const SHIPPED   = 'shipped';
    public const PICKED_UP = 'picked_up';
    public const CANCELLED = 'cancelled';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [self::OPEN, self::PAID, self::SHIPPED, self::PICKED_UP, self::CANCELLED];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private static function transitions(): array
    {
        return [
            self::OPEN      => [self::PAID, self::CANCELLED],
            self::PAID      => [self::SHIPPED, self::PICKED_UP, self::CANCELLED],
            self::SHIPPED   => [],
            self::PICKED_UP => [],
            self::CANCELLED => [],
        ];
    }

    public static function can_transition(string $from, string $to): bool
    {
        return in_array($to, self::transitions()[$from] ?? [], true);
    }

    /**
     * Anzeigenamen der Status (klein geschrieben, fuer Listen/Export).
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::OPEN      => __('open', 'kontor-shop'),
            self::PAID      => __('paid', 'kontor-shop'),
            self::SHIPPED   => __('shipped', 'kontor-shop'),
            self::PICKED_UP => __('picked up', 'kontor-shop'),
            self::CANCELLED => __('cancelled', 'kontor-shop'),
        ];
    }

    public static function is_known(string $status): bool
    {
        return in_array($status, self::all(), true);
    }
}
