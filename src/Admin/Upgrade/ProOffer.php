<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin\Upgrade;

use Kontor\Shop\Modules\Notifications\PoweredBy;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Das Pro-Angebot fuer "Pläne & Preise" (PricingPage) und die Free-Seite
 * "KI-Assistent": Pakete (1, 5 ... Websites) mit Jahres- und Einmalpreis,
 * Kauf-Links, Geld-zurueck-Frist und ob Pro nach Ablauf abschaltet. Der Kern
 * kennt Freemius nicht - src/freemius.php haengt sich an
 * kontor_shop/pro_offer und liefert alles aus pricing.json. Ohne
 * freigegebenen Plan bleibt es bei der Website, ohne Preise.
 */
final class ProOffer
{
    private const SYMBOLS = ['eur' => '€', 'usd' => '$', 'gbp' => '£', 'chf' => 'CHF'];

    /**
     * @return array{url: string, annual: string, lifetime: string, packages: array<int, array<string, mixed>>, refund_days: int, blocks_after_expiry: ?bool, account_url: string, pending_email: string, install_url: string}
     */
    public static function get(): array
    {
        $offer = array_merge(self::fallback(), (array) apply_filters('kontor_shop/pro_offer', self::fallback()));

        return [
            'url'                 => (string) $offer['url'],
            'annual'              => (string) $offer['annual'],
            'lifetime'            => (string) $offer['lifetime'],
            'packages'            => array_values((array) $offer['packages']),
            'refund_days'         => (int) $offer['refund_days'],
            'blocks_after_expiry' => $offer['blocks_after_expiry'] === null ? null : (bool) $offer['blocks_after_expiry'],
            'account_url'         => (string) $offer['account_url'],
            'pending_email'       => self::status()['pending_email'],
            'install_url'         => self::status()['install_url'],
        ];
    }

    /**
     * Stand von Kauf/Lizenz (src/freemius.php, kontor_shop/pro_status) - ohne Preisabfrage,
     * fuer den Hinweis auf allen Kontor-Seiten.
     *
     * install_ajax: Felder fuer admin-ajax, mit denen Freemius Pro herunterlaedt,
     * installiert und aktiviert (leer = nichts zu installieren).
     *
     * licence: Plan, Websites, Ablauf, Konto-Link (nur mit aktiver Pro-Version).
     *
     * @return array{pending_email: string, install_url: string, install_ajax: array<string, string>, licence: array<string, string>}
     */
    public static function status(): array
    {
        $status = (array) apply_filters('kontor_shop/pro_status', ['pending_email' => '', 'install_url' => '', 'install_ajax' => [], 'licence' => []]);

        return [
            'pending_email' => (string) ($status['pending_email'] ?? ''),
            'install_url'   => (string) ($status['install_url'] ?? ''),
            'install_ajax'  => array_map('strval', (array) ($status['install_ajax'] ?? [])),
            'licence'       => array_map('strval', (array) ($status['licence'] ?? [])),
        ];
    }

    /** @return array<string, mixed> */
    public static function fallback(): array
    {
        return [
            'url'                 => PoweredBy::URL,
            'annual'              => '',
            'lifetime'            => '',
            'packages'            => [],
            'refund_days'         => 0,
            'blocks_after_expiry' => null,
            'account_url'         => '',
        ];
    }

    /**
     * Angebot aus der Freemius-Antwort pricing.json: der Plan mit Preisen
     * (bevorzugt der hervorgehobene), je Paket Jahres- und Einmalpreis samt
     * Kauf-Link ($buy: fn (int $plan_id, int $licenses, string $cycle): string),
     * dazu jeweils der guenstigste Preis als Text.
     *
     * @param array<string, mixed> $offer
     * @param array<string, mixed> $pricing
     * @return array<string, mixed>
     */
    public static function with_pricing(array $offer, array $pricing, string $checkout_url, ?callable $buy = null): array
    {
        $plan = null;

        foreach ((array) ($pricing['plans'] ?? []) as $candidate) {
            $candidate = (array) $candidate;
            $paid      = array_filter((array) ($candidate['pricing'] ?? []), static fn ($price): bool => self::amount((array) $price, 'annual_price') > 0 || self::amount((array) $price, 'lifetime_price') > 0);

            if ($paid !== [] && ($plan === null || !empty($candidate['is_featured']))) {
                $plan = $candidate + ['paid' => $paid];
            }
        }

        if ($plan === null) {
            return $offer;
        }

        $packages = [];

        foreach ($plan['paid'] as $price) {
            $price    = (array) $price;
            $licenses = max(1, (int) ($price['licenses'] ?? 1));
            $annual   = self::amount($price, 'annual_price');
            $lifetime = self::amount($price, 'lifetime_price');

            $packages[$licenses] = [
                'licenses'     => $licenses,
                'currency'     => strtolower((string) ($price['currency'] ?? 'eur')),
                'annual'       => $annual,
                'lifetime'     => $lifetime,
                'buy_annual'   => $buy !== null && $annual > 0 ? (string) $buy((int) ($plan['id'] ?? 0), $licenses, 'annual') : $checkout_url,
                'buy_lifetime' => $buy !== null && $lifetime > 0 ? (string) $buy((int) ($plan['id'] ?? 0), $licenses, 'lifetime') : $checkout_url,
            ];
        }

        ksort($packages);
        $cheapest = static function (string $field) use ($packages): string {
            $amounts = array_filter(array_map(static fn (array $p): array => [$p[$field], $p['currency']], $packages), static fn (array $a): bool => $a[0] > 0);
            usort($amounts, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

            return $amounts !== [] ? self::money($amounts[0][0], $amounts[0][1]) : '';
        };

        return array_merge($offer, [
            'url'                 => $checkout_url,
            'annual'              => $cheapest('annual'),
            'lifetime'            => $cheapest('lifetime'),
            'packages'            => array_values($packages),
            'refund_days'         => (int) ($pricing['money_back_period'] ?? 0),
            'blocks_after_expiry' => isset($plan['is_block_features']) ? (bool) $plan['is_block_features'] : null,
        ]);
    }

    public static function money(float $amount, string $currency): string
    {
        $decimals = floor($amount) === $amount ? 0 : 2;

        return number_format_i18n($amount, $decimals) . ' ' . (self::SYMBOLS[$currency] ?? strtoupper($currency));
    }

    /** @param array<string, mixed> $price */
    private static function amount(array $price, string $field): float
    {
        return is_numeric($price[$field] ?? null) ? (float) $price[$field] : 0.0;
    }
}
