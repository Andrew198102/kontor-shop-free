<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Catalog;

use Kontor\Shop\Domain\Catalog\SellableItem;
use Kontor\Shop\Infrastructure\Repositories\ProductRepository;
use Kontor\Shop\Infrastructure\Repositories\VariantRepository;

/**
 * Macht aus Artikel-ID + Varianten-ID (Warenkorb, Formular) einen
 * SellableItem - mit Zwischenspeicher je Anfrage, weil Warenkorb, Drawer und
 * Kasse dieselben Positionen mehrfach aufloesen.
 */
final class SellableItems
{
    /** @var array<string, SellableItem|null> */
    private array $cache = [];

    public function __construct(
        private readonly ProductRepository $products = new ProductRepository(),
        private readonly VariantRepository $variants = new VariantRepository(),
    ) {
    }

    /**
     * null, wenn der Artikel nicht existiert oder die Variante nicht zu ihm
     * gehoert. Ob der Posten kaufbar ist, sagt SellableItem::is_sellable().
     */
    public function resolve(int $product_id, int $variant_id = 0): ?SellableItem
    {
        $key = $product_id . ':' . $variant_id;

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $product = $product_id > 0 ? $this->products->find($product_id) : null;
        $variant = $variant_id > 0 ? $this->variants->find($variant_id) : null;

        if ($product === null || ($variant_id > 0 && ($variant === null || $variant->product_id !== $product_id))) {
            return $this->cache[$key] = null;
        }

        return $this->cache[$key] = new SellableItem($product, $variant);
    }
}
