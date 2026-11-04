<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Seo;

/**
 * Meldet "oeffentlich sichtbare Katalogdaten haben sich geaendert" - neutral,
 * ohne Bezug zu einem SEO-Plugin. Der Meridian-Adapter haengt sich hier ein
 * und leert Sammlungs-Cache, Sitemap und llms.txt (Pro: Pro\Seo\MeridianAdapter).
 *
 * Gemeldet wird nur bei relevanten Aenderungen (Domain\Seo\ContentChange),
 * nie bei jeder Bestandsbuchung. Pro Anfrage hoechstens einmal, damit eine
 * Sammelaktion ueber 500 Artikel den Cache nicht 500-mal leert.
 */
final class CatalogEvents
{
    public const ACTION = 'kontor_shop/catalog/changed';

    private static bool $fired = false;

    public static function changed(?int $product_id = null): void
    {
        if (self::$fired) {
            return;
        }

        self::$fired = true;

        do_action(self::ACTION, $product_id);
    }

    /** Fuer Tests und lang laufende Prozesse (WP-CLI). */
    public static function reset(): void
    {
        self::$fired = false;
    }
}
