<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Cart;

use Kontor\Shop\Domain\Cart\Cart;

/**
 * Persistiert den Warenkorb in einem Cookie (Artikel-ID + Menge als JSON) -
 * kein eigenes Kundenkonto/keine Server-Session noetig (Gastbestellung,
 * Abschnitt 3 Ziel 8). Preise stehen nie im Cookie, daher ist eine
 * Manipulation folgenlos: Mengen werden beim Verarbeiten stets gegen den
 * aktuellen Bestand gekappt (Modules\Cart\CartModule).
 */
final class CookieCartStore
{
    public const COOKIE_NAME = 'kontor_shop_cart';

    private const TTL = 30 * DAY_IN_SECONDS;

    public function load(): Cart
    {
        if (!isset($_COOKIE[self::COOKIE_NAME])) {
            return new Cart();
        }

        // WordPress versieht $_COOKIE mit Slashes (wp_magic_quotes) - ohne
        // wp_unslash() waere das JSON ({\"12\":1}) ungueltig und der Warenkorb
        // bei jedem neuen Request leer.
        $decoded = json_decode(wp_unslash((string) $_COOKIE[self::COOKIE_NAME]), true); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON; Cart::from_array() wandelt jede ID und Menge in int

        if (!is_array($decoded)) {
            return new Cart();
        }

        return Cart::from_array($decoded);
    }

    public function save(Cart $cart): void
    {
        if ($cart->is_empty()) {
            $this->clear();

            return;
        }

        $value = (string) wp_json_encode($cart->to_array());

        setcookie(self::COOKIE_NAME, $value, [
            'expires'  => time() + self::TTL,
            'path'     => COOKIEPATH ?: '/',
            'domain'   => COOKIE_DOMAIN ?: '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $_COOKIE[self::COOKIE_NAME] = $value;
    }

    public function clear(): void
    {
        setcookie(
            self::COOKIE_NAME,
            '',
            time() - HOUR_IN_SECONDS,
            COOKIEPATH ?: '/',
            COOKIE_DOMAIN ?: ''
        );

        unset($_COOKIE[self::COOKIE_NAME]);
    }
}
