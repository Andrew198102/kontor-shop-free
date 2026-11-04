<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Seo;

use Kontor\Shop\Core\Registerable;
use Kontor\Shop\Domain\Seo\UrlScheme;
use Kontor\Shop\Infrastructure\Seo\PublicCatalog;
use Kontor\Shop\Settings\Repository;

/**
 * SEO-Grundlage (Plan 5.4): virtuelle Artikelseiten (/shop/artikel/…, mit
 * 301/410/404/503), noindex fuer Kassenseiten und ein kleiner eigener Kopf
 * (FallbackHead: Titel, Beschreibung, Canonical).
 *
 * Erweiterungen haengen sich ueber Filter an, z. B. Pro (Pro\Seo):
 * Kategorieseiten (kontor_shop/seo/rewrite_rules, .../virtual_page,
 * .../category_url), Product-JSON-LD (kontor_shop/seo/head) und die
 * Meridian-SEO-Anbindung - dann ohne eigenen Kopf
 * (kontor_shop/seo/fallback_head), damit es keine doppelten Tags gibt.
 */
final class SeoModule implements Registerable
{
    public const FLUSH_OPTION = 'kontor_shop_flush_rewrite';

    /** Beim Deaktivieren gesetzt, damit die Regeln beim Leeren nicht wieder mitgeneriert werden. */
    public static bool $rules_disabled = false;

    public function __construct(private readonly Repository $settings)
    {
    }

    public function register(): void
    {
        $urls    = new UrlScheme(self::base_path());
        $links   = new Links($this->settings, $urls);
        $catalog = new PublicCatalog($urls);
        $private = new PrivatePages($this->settings);

        (new RouteController($this->settings, $links, $catalog, new PageRenderer($links)))->register();
        $private->register();

        add_action('init', [$this, 'maybe_flush_rewrite'], 99);

        /**
         * Filter kontor_shop/seo/fallback_head: eigenen Kopf der Shop-Seiten
         * (Titel, Beschreibung, Canonical, noindex der Kassenseiten,
         * Sitemap-Ausschluss) ausgeben? false, wenn eine Erweiterung das
         * uebernimmt - z. B. Pro, wenn Meridian SEO aktiv ist. Muss vor dem
         * Kern-Boot gesetzt sein. Standard: true.
         *
         * @param bool $enabled
         */
        if ((bool) apply_filters('kontor_shop/seo/fallback_head', true)) {
            (new FallbackHead($links, $private))->register();
        }
    }

    public static function base_path(): string
    {
        $base = sanitize_title((string) apply_filters('kontor_shop/seo/base_path', 'shop'));

        return $base !== '' ? $base : 'shop';
    }

    public function maybe_flush_rewrite(): void
    {
        if (get_option(self::FLUSH_OPTION)) {
            delete_option(self::FLUSH_OPTION);
            flush_rewrite_rules(false);
        }
    }

    public static function remove_rules_and_flush(): void
    {
        self::$rules_disabled = true;
        flush_rewrite_rules(false);
    }
}
