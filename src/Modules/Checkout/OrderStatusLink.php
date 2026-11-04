<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Checkout;

use Kontor\Shop\Domain\Order\Order;
use Kontor\Shop\Domain\Order\OrderLink;
use Kontor\Shop\Settings\Repository;

/**
 * Link zur oeffentlichen Bestellstatus-Seite (Kassen-Seite + geheimes Token).
 * Steht in allen Kunden-Mails; wie lange er nach Abschluss gilt, regelt die
 * Einstellung checkout.order_link_days (Domain\Order\OrderLink).
 */
final class OrderStatusLink
{
    public static function url(Order $order, Repository $settings): string
    {
        $page_id = absint($settings->get('general.checkout_page_id', 0));
        $base    = $page_id > 0 ? get_permalink($page_id) : false;

        return $base && $order->checkout_token !== null ? add_query_arg('kontor_order', $order->checkout_token, (string) $base) : '';
    }

    public static function days(Repository $settings): int
    {
        return OrderLink::sanitize_days($settings->get('checkout.order_link_days', OrderLink::DEFAULT_DAYS));
    }
}
