<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Catalog;

use Kontor\Shop\Modules\Payments\PaymentOptions;
use Kontor\Shop\Settings\Repository;
use Kontor\Shop\Support\Countries;

/**
 * Kurze Infozeilen auf der Artikelseite (unter dem Kaufknopf): Versand,
 * Selbstabholung, Bezahlen - alles aus den Shop-Einstellungen, nichts
 * erfunden. Was nicht eingestellt ist, erscheint nicht. Die genauen
 * Versandkosten (z. B. bei Lieferung aus mehreren Lagern) zeigt die Kasse.
 */
final class ServiceInfo
{
    /**
     * @return array<string, array{icon: string, text: string}> Schluessel shipping|pickup|payment (Reihenfolge = Anzeige)
     */
    public static function lines(Repository $settings): array
    {
        $lines   = [];
        $rate    = round((float) $settings->get('shipping.flat_rate', 0), 2);
        $free    = round((float) $settings->get('shipping.free_from_amount', 0), 2);
        $where   = self::countries($settings);
        $payment = new PaymentOptions($settings);

        if ($rate <= 0) {
            $text = $where !== ''
                /* translators: %s: countries, e.g. "Austria and Germany" */
                ? sprintf(__('Free shipping to %s', 'kontor-shop'), $where)
                : __('Free shipping', 'kontor-shop');
        } else {
            $text = $where !== ''
                /* translators: 1: countries, e.g. "Austria and Germany", 2: shipping cost such as "6,90 €" */
                ? sprintf(__('Shipping to %1$s: %2$s', 'kontor-shop'), $where, self::money($rate))
                /* translators: %s: shipping cost such as "6,90 €" */
                : sprintf(__('Shipping: %s', 'kontor-shop'), self::money($rate));

            if ($free > 0) {
                /* translators: %s: order value such as "100 €" */
                $text .= ' – ' . sprintf(__('free from %s', 'kontor-shop'), self::money($free));
            }
        }

        $lines['shipping'] = ['icon' => 'truck', 'text' => $text];

        if ((bool) $settings->get('shipping.allow_self_pickup', true)) {
            $lines['pickup'] = [
                'icon' => 'pickup',
                'text' => $payment->cash_enabled()
                    ? __('Or pick it up yourself for free – and pay in cash if you like', 'kontor-shop')
                    : __('Or pick it up yourself for free', 'kontor-shop'),
            ];
        }

        $methods = [];

        if ($payment->prepayment_enabled()) {
            $methods['prepayment'] = PaymentOptions::qr_enabled($settings)
                ? __('bank transfer with QR code for your banking app', 'kontor-shop')
                : __('bank transfer', 'kontor-shop');
        }

        if ($payment->invoice_enabled()) {
            $methods['invoice'] = __('on invoice', 'kontor-shop');
        }

        /**
         * Filter kontor_shop/product/payment_methods: Zahlungsarten fuer die
         * Infozeile "Bezahlen per ..." (Schluessel prepayment|invoice, Text
         * klein), z. B. Pro: "auf Rechnung (für Mitglieder)".
         *
         * @param array<string, string> $methods
         * @param Repository            $settings
         */
        $methods = array_filter(array_map('strval', (array) apply_filters('kontor_shop/product/payment_methods', $methods, $settings)));

        if ($methods !== []) {
            /* translators: %s: payment methods, e.g. "bank transfer, on invoice" */
            $lines['payment'] = ['icon' => 'card', 'text' => sprintf(__('Pay by %s', 'kontor-shop'), implode(', ', $methods))];
        }

        /**
         * Filter kontor_shop/product/service_lines: Infozeilen auf der
         * Artikelseite ergaenzen oder aendern, z. B. Pro: "Mitglieder zahlen
         * keinen Versand", "Auf Rechnung nur fuer Mitglieder". Icons: truck,
         * pickup, card, info. Texte werden escaped.
         *
         * @param array<string, array{icon: string, text: string}> $lines
         * @param Repository                                        $settings
         */
        $lines = (array) apply_filters('kontor_shop/product/service_lines', $lines, $settings);

        return array_filter($lines, static fn ($line): bool => is_array($line) && isset($line['text']) && (string) $line['text'] !== '');
    }

    public static function render(Repository $settings): string
    {
        $lines = self::lines($settings);

        if ($lines === []) {
            return '';
        }

        $html = '<ul class="kontor-shop-detail__service">';

        foreach ($lines as $key => $line) {
            $html .= '<li class="kontor-shop-detail__service-' . esc_attr((string) $key) . '">' . self::icon((string) ($line['icon'] ?? 'info'))
                . '<span>' . esc_html((string) $line['text']) . '</span></li>';
        }

        return $html . '</ul>';
    }

    /** "Österreich und Deutschland", "Österreich, Deutschland und Schweiz"; '' ohne Laenderliste. */
    private static function countries(Repository $settings): string
    {
        $names = array_values(array_filter(array_map(
            static fn ($code): string => Countries::name((string) $code),
            (array) $settings->get('checkout.countries', [])
        )));

        if (count($names) <= 1) {
            return $names[0] ?? '';
        }

        $last = array_pop($names);

        /* translators: 1: list of countries, 2: last country */
        return sprintf(__('%1$s and %2$s', 'kontor-shop'), implode(', ', $names), $last);
    }

    private static function money(float $amount): string
    {
        return LabelCard::price_label($amount);
    }

    private static function icon(string $name): string
    {
        $paths = [
            'truck'  => '<path d="M3 7h11v9H3zM14 10h4l3 3v3h-7" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><circle cx="7" cy="17.5" r="1.8" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="17" cy="17.5" r="1.8" fill="none" stroke="currentColor" stroke-width="1.8"/>',
            'pickup' => '<path d="M4 10l8-6 8 6v10H4z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M10 20v-5h4v5" fill="none" stroke="currentColor" stroke-width="1.8"/>',
            'card'   => '<rect x="3" y="6" width="18" height="12" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M3 10h18" stroke="currentColor" stroke-width="1.8"/>',
            'info'   => '<circle cx="12" cy="12" r="8.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M12 11v5M12 8v.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
        ];

        return '<svg aria-hidden="true" viewBox="0 0 24 24" width="22" height="22">' . ($paths[$name] ?? $paths['info']) . '</svg>';
    }
}
