<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Catalog;

use Kontor\Shop\Domain\Catalog\Product;
use Kontor\Shop\Domain\Catalog\PublishReadiness;
use Kontor\Shop\Infrastructure\Repositories\ProductRepository;
use Kontor\Shop\Infrastructure\Repositories\StockRepository;
use Kontor\Shop\Infrastructure\Repositories\VariantRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Versteckte Artikel, die fertig sind (oder fast): fuer die Kachel "Bereit
 * zum Freischalten" (Uebersicht, KI-Uebersicht) und die gleichnamige Ansicht
 * der Artikelliste. Drei Abfragen fuer alle versteckten Artikel zusammen
 * (Artikel samt Bestand, Varianten, Variantenbestaende), je Anfrage gemerkt.
 */
final class PublishCandidates
{
    /** Hoechstens so viele versteckte Artikel werden geprueft. */
    private const LIMIT = 500;

    /** @var array<string, array{ready: list<Product>, almost: list<array{product: Product, missing: list<string>}>}> */
    private static array $cache = [];

    /**
     * @param array<int, int>|null $warehouse_ids null = alle Lager
     * @return array{ready: list<Product>, almost: list<array{product: Product, missing: list<string>}>}
     */
    public static function scan(?array $warehouse_ids = null): array
    {
        $key = $warehouse_ids === null ? 'all' : implode(',', $warehouse_ids);

        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $args = ['view' => 'active', 'visibility' => 'hidden', 'per_page' => self::LIMIT, 'orderby' => 'name'];

        if ($warehouse_ids !== null) {
            $args['warehouse_ids'] = $warehouse_ids;
        }

        $result   = (new ProductRepository())->query($args);
        $products = $result['items'];
        $ids      = array_map(static fn (Product $product): int => (int) $product->id, array_filter($products, static fn (Product $product): bool => $product->has_variants()));
        $variants = $ids !== [] ? (new VariantRepository())->for_products($ids) : [];
        $stocks   = $ids !== [] ? (new StockRepository())->variant_balances($ids) : [];
        $ready    = [];
        $almost   = [];

        foreach ($products as $product) {
            $id      = (int) $product->id;
            $missing = PublishReadiness::missing($product, (int) ($result['balances'][$id] ?? 0), $variants[$id] ?? [], $stocks[$id] ?? []);

            if ($missing === []) {
                $ready[] = $product;
            } elseif ($missing !== null && count($missing) === 1) {
                $almost[] = ['product' => $product, 'missing' => $missing];
            }
        }

        return self::$cache[$key] = ['ready' => $ready, 'almost' => $almost];
    }

    /**
     * Einzelner Artikel (nach einer Aenderung): was fehlt noch? null = nicht versteckt o. Ae.
     *
     * @return list<string>|null
     */
    public static function check(Product $product): ?array
    {
        $id       = (int) $product->id;
        $variants = $product->has_variants() ? (new VariantRepository())->for_product($id) : [];
        $stocks   = $product->has_variants() ? ((new StockRepository())->variant_balances([$id])[$id] ?? []) : [];

        return PublishReadiness::missing($product, (new StockRepository())->balance($id), $variants, $stocks);
    }

    /** Nach Aenderungen im selben Aufruf neu zaehlen. */
    public static function forget(): void
    {
        self::$cache = [];
    }
}
