<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Stock;

/**
 * Bekannte Bewegungstypen fuer stock_movements (Abschnitt 5.3). purchase,
 * offline_sale und correction werden manuell im Admin erfasst (P1, StockPage);
 * online_sale/reservation/reservation_release entstehen automatisch im
 * Checkout (P4) und sind hier nur schon als gueltige Typen bekannt, damit die
 * Ledger-Logik sie nicht ablehnt.
 */
final class MovementType
{
    public const PURCHASE            = 'purchase';
    public const OFFLINE_SALE        = 'offline_sale';
    public const ONLINE_SALE         = 'online_sale';
    public const CORRECTION          = 'correction';
    public const RESERVATION         = 'reservation';
    public const RESERVATION_RELEASE = 'reservation_release';
    public const DEFECTIVE           = 'defective';
    public const STOLEN              = 'stolen';
    public const GIVEAWAY            = 'giveaway';
    public const RETURN              = 'return';
    public const CANCEL_RESTOCK      = 'cancel_restock';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::PURCHASE,
            self::OFFLINE_SALE,
            self::ONLINE_SALE,
            self::CORRECTION,
            self::RESERVATION,
            self::RESERVATION_RELEASE,
            self::DEFECTIVE,
            self::STOLEN,
            self::GIVEAWAY,
            self::RETURN,
            self::CANCEL_RESTOCK,
        ];
    }

    /**
     * Anzeigenamen fuer Historie/Auswahl.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::PURCHASE            => __('Purchase / goods receipt', 'kontor-shop'),
            self::OFFLINE_SALE        => __('Offline sale', 'kontor-shop'),
            self::ONLINE_SALE         => __('Online sale', 'kontor-shop'),
            self::CORRECTION          => __('Correction', 'kontor-shop'),
            self::RESERVATION         => __('Reservation', 'kontor-shop'),
            self::RESERVATION_RELEASE => __('Reservation released', 'kontor-shop'),
            self::DEFECTIVE           => __('Defective / damaged', 'kontor-shop'),
            self::STOLEN              => __('Lost / stolen', 'kontor-shop'),
            self::GIVEAWAY            => __('Free item / sample / own use', 'kontor-shop'),
            self::RETURN              => __('Return', 'kontor-shop'),
            self::CANCEL_RESTOCK      => __('Cancellation: back to warehouse', 'kontor-shop'),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function manual(): array
    {
        return [
            self::PURCHASE,
            self::RETURN,
            self::OFFLINE_SALE,
            self::DEFECTIVE,
            self::STOLEN,
            self::GIVEAWAY,
            self::CORRECTION,
        ];
    }

    public static function is_known(string $type): bool
    {
        return in_array($type, self::all(), true);
    }
}
