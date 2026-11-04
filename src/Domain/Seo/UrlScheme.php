<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Seo;

/**
 * Adressen der virtuellen Shop-Seiten (kein WordPress-Post):
 *
 *   /shop/artikel/{slug}-{id}/
 *   /shop/kategorie/{slug}/            und  /shop/kategorie/{slug}/seite/{n}/  (Pro)
 *
 * Massgeblich ist die ID am Ende - der Slug darf sich aendern, die alte
 * Adresse leitet dann per 301 auf die neue.
 */
final class UrlScheme
{
    public function __construct(private readonly string $base = 'shop')
    {
    }

    public function base(): string
    {
        return trim($this->base, '/');
    }

    /** Pfadpraefix aller Artikelseiten, z. B. "/shop/artikel/". */
    public function product_prefix(): string
    {
        return '/' . $this->base() . '/artikel/';
    }

    public function category_prefix(): string
    {
        return '/' . $this->base() . '/kategorie/';
    }

    public function product_path(string $slug, int $id): string
    {
        return $this->product_prefix() . $slug . '-' . $id . '/';
    }

    public function category_path(string $slug, int $page = 1): string
    {
        $path = $this->category_prefix() . $slug . '/';

        return $page > 1 ? $path . 'seite/' . $page . '/' : $path;
    }

    /**
     * Zerlegt "{slug}-{id}". Null, wenn das nicht wie eine Artikeladresse aussieht.
     *
     * @return array{slug: string, id: int}|null
     */
    public static function parse_product_ref(string $ref): ?array
    {
        if (!preg_match('/^(?:([A-Za-z0-9-]*)-)?([0-9]{1,18})$/', $ref, $m)) {
            return null;
        }

        $id = (int) $m[2];

        return $id > 0 ? ['slug' => (string) ($m[1] ?? ''), 'id' => $id] : null;
    }

    /**
     * Rewrite-Regeln (Regex => Query-String), in dieser Reihenfolge registrieren:
     * Artikelseiten, dann Kategorieseiten.
     *
     * @return array<string, string>
     */
    public function rewrite_rules(): array
    {
        return $this->product_rewrite_rules() + $this->category_rewrite_rules();
    }

    /**
     * Artikelseiten (Kern, Modules\Seo\RouteController).
     *
     * @return array<string, string>
     */
    public function product_rewrite_rules(): array
    {
        return ['^' . preg_quote($this->base(), '#') . '/artikel/([^/]+)/?$' => 'index.php?kontor_shop_route=product&kontor_shop_ref=$matches[1]'];
    }

    /**
     * Kategorieseiten mit Seitenzahl (Pro, ueber kontor_shop/seo/rewrite_rules).
     *
     * @return array<string, string>
     */
    public function category_rewrite_rules(): array
    {
        $b = preg_quote($this->base(), '#');

        return [
            '^' . $b . '/kategorie/([^/]+)/seite/([0-9]+)/?$'   => 'index.php?kontor_shop_route=category&kontor_shop_ref=$matches[1]&kontor_shop_page=$matches[2]',
            '^' . $b . '/kategorie/([^/]+)/?$'                  => 'index.php?kontor_shop_route=category&kontor_shop_ref=$matches[1]',
        ];
    }
}
