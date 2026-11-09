<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Agents;

use Kontor\Shop\Domain\Catalog\Category;
use Kontor\Shop\Domain\Seo\PublicProduct;
use Kontor\Shop\Domain\Seo\UrlScheme;
use Kontor\Shop\Infrastructure\Seo\PublicCatalog;
use Kontor\Shop\Modules\Seo\ProductUrl;
use Kontor\Shop\Settings\Repository;

/**
 * Der Katalog, wie ihn ein KI-Assistent eines Kunden sieht: nur, was auch
 * auf der Website oeffentlich ist (Infrastructure\Seo\PublicCatalog -
 * Normalpreis, "verfuegbar ja/nein", keine Lager- oder Kundendaten), dazu
 * eine Suche, die Woerter in Name, Marke, Kategorie, Beschreibung,
 * Artikelnummer und Varianten findet.
 */
final class ShopCatalog
{
    private PublicCatalog $catalog;

    /** @var array<int, PublicProduct>|null */
    private ?array $all = null;

    public function __construct(private readonly Repository $settings)
    {
        $this->catalog = new PublicCatalog(new UrlScheme());
    }

    /**
     * Alle Artikel fuer Suche und Trefferlisten - nur mit Hauptbild; die
     * Galerie liefern find()/find_by_slug().
     *
     * @return array<int, PublicProduct>
     */
    public function all(): array
    {
        return $this->all ??= $this->catalog->all(false);
    }

    public function find(int $id): ?PublicProduct
    {
        return $id > 0 ? $this->catalog->find_public($id) : null;
    }

    public function find_by_slug(string $slug): ?PublicProduct
    {
        foreach ($this->all() as $product) {
            if ($product->slug === $slug) {
                return $this->find($product->id);
            }
        }

        return null;
    }

    /**
     * @return array<int, Category>
     */
    public function categories(): array
    {
        return $this->catalog->visible_categories();
    }

    public function category(string $slug_or_id): ?Category
    {
        foreach ($this->categories() as $category) {
            if ($category->slug === $slug_or_id || (string) $category->id === $slug_or_id) {
                return $category;
            }
        }

        return null;
    }

    /**
     * IDs der Artikel einer Kategorie samt Unterkategorien.
     *
     * @return array<int, true>
     */
    public function ids_in(Category $category): array
    {
        return $this->catalog->ids_in_category($category);
    }

    /**
     * Treffer nach Relevanz. Erst muessen alle Suchwoerter vorkommen; findet
     * das nichts, reicht eines (der Assistent sieht das an "match").
     *
     * @param array<int, PublicProduct> $products
     * @return array{items: array<int, PublicProduct>, match: string}
     */
    public static function search(array $products, string $query): array
    {
        $words = array_values(array_filter(preg_split('/[\s,;]+/u', self::fold($query)) ?: [], static fn (string $w): bool => $w !== ''));

        if ($words === []) {
            usort($products, static fn (PublicProduct $a, PublicProduct $b): int => strnatcasecmp($a->name, $b->name));

            return ['items' => $products, 'match' => 'all'];
        }

        foreach (['all', 'any'] as $mode) {
            $scored = [];

            foreach ($products as $product) {
                $score = self::score($product, $words, $mode === 'all');

                if ($score > 0) {
                    $scored[] = [$score, $product];
                }
            }

            if ($scored !== []) {
                usort($scored, static fn (array $a, array $b): int => $b[0] <=> $a[0] ?: strnatcasecmp($a[1]->name, $b[1]->name));

                return ['items' => array_column($scored, 1), 'match' => $mode];
            }
        }

        return ['items' => [], 'match' => 'none'];
    }

    /**
     * @param array<int, string> $words
     */
    private static function score(PublicProduct $product, array $words, bool $require_all): int
    {
        $fields = [
            6 => self::fold($product->name),
            4 => self::fold($product->brand . ' ' . implode(' ', array_column($product->category_trail, 'name'))),
            3 => self::fold(implode(' ', array_map(static fn (array $v): string => (string) ($v['label'] ?? ''), $product->variants))),
            2 => self::fold($product->sku . ' ' . $product->gtin . ' ' . implode(' ', array_map(static fn (array $v): string => ($v['sku'] ?? '') . ' ' . ($v['gtin'] ?? ''), $product->variants))),
            1 => self::fold(wp_strip_all_tags($product->description)),
        ];
        $total = 0;

        foreach ($words as $word) {
            $best = 0;

            foreach ($fields as $weight => $text) {
                if ($text !== '' && self::contains($text, $word)) {
                    $best = max($best, $weight);
                }
            }

            if ($best === 0 && $require_all) {
                return 0;
            }

            $total += $best;
        }

        return $total;
    }

