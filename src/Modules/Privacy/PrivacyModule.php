<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Privacy;

use Kontor\Shop\Core\Registerable;
use Kontor\Shop\Domain\Order\Order;
use Kontor\Shop\Infrastructure\Repositories\OrderRepository;
use Kontor\Shop\Settings\Repository;

/**
 * Anbindung an die WordPress-Datenschutz-Werkzeuge (Werkzeuge -> Personenbezogene
 * Daten exportieren/loeschen):
 *  - Exporter: liefert alle Bestellungen zu einer E-Mail-Adresse,
 *  - Eraser: anonymisiert Bestellungen (Name, E-Mail, Adresse), aber erst nach
 *    Ablauf der Aufbewahrungsfrist (Standard 7 Jahre, Einstellung
 *    privacy.retention_years) - Bestell-/Rechnungsdaten sind Buchhaltungs-
 *    unterlagen und duerfen vorher nicht geloescht werden. Innerhalb der Frist
 *    meldet der Eraser die Daten als "aufbewahrt" mit Begruendung,
 *  - Verfuegbarkeitsanfragen (Tabelle stock_requests, StockRequestData):
 *    Export und Loeschung bleiben im Kern, auch wenn nur Pro neue anlegt -
 *    so kann der Shop Auskunft geben, auch nach einem Downgrade,
 *  - Textbaustein fuer die Datenschutzerklaerung (Einstellungen -> Datenschutz),
 *    Erweiterungen ergaenzen Abschnitte ueber kontor_shop/privacy/policy_sections.
 * Bestellungen ganz zu loeschen bleibt eine bewusste manuelle Handlung im Backend.
 */
final class PrivacyModule implements Registerable
{
    private const PER_PAGE = 20;

    private OrderRepository $orders;

    public function __construct(private readonly Repository $settings)
    {
        $this->orders = new OrderRepository();
    }

