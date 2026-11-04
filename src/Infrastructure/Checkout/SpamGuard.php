<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Checkout;

use Kontor\Shop\Settings\Repository;

/**
 * Einfacher Spam-/Missbrauchsschutz fuer den Gast-Checkout (ohne Captcha,
 * ohne externe Dienste):
 *  - Honeypot-Feld, das Menschen nie ausfuellen,
 *  - signierter Zeitstempel: zu schnell abgeschickte Formulare (Bots) und
 *    Formulare, die aelter als 24 h sind, werden abgelehnt,
 *  - Begrenzung erfolgreicher Bestellungen pro IP (angemeldet: pro Konto)
 *    und 15 Minuten (Transient, Einstellung checkout.rate_limit unter
 *    Rechtliches), damit sich Reservierungen/Bestand nicht massenhaft
 *    blockieren lassen.
 * Die Pruefung selbst (evaluate) ist reine Logik und unit-testbar.
 */
final class SpamGuard
{
    public const HONEYPOT_FIELD = 'ks_website';
    public const TOKEN_FIELD    = 'ks_ts';

    private const MIN_SECONDS      = 3;
    private const MAX_SECONDS      = DAY_IN_SECONDS;
    /** Standard fuer checkout.rate_limit (bis 2.1.0 fest 5 - zu wenig fuer einen Vereinsabend im selben WLAN). */
    public const RATE_LIMIT_MAX    = 20;

    public const RATE_LIMIT_CEILING = 500;
    private const RATE_LIMIT_WINDOW = 15 * MINUTE_IN_SECONDS;

    public static function field_html(): string
    {
        return '<div class="kontor-shop-hp" aria-hidden="true">'
            . '<label>Website<input type="text" name="' . self::HONEYPOT_FIELD . '" value="" tabindex="-1" autocomplete="off" /></label>'
            . '<input type="hidden" name="' . self::TOKEN_FIELD . '" value="' . esc_attr(self::issue_token(time(), self::secret())) . '" />'
            . '</div>';
    }

    /**
     * @param array<string, mixed> $post
     */
    public static function check(array $post): ?string
    {
        $error = self::evaluate(
            (string) ($post[self::HONEYPOT_FIELD] ?? ''),
            (string) ($post[self::TOKEN_FIELD] ?? ''),
            time(),
            self::secret()
        );

        if ($error !== null) {
            return $error;
        }

        if (self::is_rate_limited()) {
            return __('Too many orders in a short time. Please try again in a few minutes.', 'kontor-shop');
        }

        return null;
    }

    public static function record_success(): void
    {
        $key   = self::rate_key();
        $count = (int) get_transient($key);

        set_transient($key, $count + 1, (int) apply_filters('kontor_shop/spam/rate_window', self::RATE_LIMIT_WINDOW));
    }

    public static function issue_token(int $now, string $secret): string
    {
        return $now . '.' . hash_hmac('sha256', (string) $now, $secret);
    }

    /**
     * Reine Pruefung ohne WordPress-Abhaengigkeit.
     *
     * @return string|null Fehlermeldung oder null, wenn alles in Ordnung ist
     */
    public static function evaluate(string $honeypot, string $token, int $now, string $secret): ?string
    {
        $reload = __('Please reload the page and submit the form again.', 'kontor-shop');

        if ($honeypot !== '') {
            return $reload;
        }

        $parts = explode('.', $token, 2);

        if (count($parts) !== 2 || !ctype_digit($parts[0])) {
            return $reload;
        }

        if (!hash_equals(hash_hmac('sha256', $parts[0], $secret), $parts[1])) {
            return $reload;
        }

        $age = $now - (int) $parts[0];

        if ($age < self::MIN_SECONDS || $age > self::MAX_SECONDS) {
            return $reload;
        }

        return null;
    }

    /** Einstellung checkout.rate_limit bereinigen (0 = keine Grenze). */
    public static function sanitize_limit(mixed $value): int
    {
        return is_numeric($value) ? max(0, min(self::RATE_LIMIT_CEILING, (int) $value)) : self::RATE_LIMIT_MAX;
    }

    private static function is_rate_limited(): bool
    {
        $setting = (new Repository())->get('checkout.rate_limit', self::RATE_LIMIT_MAX);
        $max     = (int) apply_filters('kontor_shop/spam/rate_limit', self::sanitize_limit($setting));

        return $max > 0 && (int) get_transient(self::rate_key()) >= $max;
    }

    private static function rate_key(): string
    {
        // Angemeldete Kunden (z. B. Mitglieder) zaehlen je Konto: viele Bestellungen aus einem
        // Vereins-WLAN sind dann kein Problem, Bots bleiben je Anschluss begrenzt.
        $user_id = function_exists('get_current_user_id') ? get_current_user_id() : 0;

        if ($user_id > 0) {
            return 'kontor_shop_rl_u' . $user_id;
        }

        // Hinter Reverse-Proxys kann die echte Client-IP per Filter geliefert werden.
        $ip = (string) apply_filters('kontor_shop/spam/client_ip', isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : '');

        return 'kontor_shop_rl_' . md5($ip);
    }

    private static function secret(): string
    {
        return wp_salt('nonce') . 'kontor-shop-spam';
    }
}
