<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Catalog;

use Kontor\Shop\Domain\Catalog\Category;

/**
 * Welche Kategorien der oeffentliche Katalog zeigt - fuer den Shortcode
 * [kontor_shop_catalog] (Modules\Catalog\CatalogModule) und die
 * Artikel-/Kategorieseiten samt KI-Shopzugang (Infrastructure\Seo\PublicCatalog).
 *
 * Der Kern zeigt immer den ganzen Katalog: alle aktiven Kategorien, dazu
 * Artikel ohne Kategorie. Mehrere Shops auf einer Seite (Shoptypen mit
 * eigener Farbe, shop="<slug>" im Shortcode, inaktive Shoptypen) kommen mit
 * Pro ueber kontor_shop/catalog/scope; ohne Pro zeigt shop="..." einfach den
 * ganzen Katalog.
 */
final class CatalogScope
{
    /**
     * @param string               $shop       shop-Attribut des Shortcodes (bereinigter Slug, '' = keins)
     * @param array<int, Category> $categories aktive Kategorien, Schluessel = ID
     * @return array{categories: array<int, Category>, uncategorized: bool, accent: string|null, key: string, html: string|null}&array<string, mixed>
     */
    public static function resolve(string $shop, array $categories): array
    {
        $whole = ['categories' => $categories, 'uncategorized' => true, 'accent' => null, 'key' => 'all', 'html' => null];

        /**
         * Filter kontor_shop/catalog/scope: Ausschnitt des Katalogs, z. B. Pro:
         * nur Kategorien aktiver Shoptypen, bei shop="<slug>" nur dieser
         * Shoptyp in seiner Farbe. Standard: null = ganzer Katalog.
         * Rueckgabe (Array, alles optional):
         *  - categories:    sichtbare Kategorien (Teilmenge von $categories, Schluessel = ID),
         *  - uncategorized: Artikel ohne Kategorie zeigen (Standard true),
         *  - accent:        Hauptfarbe des Katalogs (#rrggbb) oder null,
         *  - key:           Kennung fuer Element-IDs (Standard 'all'),
         *  - html:          statt des Katalogs ausgeben (z. B. Hinweis "Shoptyp nicht gefunden"),
         *  - weitere Schluessel reicht der Kern an kontor_shop/catalog/toolbar weiter.
         *
         * @param array<string, mixed>|null $scope
         * @param string                    $shop       shop-Attribut des Shortcodes ('' = keins, z. B. Artikelseiten)
         * @param array<int, Category>      $categories aktive Kategorien, Schluessel = ID
         */
        $scope = apply_filters('kontor_shop/catalog/scope', null, $shop, $categories);

        if (!is_array($scope)) {
            return $whole;
        }

        $visible = [];

        foreach ((array) ($scope['categories'] ?? $categories) as $category) {
            if ($category instanceof Category && isset($categories[(int) $category->id])) {
                $visible[(int) $category->id] = $category;
            }
        }

        return array_merge($scope, [
            'categories'    => $visible,
            'uncategorized' => (bool) ($scope['uncategorized'] ?? true),
            'accent'        => isset($scope['accent']) && is_string($scope['accent']) && $scope['accent'] !== '' ? $scope['accent'] : null,
            'key'           => isset($scope['key']) && is_string($scope['key']) && $scope['key'] !== '' ? $scope['key'] : 'all',
            'html'          => isset($scope['html']) && is_string($scope['html']) ? $scope['html'] : null,
        ]);
    }
}
