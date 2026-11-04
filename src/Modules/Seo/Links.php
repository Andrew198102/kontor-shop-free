<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Seo;

use Kontor\Shop\Domain\Seo\NoIndexPolicy;
use Kontor\Shop\Domain\Seo\PublicProduct;
use Kontor\Shop\Domain\Seo\UrlScheme;
use Kontor\Shop\Settings\Repository;

/**
 * Absolute Adressen der Shop-Seiten - eine Stelle, damit Seiten, Meta,
 * Brotkrumen und Provider dieselben URLs verwenden.
 */
final class Links
{
    public function __construct(private readonly Repository $settings, private readonly UrlScheme $urls)
    {
    }

    public function urls(): UrlScheme
    {
        return $this->urls;
    }

    public function absolute(string $path): string
    {
        return home_url($path);
    }

    public function home(): string
    {
        return home_url('/');
    }

    /** Katalogseite: die als "Shop" angelegte WordPress-Seite, sonst /shop/. */
    public function shop(): string
    {
        $id  = (int) $this->settings->get('general.shop_page_id', 0);
        $url = $id > 0 ? get_permalink($id) : false;

        return is_string($url) && $url !== '' ? $url : $this->absolute('/' . $this->urls->base() . '/');
    }

    public function product(PublicProduct $product): string
    {
        return $this->absolute($this->urls->product_path($product->slug, $product->id));
    }

    public function category(string $slug, int $page = 1): string
    {
        return $this->absolute($this->urls->category_path($slug, $page));
    }

    /**
     * Canonical einer WordPress-Seite mit Katalog: Aufrufe mit Parametern
     * (?add-to-cart=…, ?utm_…) zeigen auf die saubere URL; nur die Seitenzahl
     * einer aufgeteilten Katalogseite (?kseite=2) ist eine eigene Seite und
     * verweist auf sich selbst.
     */
    public function catalog_canonical(string $url): string
    {
        $keep = NoIndexPolicy::canonical_query(is_array($_GET) ? $_GET : []); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige-Parameter, canonical_query liest nur kseite als (int)

        return $keep === [] ? $url : add_query_arg($keep, $url);
    }

    /**
     * Adresse einer Kategorieseite fuer Brotkrumen und die Zeile "Kategorie:"
     * der Artikelseite; '' = es gibt keine (dann ohne Link bzw. ohne
     * Brotkrume).
     */
    public function category_url(string $slug, int $page = 1): string
    {
        /**
         * Filter kontor_shop/seo/category_url: Adresse der Kategorieseite,
         * z. B. Pro: /shop/kategorie/{slug}/ (Pro\Seo\CategoryPages).
         * Standard: '' - ohne Kategorieseiten verlinkt die Artikelseite keine
         * Kategorie (die Katalogseite filtert im Browser).
         *
         * @param string $url
         * @param string $slug Kategorie-Slug
         * @param int    $page Seitenzahl (ab 1)
         */
        return (string) apply_filters('kontor_shop/seo/category_url', '', $slug, $page);
    }

    /**
     * Startseite > Shop > Kategorie(n): Brotkrumen einer virtuellen Seite
     * (die Seite selbst haengt der Aufrufer an). Kategorien ohne eigene
     * Seite (category_url() = '') fallen weg.
     *
     * @param array<int, array{name: string, path: string}> $trail Kategorie-Pfad (PublicProduct::$category_trail)
     * @return array<int, array{name: string, url: string}>
     */
    public function breadcrumbs(array $trail): array
    {
        $crumbs = [
            ['name' => __('Home', 'kontor-shop'), 'url' => $this->home()],
            ['name' => __('Shop', 'kontor-shop'), 'url' => $this->shop()],
        ];

        foreach ($trail as $step) {
            $url = $this->category_url(self::slug_of((string) $step['path']));

            if ($url !== '') {
                $crumbs[] = ['name' => $step['name'], 'url' => $url];
            }
        }

        return $crumbs;
    }

    /** Slug aus einem Kategorie-Pfad ("/shop/kategorie/kleidung/" -> "kleidung"). */
    public static function slug_of(string $path): string
    {
        $parts = array_values(array_filter(explode('/', $path), static fn (string $part): bool => $part !== ''));

        return (string) end($parts);
    }

    public function shop_name(): string
    {
        $name = trim((string) $this->settings->get('general.shop_name', ''));

        return $name !== '' ? $name : (string) get_bloginfo('name');
    }
}
