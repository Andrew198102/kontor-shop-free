<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Seo;

use Kontor\Shop\Domain\Catalog\Category;
use Kontor\Shop\Domain\Catalog\Product;
use Kontor\Shop\Domain\Seo\CatalogSource;
use Kontor\Shop\Domain\Seo\CategoryTree;
use Kontor\Shop\Domain\Seo\PublicProduct;
use Kontor\Shop\Domain\Seo\SourceUnavailable;
use Kontor\Shop\Domain\Seo\UrlScheme;
use Kontor\Shop\Infrastructure\Catalog\CatalogScope;
use Kontor\Shop\Infrastructure\Catalog\ImageData;
use Kontor\Shop\Infrastructure\Catalog\StockSnapshot;
use Kontor\Shop\Infrastructure\Catalog\VariantRows;
use Kontor\Shop\Infrastructure\Checkout\ReservationStore;
use Kontor\Shop\Infrastructure\Repositories\CategoryRepository;
use Kontor\Shop\Infrastructure\Repositories\ProductRepository;
use Kontor\Shop\Infrastructure\Repositories\StockRepository;

/**
 * Der oeffentliche Blick auf den Katalog (Domain\Seo\PublicProduct): nur
 * aktive, nicht archivierte Artikel in sichtbaren Kategorien (mit Pro: in
 * aktiven Shoptypen, Infrastructure\Catalog\CatalogScope),
 * Verfuegbarkeit = Bestand abzueglich Reservierungen, nur Normalpreis.
 * Diese Klasse ist die Grenze: was sie nicht liefert (Mitgliedspreis, Lager,
 * Bestellungen, Kunden), kann weder eine Seite noch ein Provider ausgeben.
 *
 * Datenbankfehler werden als SourceUnavailable gemeldet (-> 503), nie als
 * "nicht gefunden".
 */
final class PublicCatalog implements CatalogSource
{
    /** @var array<int, Category>|null */
    private ?array $visible_categories = null;

    /** @var array<int, int|null>|null */
    private ?array $parents = null;

    /** @var array<int, Product>|null alle oeffentlichen Artikel (id => Artikel), einmal je Anfrage geladen */
    private ?array $public = null;

    /** @var array<int, Product> einzeln geladene Artikel (find_public) */
    private array $loaded = [];

    private ProductRepository $products;
    private CategoryRepository $categories;
    private StockRepository $stock;
    private ReservationStore $reservations;

    public function __construct(private readonly UrlScheme $urls)
    {
        $this->products     = new ProductRepository();
        $this->categories   = new CategoryRepository();
        $this->stock        = new StockRepository();
        $this->reservations = new ReservationStore();
    }

    public function find_public(int $id): ?PublicProduct
    {
        return $this->guard(function () use ($id): ?PublicProduct {
            $product = $this->public !== null ? ($this->public[$id] ?? null) : $this->products->find($id);

            if ($product === null || !$this->is_public($product)) {
                return null;
            }

            $this->loaded[$id] = $product;

            return $this->build([$product])[0] ?? null;
        });
    }

    public function existed(int $id): bool
    {
        return $this->guard(fn (): bool => $this->products->existed($id));
    }

    /**
     * Alle oeffentlichen Artikel. $gallery = false: nur das Hauptbild (fuer
     * Trefferlisten), spart Galerie-Abfragen und Bild-URLs, die keiner zeigt.
     *
     * @return array<int, PublicProduct>
     */
    public function all(bool $gallery = true): array
    {
        return $this->guard(fn (): array => $this->build(array_values($this->public_products()), $gallery));
    }

    /**
     * Der Artikel hinter einem oeffentlichen Artikel (z. B. fuer seine
     * Adresse) - aus dem, was schon geladen ist, sonst eine Abfrage.
     */
    public function source(int $id): ?Product
    {
        return $this->public[$id] ?? $this->loaded[$id] ?? $this->guard(fn (): ?Product => $this->products->find($id));
    }

    /**
     * IDs der oeffentlichen Artikel einer Kategorie samt Unterkategorien,
     * ohne die Artikel aufzubauen.
     *
     * @return array<int, true>
     */
    public function ids_in_category(Category $category): array
    {
        return $this->guard(function () use ($category): array {
            $ids = [];

            foreach ($this->matching($category) as $product) {
                $ids[(int) $product->id] = true;
            }

            return $ids;
        });
    }

