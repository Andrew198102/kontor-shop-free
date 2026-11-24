<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Catalog;

use Kontor\Shop\Domain\Catalog\Category;
use Kontor\Shop\Infrastructure\Repositories\CategoryRepository;
use Kontor\Shop\Support\Color;

/**
 * Akzentfarbe eines Artikels ausserhalb des Katalogs (Artikelseite,
 * Kategorieseite): dieselbe Reihenfolge wie auf der Katalogkarte - eigene
 * Farbe der Hauptkategorie, sonst was Erweiterungen liefern (Pro: Farbe des
 * Shoptyps), sonst '' = Hauptfarbe aus Einstellungen -> Design.
 */
final class ProductAccent
{
    public static function of(?Category $category): string
    {
        $accent = $category !== null && $category->accent_color !== null ? Color::sanitize_hex((string) $category->accent_color) : '';

        /**
         * Filter kontor_shop/product/accent: Akzentfarbe (#rrggbb) eines
         * Artikels auf Artikel- und Kategorieseiten, z. B. Pro: Farbe des
         * Shoptyps, wenn die Kategorie keine eigene hat. '' = Hauptfarbe.
         *
         * @param string        $accent   bisherige Farbe ('' = keine)
         * @param Category|null $category Hauptkategorie
         */
        return Color::sanitize_hex((string) apply_filters('kontor_shop/product/accent', $accent, $category));
    }

    public static function of_category_id(?int $category_id): string
    {
        $category = $category_id !== null && $category_id > 0 ? (new CategoryRepository())->find($category_id) : null;

        return self::of($category);
    }

    /**
     * Inline-Variablen fuer einen Container in dieser Farbe: Hauptfarbe
     * (Buttons, "Lieferbar", Links mit Akzent) und Kartenakzent.
     */
    public static function style(string $accent): string
    {
        if ($accent === '') {
            return '';
        }

        return '--kontor-shop-primary:' . $accent . ';--kontor-shop-on-primary:' . Color::on_color($accent) . ';' . Color::accent_style($accent);
    }
}
