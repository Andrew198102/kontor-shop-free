<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Catalog;

use Kontor\Shop\Domain\Catalog\Product;
use Kontor\Shop\Domain\Catalog\Variant;

/**
 * Oeffentliche Sicht auf die Varianten eines Artikels - dieselbe Form fuer
 * Katalog, Artikelseite und JSON-LD: Werte, Normalpreis, Grundpreis,
 * verfuegbar ja/nein, Bild. Keine Bestandszahlen, kein Mitgliedspreis.
 */
final class VariantRows
{
    /**
     * @param array<int, Variant> $variants nur aktive
     * @param callable(Variant): bool $available
     * @return array<int, array{id: int, options: array<int, string>, label: string, price: float, unit_price: string, available: bool, sku: string, gtin: string, image: array<string, mixed>|null}>
     */
    public static function build(Product $product, array $variants, callable $available): array
    {
        $rows = [];

        foreach ($variants as $variant) {
            if (!$variant->active) {
                continue;
            }

            $price  = $product->list_price($variant);
            $rows[] = [
                'id'         => (int) $variant->id,
                'options'    => $variant->options,
                'label'      => $variant->label(),
                'price'      => $price,
                'unit_price' => $product->unit_price_text($price, $variant),
                'available'  => (bool) $available($variant),
                'sku'        => $variant->sku !== '' ? $variant->sku : $product->sku,
                'gtin'       => $variant->gtin,
                'image'      => $variant->image_id !== null ? ImageData::of($variant->image_id, $product->name . ' – ' . $variant->label()) : null,
            ];
        }

        return $rows;
    }
}