    /**
     * Sichtbare Kategorie zu einem Slug (aktiv, aktiver Shoptyp, aktive Eltern).
     */
    public function visible_category(string $slug): ?Category
    {
        return $this->guard(function () use ($slug): ?Category {
            foreach ($this->visible() as $category) {
                if ($category->slug === $slug) {
                    return $category;
                }
            }

            return null;
        });
    }

    /**
     * @return array<int, Category> id => Kategorie, nur sichtbare
     */
    public function visible_categories(): array
    {
        return $this->guard(fn (): array => $this->visible());
    }

    /**
     * Artikel einer Kategorie inklusive ihrer Unterkategorien, alphabetisch,
     * seitenweise.
     *
     * @return array{items: array<int, PublicProduct>, total: int}
     */
    public function in_category(Category $category, int $page, int $per_page): array
    {
        return $this->guard(function () use ($category, $page, $per_page): array {
            $matching = $this->matching($category);
            $total    = count($matching);
            $slice    = array_slice($matching, max(0, ($page - 1) * $per_page), $per_page);

            return ['items' => $this->build($slice), 'total' => $total];
        });
    }

    /**
     * Oeffentliche Artikel einer Kategorie samt Unterkategorien, alphabetisch.
     *
     * @return array<int, Product>
     */
    private function matching(Category $category): array
    {
        $wanted = array_merge([(int) $category->id], CategoryTree::descendants((int) $category->id, $this->parent_map()));
        $public = array_values($this->public_products());
        $map    = $this->products->category_map(array_map(static fn (Product $p): int => (int) $p->id, $public));

        return array_values(array_filter(
            $public,
            static fn (Product $p): bool => array_intersect($map[(int) $p->id] ?? [], $wanted) !== []
        ));
    }

    /**
     * @return array<int, Product> id => Artikel, alphabetisch
     */
    private function public_products(): array
    {
        if ($this->public === null) {
            $this->public = [];

            foreach ($this->products->all() as $product) {
                if ($this->is_public($product)) {
                    $this->public[(int) $product->id] = $product;
                }
            }
        }

        return $this->public;
    }

