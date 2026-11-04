<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Seo;

/**
 * Statuscode einer Artikeladresse:
 *
 *   200  aktiv (auch ausverkauft)
 *   301  richtige ID, falscher Slug -> auf die aktuelle Adresse
 *   410  gab es, ist jetzt inaktiv/archiviert/geloescht
 *   404  gab es nie (oder Adresse nicht lesbar)
 *   503  Datenquelle nicht verfuegbar - nie 410
 */
final class ProductRouteResolver
{
    public function __construct(private readonly CatalogSource $source, private readonly UrlScheme $urls)
    {
    }

    public function resolve(string $ref): RouteDecision
    {
        $parsed = UrlScheme::parse_product_ref($ref);

        if ($parsed === null) {
            return new RouteDecision(RouteDecision::NOT_FOUND);
        }

        try {
            $product = $this->source->find_public($parsed['id']);

            if ($product === null) {
                return new RouteDecision($this->source->existed($parsed['id']) ? RouteDecision::GONE : RouteDecision::NOT_FOUND);
            }
        } catch (SourceUnavailable) {
            return new RouteDecision(RouteDecision::UNAVAILABLE);
        }

        if ($parsed['slug'] !== $product->slug) {
            return new RouteDecision(RouteDecision::MOVED, $this->urls->product_path($product->slug, $product->id), $product);
        }

        return new RouteDecision(RouteDecision::OK, null, $product);
    }
}
