<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin\Ui;

use Kontor\Shop\Domain\Order\OrderStatus;
use Kontor\Shop\Domain\Stock\StockStatus;

/**
 * Farbige Status-Marken fuer Listen (Text bleibt immer dabei - Farbe ist nie
 * der einzige Informationstraeger).
 */
final class Badge
{
    public const OK    = 'ok';
    public const WARN  = 'warn';
    public const BAD   = 'bad';
    public const INFO  = 'info';
    public const MUTED = 'muted';

    public static function html(string $label, string $tone = self::MUTED): string
    {
        return '<span class="ks-badge ks-badge--' . esc_attr($tone) . '">' . esc_html($label) . '</span>';
    }

    public static function order_status(string $status): string
    {
        $labels = self::order_status_labels();
        $tone   = match ($status) {
            OrderStatus::OPEN      => self::WARN,
            OrderStatus::PAID      => self::INFO,
            OrderStatus::SHIPPED,
            OrderStatus::PICKED_UP => self::OK,
            OrderStatus::CANCELLED => self::MUTED,
            default                => self::MUTED,
        };

        return self::html($labels[$status] ?? $status, $tone);
    }

    /**
     * @return array<string, string>
     */
    public static function order_status_labels(): array
    {
        return [
            OrderStatus::OPEN      => __('Open', 'kontor-shop'),
            OrderStatus::PAID      => __('Paid', 'kontor-shop'),
            OrderStatus::SHIPPED   => __('Shipped', 'kontor-shop'),
            OrderStatus::PICKED_UP => __('Picked up', 'kontor-shop'),
            OrderStatus::CANCELLED => __('Cancelled', 'kontor-shop'),
        ];
    }

    /**
     * Bestandszahl mit Ampel-Marke: 0 = ausverkauft, knapp = unter Mindestbestand.
     */
    public static function stock(int $balance, ?int $min_stock): string
    {
        $status = StockStatus::classify($balance, $min_stock);
        $label  = match ($status) {
            StockStatus::OUT => __('sold out', 'kontor-shop'),
            StockStatus::LOW => __('low', 'kontor-shop'),
            default          => '',
        };
        $tone = $status === StockStatus::OUT ? self::BAD : ($status === StockStatus::LOW ? self::WARN : self::OK);

        return '<strong class="ks-stock ks-stock--' . esc_attr($status) . '">' . esc_html((string) $balance) . '</strong>'
            . ($label !== '' ? ' ' . self::html($label, $tone) : '');
    }
}