    /**
     * Brotkrumen-Kette einer Kategorie: Wurzel zuerst, sie selbst zuletzt.
     *
     * @return array<int, Category>
     */
    public function chain(Category $category): array
    {
        $visible = $this->visible();
        $chain   = [];

        foreach (array_reverse(CategoryTree::ancestors((int) $category->id, $this->parent_map())) as $ancestor_id) {
            if (isset($visible[$ancestor_id])) {
                $chain[] = $visible[$ancestor_id];
            }
        }

        $chain[] = $category;

        return $chain;
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     * @throws SourceUnavailable
     */
    private function guard(callable $work): mixed
    {
        global $wpdb;

        $wpdb->last_error = '';

        try {
            $result = $work();
        } catch (SourceUnavailable $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new SourceUnavailable($e->getMessage(), 0, $e); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Meldung wird nie ausgegeben (Aufrufer antworten mit 503)
        }

        if (($wpdb->last_error ?? '') !== '') {
            throw new SourceUnavailable((string) $wpdb->last_error); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Meldung wird nie ausgegeben (Aufrufer antworten mit 503)
        }

        return $result;
    }

    private function is_public(Product $product): bool
    {
        if (!$product->is_sellable()) {
            return false;
        }

        return $product->category_id === null || isset($this->visible()[$product->category_id]);
    }

    /**
     * @return array<int, Category>
     */
    private function visible(): array
    {
        if ($this->visible_categories !== null) {
            return $this->visible_categories;
        }

        $all    = [];
        $active = [];

        foreach ($this->categories->all() as $category) {
            $all[(int) $category->id] = $category;

            if ($category->active) {
                $active[(int) $category->id] = $category;
            }
        }

        // Ganzer Katalog (ohne shop-Attribut): im Kern alle aktiven Kategorien, mit Pro ohne inaktive Shoptypen.
        $in_scope = CatalogScope::resolve('', $active)['categories'];
        $visible  = [];

        foreach ($all as $id => $category) {
            if (!isset($in_scope[$id])) {
                continue;
            }

            $ok = true;

            foreach (CategoryTree::ancestors($id, $this->parent_map()) as $ancestor_id) {
                $ancestor = $all[$ancestor_id] ?? null;

                if ($ancestor === null || !$ancestor->active) {
                    $ok = false;

                    break;
                }
            }

            if ($ok) {
                $visible[$id] = $category;
            }
        }

        return $this->visible_categories = $visible;
    }

    /**
     * @return array<int, int|null>
     */
    private function parent_map(): array
    {
        return $this->parents ??= $this->categories->parent_map();
    }

    /**
     * Alles fuer viele Artikel gesammelt laden (Bestand, Kategorien,
     * Galerien, Bild-Anhaenge), damit die Schleife keine Abfragen mehr macht.
     *
     * @param array<int, Product> $products
     * @return array<int, PublicProduct>
     */
    private function build(array $products, bool $with_gallery = true): array
    {
        if ($products === []) {
            return [];
        }

        $ids         = array_map(static fn (Product $p): int => (int) $p->id, $products);
        $snapshot    = StockSnapshot::load($products);
        $cat_map     = $this->products->category_map($ids);
        $galleries   = $with_gallery ? $this->products->gallery_map($ids) : [];
        $visible     = $this->visible();
        $result      = [];
        $attachments = [];

        foreach ($products as $product) {
            $attachments[] = $product->image_id;
            array_push($attachments, ...($galleries[(int) $product->id] ?? []));

            foreach ($snapshot->variants($product) as $variant) {
                $attachments[] = $variant->image_id;
            }
        }

        ImageData::prime($attachments);

        foreach ($products as $product) {
            $id = (int) $product->id;

            // Hauptkategorie zuerst, dann die uebrigen sichtbaren Zuordnungen.
            $assigned = array_values(array_filter(
                array_unique(array_merge($product->category_id !== null ? [$product->category_id] : [], $cat_map[$id] ?? [])),
                static fn (int $cid): bool => isset($visible[$cid])
            ));

            $trail = [];

            if ($assigned !== []) {
                foreach ($this->chain($visible[$assigned[0]]) as $category) {
                    $trail[] = ['name' => $category->name, 'path' => $this->urls->category_path($category->slug)];
                }
            }

            $list_paths = [];

            foreach ($assigned as $cid) {
                foreach ($this->chain($visible[$cid]) as $category) {
                    $list_paths[$this->urls->category_path($category->slug)] = true;
                }
            }

            $main      = ImageData::of($product->image_id, $product->name, $product->image_alt);
            $image_url = $main['url'] ?? null;
            $alt       = $main['alt'] ?? $product->image_alt;
            $images    = $main !== null ? [$main] : [];

            foreach ($galleries[$id] ?? [] as $gallery_id) {
                $image = ImageData::of($gallery_id, $product->name);

                if ($image !== null) {
                    $images[] = $image;
                }
            }

            $variants = $product->has_variants()
                ? VariantRows::build($product, $snapshot->variants($product), static fn ($variant): bool => $snapshot->available($product, $variant))
                : [];
            $prices   = array_map(static fn (array $row): float => (float) $row['price'], $variants);
            $units    = array_values(array_unique(array_map(static fn (array $row): string => (string) $row['unit_price'], $variants)));

            $result[] = new PublicProduct(
                $id,
                $product->slug,
                $product->name,
                $product->description,
                $product->sku,
                $product->brand,
                $product->gtin,
                $prices !== [] ? min($prices) : $product->price,
                $snapshot->available($product),
                (string) ($product->content_updated_at ?? ''),
                $image_url,
                $alt !== '' ? $alt : $product->name,
                $trail,
                array_keys($list_paths),
                $product->tax_rate,
                $images,
                $variants !== [] ? $product->variant_options : [],
                $variants,
                $variants !== [] ? (count($units) === 1 ? $units[0] : '') : $product->unit_price_text($product->price),
                $product->discontinued,
            );
        }

        return $result;
    }
}