    /**
     * Teilwort-Treffer ("hood" findet "Hoodie"); kurze Woerter (Groessen wie
     * "L", "XL") nur als ganzes Wort, sonst passt "l" auf fast alles.
     */
    private static function contains(string $text, string $word): bool
    {
        if (mb_strlen($word) >= 3) {
            return str_contains($text, $word);
        }

        return preg_match('/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '(?![\p{L}\p{N}])/u', $text) === 1;
    }

    /** Kleinschreibung, ohne Akzente/Umlaute - "Pullöver" findet "pullover". */
    private static function fold(string $text): string
    {
        return mb_strtolower(remove_accents($text));
    }

    /**
     * Adresse der Artikelseite (Modules\Seo\ProductUrl, Filter
     * kontor_shop/catalog/product_url), sonst die Shop-Seite.
     */
    public function product_url(PublicProduct $product): string
    {
        $source = $this->catalog->source($product->id);
        $url    = $source !== null ? ProductUrl::of($source) : '';

        return $url !== '' ? $url : $this->shop_url();
    }

    private function shop_url(): string
    {
        $page_id = absint($this->settings->get('general.shop_page_id', 0));
        $url     = $page_id > 0 ? get_permalink($page_id) : false;

        return is_string($url) && $url !== '' ? $url : home_url('/');
    }

    /**
     * Kurzform fuer Trefferlisten.
     *
     * @return array<string, mixed>
     */
    public function summary(PublicProduct $product): array
    {
        $out = [
            'product_id'   => $product->id,
            'name'         => $product->name,
            'price'        => round($product->price, 2),
            'currency'     => 'EUR',
            'available'    => $product->available,
            'url'          => $this->product_url($product),
        ];

        if ($product->price_max() > $product->price) {
            $out['price_from'] = true;
            $out['price_max']  = round($product->price_max(), 2);
        }

        if ($product->unit_price !== '') {
            $out['unit_price'] = $product->unit_price;
        }

        if ($product->brand !== '') {
            $out['brand'] = $product->brand;
        }

        if ($product->category_trail !== []) {
            $out['category'] = implode(' › ', array_column($product->category_trail, 'name'));
        }

        if ($product->variant_options !== []) {
            $out['variant_options'] = $product->variant_options;
        }

        if ($product->discontinued) {
            $out['discontinued'] = true;
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags($product->description)));

        if ($text !== '') {
            $out['short_description'] = mb_strlen($text) > 240 ? mb_substr($text, 0, 237) . '…' : $text;
        }

        if ($product->image_url !== null) {
            $out['image_url'] = $product->image_url;
        }

        return $out;
    }

    /**
     * Alles Oeffentliche eines Artikels, mit Varianten.
     *
     * @return array<string, mixed>
     */
    public function details(PublicProduct $product): array
    {
        $out = $this->summary($product);

        unset($out['short_description']);

        $out['description'] = trim(wp_strip_all_tags($product->description));

        if ($product->sku !== '') {
            $out['sku'] = $product->sku;
        }

        if ($product->gtin !== '') {
            $out['gtin'] = $product->gtin;
        }

        if ($product->tax_rate !== null) {
            $out['vat_rate_percent'] = $product->tax_rate;
        }

        $out['images'] = array_values(array_filter(array_map(
            static fn (array $image): ?array => isset($image['full']) || isset($image['url']) ? ['url' => (string) ($image['full'] ?? $image['url']), 'alt' => (string) ($image['alt'] ?? '')] : null,
            $product->images
        )));

        if ($product->variants !== []) {
            $names           = array_column($product->variant_options, 'name');
            $out['variants'] = array_map(static function (array $row) use ($names): array {
                $values  = array_values((array) ($row['options'] ?? []));
                $variant = [
                    'variant_id' => (int) $row['id'],
                    'label'      => (string) ($row['label'] ?? implode(' / ', $values)),
                    'options'    => count($names) === count($values) ? array_combine($names, $values) : $values,
                    'price'      => round((float) $row['price'], 2),
                    'available'  => (bool) ($row['available'] ?? false),
                ];

                if (!empty($row['unit_price'])) {
                    $variant['unit_price'] = (string) $row['unit_price'];
                }

                if (!empty($row['sku'])) {
                    $variant['sku'] = (string) $row['sku'];
                }

                return $variant;
            }, $product->variants);
        }

        return $out;
    }
}
