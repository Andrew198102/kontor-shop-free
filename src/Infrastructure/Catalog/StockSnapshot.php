<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Catalog;

use Kontor\Shop\Domain\Catalog\Product;
use Kontor\Shop\Domain\Catalog\Variant;
use Kontor\Shop\Domain\Stock\Availability;
use Kontor\Shop\Infrastructure\Checkout\ReservationStore;
use Kontor\Shop\Infrastructure\Repositories\StockRepository;
use Kontor\Shop\Infrastructure\Repositories\VariantRepository;

/**
 * Bestand, Reservierungen und Varianten vieler Artikel mit drei Abfragen
 * (statt mehrerer je Artikel) - fuer Katalog, Kategorieseiten und SEO.
 *
 * Verfuegbarkeit eines Artikels mit Varianten = Summe ueber seine AKTIVEN
 * Varianten; ein Restbestand "ohne Variante" aus der Zeit vor den Varianten
 * zaehlt im Shop nicht mit (er laesst sich keiner Groesse zuordnen).
 */
final class StockSnapshot
{
    /**
     * @param array<int, array<int, int>> $balances product_id => [variant_id => Bestand]
     * @param array<int, array<int, int>> $reserved product_id => [variant_id => reserviert]
     * @param array<int, array<int, Variant>> $variants product_id => aktive Varianten
     */
    private function __construct(
        private readonly array $balances,
        private readonly array $reserved,
        private readonly array $variants,
    ) {
    }

    /**
     * @param array<int, Product> $products
     * @param string|null $except_session Reservierungen dieser Sitzung nicht abziehen (null = alle abziehen)
     */
    public static function load(array $products, ?string $except_session = null): self
    {
        $ids          = array_values(array_filter(array_map(static fn (Product $p): int => (int) $p->id, $products)));
        $with_options = array_values(array_filter(array_map(static fn (Product $p): int => $p->has_variants() ? (int) $p->id : 0, $products)));

        return new self(
            (new StockRepository())->variant_balances($ids),
            (new ReservationStore())->reserved_by_variant($ids, $except_session),
            (new VariantRepository())->for_products($with_options, true)
        );
    }

    /**
     * Aktive Varianten eines Artikels (leer bei Artikeln ohne Merkmale).
     *
     * @return array<int, Variant>
     */
    public function variants(Product $product): array
    {
        return $product->has_variants() ? ($this->variants[(int) $product->id] ?? []) : [];
    }

    /**
     * Frei verfuegbare Menge: einer Variante, ohne Variante bei Artikeln mit
     * Merkmalen die Summe ueber alle aktiven Varianten.
     */
    public function free(Product $product, ?Variant $variant = null): int
    {
        $id = (int) $product->id;

        if (!$product->has_variants()) {
            return $this->free_of($id, 0);
        }

        if ($variant !== null) {
            return $variant->active ? $this->free_of($id, (int) $variant->id) : 0;
        }

        $sum = 0;

        foreach ($this->variants($product) as $active) {
            $sum += $this->free_of($id, (int) $active->id);
        }

        return $sum;
    }

    public function available(Product $product, ?Variant $variant = null): bool
    {
        return $this->free($product, $variant) > 0;
    }

    private function free_of(int $product_id, int $variant_id): int
    {
        return Availability::filtered(Availability::free($this->balances[$product_id][$variant_id] ?? 0, $this->reserved[$product_id][$variant_id] ?? 0), $product_id, $variant_id);
    }
}
