<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Seo;

/**
 * Was die aktuelle virtuelle Shop-Seite fuer SEO-Zwecke ist. Wird von den
 * Routen gesetzt und vom Meridian-Adapter bzw. vom eigenen Fallback gelesen -
 * beide muessen so nichts ueber die Routen wissen.
 */
final class CurrentRoute
{
    /** @var array{type: string, canonical: string, title: string, description: string, breadcrumbs: array<int, array{name: string, url: string}>, indexable: bool, product: ?PublicProduct}|null */
    private static ?array $state = null;

    /**
     * @param array<int, array{name: string, url: string}> $breadcrumbs
     */
    public static function set(string $type, string $canonical, string $title, string $description, array $breadcrumbs, bool $indexable = true, ?PublicProduct $product = null): void
    {
        self::$state = [
            'type'        => $type,
            'canonical'   => $canonical,
            'title'       => $title,
            'description' => $description,
            'breadcrumbs' => $breadcrumbs,
            'indexable'   => $indexable,
            'product'     => $product,
        ];
    }

    /** @return array{type: string, canonical: string, title: string, description: string, breadcrumbs: array<int, array{name: string, url: string}>, indexable: bool, product: ?PublicProduct}|null */
    public static function get(): ?array
    {
        return self::$state;
    }

    public static function clear(): void
    {
        self::$state = null;
    }
}
