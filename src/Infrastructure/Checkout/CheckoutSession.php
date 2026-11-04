<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Checkout;

/**
 * Anonyme Session-Kennung fuer den Checkout (getrennt vom Warenkorb-Cookie),
 * damit stock_reservations.session_key einen Besucher ohne Login eindeutig
 * identifiziert (Abschnitt 6.1).
 */
final class CheckoutSession
{
    public const COOKIE_NAME = 'kontor_shop_session';

    private const TTL = HOUR_IN_SECONDS;

    /** Die vorhandene Sitzungskennung, ohne eine neue anzulegen (kein Cookie setzen). */
    public static function existing_key(): ?string
    {
        $existing = self::cookie_value();

        return is_string($existing) && preg_match('/^[a-zA-Z0-9]{20,64}$/', $existing) === 1 ? $existing : null;
    }

    public static function key(): string
    {
        $existing = self::cookie_value();

        if (is_string($existing) && preg_match('/^[a-zA-Z0-9]{20,64}$/', $existing) === 1) {
            return $existing;
        }

        $key = wp_generate_password(32, false, false);

        setcookie(self::COOKIE_NAME, $key, [
            'expires'  => time() + self::TTL,
            'path'     => COOKIEPATH ?: '/',
            'domain'   => COOKIE_DOMAIN ?: '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $_COOKIE[self::COOKIE_NAME] = $key;

        return $key;
    }

    /** Roher Cookie-Wert, bereinigt; das Format prueft der Aufrufer per Regex. */
    private static function cookie_value(): string
    {
        if (!isset($_COOKIE[self::COOKIE_NAME]) || !is_string($_COOKIE[self::COOKIE_NAME])) {
            return '';
        }

        return sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE_NAME]));
    }
}
