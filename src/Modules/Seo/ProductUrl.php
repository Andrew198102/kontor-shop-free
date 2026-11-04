<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Seo;

use Kontor\Shop\Domain\Catalog\Product;
use Kontor\Shop\Domain\Seo\UrlScheme;

/**
 * Oeffentliche Adresse der Artikelseite (/shop/artikel/{slug}-{id}/) - eine
 * Stelle fuer Katalog, "Im Shop ansehen", KI-Zugang und Mails (z. B. Pro:
 * Verfuegbarkeitsanfragen). Ob der Artikel gerade sichtbar ist, prueft der
 * Aufrufer.
 */
final class ProductUrl
{
    public static function of(Product $product): string
    {
        $url = $product->slug !== '' && $product->id !== null
            ? home_url((new UrlScheme(SeoModule::base_path()))->product_path($product->slug, (int) $product->id))
            : '';

        /**
         * Filter kontor_shop/catalog/product_url: Adresse der Artikelseite
         * (Katalog, "Im Shop ansehen", KI-Zugang, Mails), z. B. fuer eine
         * eigene Artikelseite eines Themes. '' = keine Artikelseite (der
         * Aufrufer nimmt dann die Shop-Seite). Standard: die Adresse aus
         * Domain\Seo\UrlScheme ('' ohne Slug).
         *
         * @param string  $url
         * @param Product $product
         */
        return (string) apply_filters('kontor_shop/catalog/product_url', $url, $product);
    }
}
