<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Seo;

/**
 * Seiten, die nie in Suchmaschinen gehoeren: Warenkorb, Kasse (inkl.
 * Bestellbestaetigung/-status ueber ?kontor_order), Konto.
 *
 * Signal: Meta-Robots UND X-Robots-Tag, aber bewusst KEIN robots.txt-
 * Disallow - sonst liest der Bot die Seite nie und sieht das noindex nicht.
 * Diese Klasse kennt daher nur "noindex ja/nein" und erzeugt nie
 * robots.txt-Zeilen.
 */
final class NoIndexPolicy
{
    /** Shortcodes, die eine Seite privat machen - egal auf welcher Seite sie stehen. */
    public const PRIVATE_SHORTCODES = [
        'kontor_shop_cart',
        'kontor_shop_checkout',
        'kontor_shop_order_status',
        'kontor_shop_account',
    ];

    /** Query-Parameter, die eine Seite privat machen (Bestelltoken). */
    public const PRIVATE_PARAMS = ['kontor_order'];

    public const HEADER = 'noindex, nofollow';

    /**
     * @param array<int, int> $private_page_ids Warenkorb-/Kassen-/Kontoseiten laut Einstellungen
     * @param array<string, mixed> $query Query-Parameter der Anfrage
     */
    public static function is_private_page(?int $page_id, string $content, array $private_page_ids, array $query = []): bool
    {
        if ($page_id !== null && $page_id > 0 && in_array($page_id, array_map('intval', $private_page_ids), true)) {
            return true;
        }

        foreach (self::PRIVATE_PARAMS as $param) {
            if (isset($query[$param])) {
                return true;
            }
        }

        foreach (self::PRIVATE_SHORTCODES as $shortcode) {
            if (preg_match('/\[' . preg_quote($shortcode, '/') . '(\s[^\]]*)?\]/', $content) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ein Parameter-Aufruf einer indexierbaren Seite (z. B. ?add-to-cart=5,
     * ?utm_source=...) zeigt per Canonical auf die saubere URL. Erlaubt sind nur
     * die Parameter, die eine eigene Seite ausmachen (Paginierung).
     *
     * @param array<string, mixed> $query
     */
    public static function canonical_query(array $query): array
    {
        $keep = [];

        if (isset($query['kseite']) && (int) $query['kseite'] > 1) {
            $keep['kseite'] = (int) $query['kseite'];
        }

        return $keep;
    }
}
