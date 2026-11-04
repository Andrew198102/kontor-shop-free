<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Order;

/**
 * Wie eine Zahlung tatsaechlich hereinkam (nicht zu verwechseln mit
 * PaymentMethod = was der Kunde im Checkout gewaehlt hat). Wird von der
 * Kassen-Schnittstelle mitgeschickt; im Shop-Admin bleibt es leer.
 */
final class PaidWith
{
    public const TRANSFER = 'transfer';
    public const CASH     = 'cash';
    public const CARD     = 'card';
    public const OTHER    = 'other';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [self::TRANSFER, self::CASH, self::CARD, self::OTHER];
    }

    public static function is_known(string $value): bool
    {
        return in_array($value, self::all(), true);
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::TRANSFER => __('Bank transfer', 'kontor-shop'),
            self::CASH     => __('cash', 'kontor-shop'),
            self::CARD     => __('Card', 'kontor-shop'),
            self::OTHER    => __('other', 'kontor-shop'),
        ];
    }
}
