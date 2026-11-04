<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Orders;

use Kontor\Shop\Domain\Order\Order;

/**
 * "Fuehrende Kassa": bucht ein angebundenes System (z. B. eine Vereinskassa
 * ueber die Kassen-Schnittstelle) Zahlungen und Rueckzahlungen selbst,
 * blendet der Shop "Als bezahlt markieren" und "Rueckzahlung erledigt" aus
 * und verlinkt stattdessen dorthin (WP-Admins behalten einen Notausgang).
 *
 * Der Kern kennt nur die Andockpunkte; ohne Erweiterung gibt es keine
 * fuehrende Kassa und nichts ist gesperrt.
 */
final class ExternalPayments
{
    /**
     * @return array{client_id: string, label: string, url: string, since: string}|null
     */
    public static function owner(): ?array
    {
        /**
         * Filter kontor_shop/payments/owner: welches angebundene System gerade
         * die Zahlungen bucht. Standard null = keines (der Shop bucht selbst).
         *
         * @param array{client_id: string, label: string, url: string, since: string}|null $owner
         */
        $owner = apply_filters('kontor_shop/payments/owner', null);

        if (!is_array($owner) || trim((string) ($owner['label'] ?? '')) === '') {
            return null;
        }

        return [
            'client_id' => (string) ($owner['client_id'] ?? ''),
            'label'     => (string) $owner['label'],
            'url'       => (string) ($owner['url'] ?? ''),
            'since'     => (string) ($owner['since'] ?? ''),
        ];
    }

    public static function active(): bool
    {
        return self::owner() !== null;
    }

    /**
     * Link zur Bestellung im System der fuehrenden Kassa ('' = kein Link bekannt).
     */
    public static function order_url(string $reference, ?Order $order = null): string
    {
        /**
         * Filter kontor_shop/payments/owner_order_url: Link zur Bestellung in
         * der fuehrenden Kassa. Standard '' = kein Link.
         *
         * @param string     $url       bisheriger Link ('')
         * @param string     $reference Zahlungsreferenz der Bestellung
         * @param Order|null $order     die Bestellung, falls zur Hand
         */
        $url = (string) apply_filters('kontor_shop/payments/owner_order_url', '', $reference, $order);

        return preg_match('#^https?://#i', $url) === 1 ? $url : '';
    }
}
