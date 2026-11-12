<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Catalog;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ist ein versteckter Artikel "sichtlich fertig" und koennte online gehen?
 *
 * Fertig heisst: Preis, Bestand (bei Varianten: eine aktive Variante mit
 * Bestand), Hauptbild, Beschreibung und Kategorie sind da. Nur Bestand reicht
 * nicht - im Test-Shop hatten am 12.11.2026 33 von 35 versteckten Artikeln
 * Bestand, aber kein Foto. Freigeschaltet wird nie automatisch, nur auf
 * Rueckfrage (Uebersicht, KI-Assistent).
 */
final class PublishReadiness
{
    public const PRICE       = 'price';
    public const STOCK       = 'stock';
    public const IMAGE       = 'image';
    public const DESCRIPTION = 'description';
    public const CATEGORY    = 'category';

    /**
     * Was fehlt noch? null = kommt nicht in Frage (schon sichtbar, archiviert
     * oder laeuft aus); [] = fertig.
     *
     * @param array<int, Variant> $variants
     * @param array<int, int> $variant_stock variant_id => Bestand
     * @return list<string>|null
     */
    public static function missing(Product $product, int $stock, array $variants = [], array $variant_stock = []): ?array
    {
        if ($product->active || $product->archived || $product->discontinued) {
            return null;
        }

        $missing = [];
        $active  = array_values(array_filter($variants, static fn (Variant $variant): bool => $variant->active));

        if ($product->has_variants() && $active !== []) {
            $priced = array_filter($active, static fn (Variant $variant): bool => ($variant->price ?? $product->price) > 0);
            $stock  = array_sum(array_map(static fn (Variant $variant): int => max(0, (int) ($variant_stock[(int) $variant->id] ?? 0)), $active));
        } else {
            $priced = $product->price > 0 ? [true] : [];
        }

        if ($priced === []) {
            $missing[] = self::PRICE;
        }

        if ($stock <= 0) {
            $missing[] = self::STOCK;
        }

        if ((int) ($product->image_id ?? 0) <= 0) {
            $missing[] = self::IMAGE;
        }

        if (trim(wp_strip_all_tags($product->description)) === '') {
            $missing[] = self::DESCRIPTION;
        }

        if ($product->category_id === null) {
            $missing[] = self::CATEGORY;
        }

        return $missing;
    }
}
