<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Seo;

/**
 * Was ueber einen Artikel oeffentlich sein darf - und nichts sonst. Das ist
 * bewusst die einzige Datenform, die Seiten, Meta, JSON-LD und der Provider
 * sehen: Es gibt hier keinen Mitgliedspreis, keine Kunden- oder Bestelldaten,
 * keine Lager-/Bestandszahlen (nur "verfuegbar ja/nein", auch je Variante).
 * Bei Varianten ist price der niedrigste Variantenpreis.
 */
final class PublicProduct
{
    /**
     * @param array<int, array{name: string, path: string}> $category_trail Wurzel -> Hauptkategorie
     * @param array<int, string> $list_paths Pfade der Kategorieseiten, auf denen der Artikel steht
     */
    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $name,
        public readonly string $description,
        public readonly string $sku,
        public readonly string $brand,
        public readonly string $gtin,
        public readonly float $price,
        public readonly bool $available,
        public readonly string $content_updated_at,
        public readonly ?string $image_url = null,
        public readonly string $image_alt = '',
        public readonly array $category_trail = [],
        public readonly array $list_paths = [],
        public readonly ?float $tax_rate = null,
        /** @var array<int, array<string, mixed>> Bilder (Hauptbild zuerst): url, thumb, full, srcset, alt */
        public readonly array $images = [],
        /** @var array<int, array{name: string, values: array<int, string>}> Merkmale (leer = ohne Varianten) */
        public readonly array $variant_options = [],
        /** @var array<int, array<string, mixed>> aktive Varianten: id, options, label, price, unit_price, available, sku, gtin, image */
        public readonly array $variants = [],
        /** Grundpreis ("12,90 € / kg") - bei Varianten nur, wenn er fuer alle gleich ist */
        public readonly string $unit_price = '',
        /** Auslaufartikel (wird nicht nachbestellt) */
        public readonly bool $discontinued = false,
    ) {
    }

    public function has_variants(): bool
    {
        return $this->variants !== [];
    }

    /** Hoechster Variantenpreis (= price ohne Varianten). */
    public function price_max(): float
    {
        return $this->variants === [] ? $this->price : max(array_map(static fn (array $row): float => (float) $row['price'], $this->variants));
    }
}
