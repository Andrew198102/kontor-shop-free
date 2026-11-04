<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Privacy;

/**
 * Datenschutz-Export und -Loeschung fuer Verfuegbarkeitsanfragen
 * ("Benachrichtigen, wenn verfuegbar", Tabelle kontor_shop_stock_requests).
 * Angelegt werden sie nur mit Pro; Auskunft und Loeschung stehen bewusst im
 * Kern (schlichtes SQL, keine Pro-Klasse), damit der Shop sie auch nach einem
 * Downgrade bedienen kann. Keine Aufbewahrungspflicht (kein Vertrag) - es
 * wird wirklich geloescht.
 */
final class StockRequestData
{
    private static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kontor_shop_stock_requests';
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, done: bool}
     */
    public static function export(string $email, int $page = 1): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM %i WHERE email = %s ORDER BY id ASC', self::table(), strtolower(trim($email))),
            ARRAY_A
        );
        $data = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $item     = (string) ($row['product_name'] ?? '');
            $variant  = (string) ($row['variant_label'] ?? '');
            $quantity = (int) ($row['quantity'] ?? 0);

            $data[] = [
                'group_id'          => 'kontor-shop-stock-requests',
                'group_label'       => __('Back-in-stock requests', 'kontor-shop'),
                'group_description' => __('“When back in stock” notifications requested with this email address.', 'kontor-shop'),
                'item_id'           => 'kontor-shop-stock-request-' . (int) ($row['id'] ?? 0),
                'data'              => [
                    ['name' => __('Product', 'kontor-shop'), 'value' => $variant !== '' ? $item . ' – ' . $variant : $item],
                    ['name' => __('Requested on', 'kontor-shop'), 'value' => (string) ($row['created_at'] ?? '')],
                    ['name' => __('Name', 'kontor-shop'), 'value' => (string) ($row['name'] ?? '')],
                    ['name' => __('Email', 'kontor-shop'), 'value' => (string) ($row['email'] ?? '')],
                    ['name' => __('Requested quantity', 'kontor-shop'), 'value' => $quantity > 0 ? (string) $quantity : ''],
                    ['name' => __('Message', 'kontor-shop'), 'value' => (string) ($row['message'] ?? '')],
                    ['name' => __('Status', 'kontor-shop'), 'value' => (string) ($row['status'] ?? '')],
                    ['name' => __('Confirmed on', 'kontor-shop'), 'value' => (string) ($row['confirmed_at'] ?? '')],
                    ['name' => __('Notified on', 'kontor-shop'), 'value' => (string) ($row['notified_at'] ?? '')],
                ],
            ];
        }

        return ['data' => $data, 'done' => true];
    }

    /**
     * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
     */
    public static function erase(string $email, int $page = 1): array
    {
        global $wpdb;

        $deleted = $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE email = %s', self::table(), strtolower(trim($email))));

        return [
            'items_removed'  => (int) $deleted > 0,
            'items_retained' => false,
            'messages'       => [],
            'done'           => true,
        ];
    }
}