    public function register(): void
    {
        add_filter('wp_privacy_personal_data_exporters', [$this, 'register_exporter']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'register_eraser']);
        add_action('admin_init', [$this, 'add_policy_content']);
    }

    /**
     * @param array<string, mixed> $exporters
     * @return array<string, mixed>
     */
    public function register_exporter(array $exporters): array
    {
        $exporters['kontor-shop'] = [
            'exporter_friendly_name' => __('Kontor Shop: Orders', 'kontor-shop'),
            'callback'               => [$this, 'export'],
        ];
        $exporters['kontor-shop-stock-requests'] = [
            'exporter_friendly_name' => __('Kontor Shop: Availability requests', 'kontor-shop'),
            'callback'               => [StockRequestData::class, 'export'],
        ];

        return $exporters;
    }

    /**
     * @param array<string, mixed> $erasers
     * @return array<string, mixed>
     */
    public function register_eraser(array $erasers): array
    {
        $erasers['kontor-shop'] = [
            'eraser_friendly_name' => __('Kontor Shop: Orders', 'kontor-shop'),
            'callback'             => [$this, 'erase'],
        ];
        $erasers['kontor-shop-stock-requests'] = [
            'eraser_friendly_name' => __('Kontor Shop: Availability requests', 'kontor-shop'),
            'callback'             => [StockRequestData::class, 'erase'],
        ];

        return $erasers;
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, done: bool}
     */
    public function export(string $email, int $page = 1): array
    {
        $orders = $this->orders->for_email($email, $page, self::PER_PAGE);
        $data   = [];

        foreach ($orders as $order) {
            $items = array_map(
                static fn ($item): string => $item->quantity . 'x ' . $item->product_name,
                $this->orders->items_for_order((int) $order->id)
            );

            $data[] = [
                'group_id'          => 'kontor-shop-orders',
                'group_label'       => __('Shop orders', 'kontor-shop'),
                'group_description' => __('Orders placed with this email address.', 'kontor-shop'),
                'item_id'           => 'kontor-shop-order-' . $order->id,
                'data'              => [
                    ['name' => __('Order number', 'kontor-shop'), 'value' => $order->order_number],
                    ['name' => __('Order date', 'kontor-shop'), 'value' => (string) $order->created_at],
                    ['name' => __('Name', 'kontor-shop'), 'value' => $order->customer_name],
                    ['name' => __('Email', 'kontor-shop'), 'value' => $order->customer_email],
                    ['name' => __('Phone', 'kontor-shop'), 'value' => (string) $order->customer_phone],
                    ['name' => __('Billing address', 'kontor-shop'), 'value' => trim(($order->billing_address ?? '') . ($order->billing_vat_id ? ' / UID ' . $order->billing_vat_id : ''))],
                    ['name' => __('Shipping address', 'kontor-shop'), 'value' => $order->self_pickup ? __('Self pickup', 'kontor-shop') : (string) $order->delivery_address],
                    ['name' => __('Items', 'kontor-shop'), 'value' => implode('; ', $items)],
                    ['name' => __('Total (euros)', 'kontor-shop'), 'value' => number_format($order->total, 2, ',', '.')],
                    ['name' => __('Status', 'kontor-shop'), 'value' => $order->status],
                    ['name' => __('Consent to terms and conditions/privacy policy', 'kontor-shop'), 'value' => (string) $order->legal_accepted_at],
                ],
            ];
        }

        return ['data' => $data, 'done' => count($orders) < self::PER_PAGE];
    }

    /**
     * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
     */
    public function erase(string $email, int $page = 1): array
    {
        $years    = max(0, (int) $this->settings->get('privacy.retention_years', 7));
        $removed  = false;
        $retained = false;
        $now      = time();

        // Alles in einem Durchgang: anonymisierte Bestellungen verschwinden aus der
        // E-Mail-Suche, seitenweises Weiterblaettern wuerde sonst Bestellungen ueberspringen.
        $orders = $this->orders->for_email($email, 1, 1000);

        foreach ($orders as $order) {
            if (self::is_erasable($order->created_at, $now, $years)) {
                $this->orders->anonymize((int) $order->id);
                $removed = true;

                continue;
            }

            $retained = true;
        }

        $messages = [];

        if ($retained) {
            $messages[] = sprintf(
                /* translators: %d: retention period in years */
                __('Order data isn\'t deleted yet because of the legal retention obligation (%d years) and will be anonymized after the period ends.', 'kontor-shop'),
                $years
            );
        }

        return [
            'items_removed'  => $removed,
            'items_retained' => $retained,
            'messages'       => $messages,
            'done'           => true,
        ];
    }

    /**
     * Darf die Bestellung anonymisiert werden? Erst wenn die Aufbewahrungsfrist
     * (in Jahren ab Bestelldatum) abgelaufen ist.
     */
    public static function is_erasable(?string $created_at, int $now, int $retention_years): bool
    {
        if ($created_at === null || $created_at === '') {
            return false;
        }

        $created = strtotime($created_at);

        if ($created === false) {
            return false;
        }

        return strtotime('+' . $retention_years . ' years', $created) <= $now;
    }

    public function add_policy_content(): void
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        $content = '<h2>' . esc_html__('Web shop (Kontor Shop)', 'kontor-shop') . '</h2>'
            . '<p class="privacy-policy-tutorial">' . esc_html__('Suggested text for the privacy policy - please adapt it to your club and have it legally reviewed.', 'kontor-shop') . '</p>'
            . implode('', array_filter(array_map('strval', $this->policy_sections())));

        wp_add_privacy_policy_content(__('Kontor Shop', 'kontor-shop'), wp_kses_post($content));
    }

    /**
     * Abschnitte des Textbausteins (Ueberschrift + Absatz, fertiges HTML).
     *
     * @return array<string, string>
     */
    public function policy_sections(): array
    {
        $years = max(0, (int) $this->settings->get('privacy.retention_years', 7));

        $sections = [
            'orders'     => '<h3>' . esc_html__('What data we process for orders', 'kontor-shop') . '</h3>'
                . '<p>' . esc_html__(
                    'When you place an order, we collect your name, email address and - for delivery - your delivery address, as well as details about the order (products, prices, payment method, payment reference) and the time you agreed to the terms and conditions and privacy policy. There are no customer accounts and no online payment; payments are made by bank transfer (prepayment or on invoice), and we don\'t store any bank details from you.',
                    'kontor-shop'
                ) . '</p>',
            'purpose'    => '<h3>' . esc_html__('Purpose and legal basis', 'kontor-shop') . '</h3>'
                . '<p>' . esc_html__(
                    'We process the data to carry out the purchase contract (Art. 6(1)(b) GDPR) and to fulfill legal retention obligations (Art. 6(1)(c) GDPR).',
                    'kontor-shop'
                ) . '</p>',
            'retention'  => '<h3>' . esc_html__('Retention period', 'kontor-shop') . '</h3>'
                . '<p>' . esc_html(sprintf(
                    /* translators: %d: years */
                    __('We keep order data for %d years because of tax and corporate law obligations and anonymize it afterward on request.', 'kontor-shop'),
                    $years
                )) . '</p>',
            'recipients' => '<h3>' . esc_html__('Recipients', 'kontor-shop') . '</h3>'
                . '<p>' . esc_html__(
                    'Recipients are our hosting provider, the email service we use (to send order confirmations), and the warehouse responsible for your order, which receives your delivery address to ship the goods.',
                    'kontor-shop'
                ) . '</p>',
            'cookies'    => '<h3>' . esc_html__('Cookies', 'kontor-shop') . '</h3>'
                . '<p>' . esc_html__(
                    'The cart is stored in a technically necessary cookie ("kontor_shop_cart"). It contains only products and quantities, no personal data.',
                    'kontor-shop'
                ) . '</p>',
        ];

        /**
         * Filter kontor_shop/privacy/policy_sections: Abschnitte des
         * Textbausteins fuer die Datenschutzerklaerung (Einstellungen ->
         * Datenschutz) ergaenzen, z. B. fuer Verfuegbarkeitsanfragen. Die
         * Reihenfolge der Schluessel ist die im Text. Standard: Bestellungen
         * (orders, purpose, retention, recipients) und cookies.
         *
         * @param array<string, string> $sections Schluessel => HTML (h3 + Absaetze, escaped)
         */
        $filtered = apply_filters('kontor_shop/privacy/policy_sections', $sections);

        return is_array($filtered) ? $filtered : $sections;
    }
}
