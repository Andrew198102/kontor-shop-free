<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin\Ui;

use Kontor\Shop\Domain\Catalog\Category;
use Kontor\Shop\Infrastructure\Repositories\CategoryRepository;
use Kontor\Shop\Infrastructure\Repositories\WarehouseRepository;

/**
 * Wiederverwendete <option>-Listen (Kategorien - mit Pro nach Shoptyp gruppiert -, Lager).
 */
final class Options
{
    /**
     * @param string $none_label Text der ersten Option (Wert 0); '' = keine solche Option
     * @param string $extra      zusaetzliche Optionen direkt nach der ersten (fertiges HTML)
     */
    /**
     * <option>-Liste fuer die Mehrfachauswahl "Weitere Kategorien".
     *
     * @param array<int, int> $selected
     */
    public static function categories_multi(array $selected): string
    {
        $html = '';

        foreach ((new CategoryRepository())->all() as $category) {
            $html .= '<option value="' . esc_attr((string) $category->id) . '"' . selected(in_array((int) $category->id, $selected, true), true, false) . '>'
                . esc_html($category->name . ($category->active ? '' : ' (' . __('inactive', 'kontor-shop') . ')')) . '</option>';
        }

        return $html;
    }

    public static function categories(int $selected, string $none_label = '', string $extra = ''): string
    {
        $categories = (new CategoryRepository())->all();

        /**
         * Filter kontor_shop/admin/category_groups: Kategorien in Auswahllisten
         * gruppieren (<optgroup>), z. B. Pro: nach Shoptyp. Bezeichnung =>
         * Kategorien; Bezeichnung '' = ohne Gruppe. Standard: eine Liste ohne
         * Gruppen.
         *
         * @param array<string, array<int, Category>> $groups
         * @param array<int, Category>                $categories alle Kategorien (sortiert)
         */
        $groups = (array) apply_filters('kontor_shop/admin/category_groups', ['' => $categories], $categories);

        $html = $none_label !== '' ? '<option value="0">' . esc_html($none_label) . '</option>' : '';
        $html .= $extra;

        foreach ($groups as $label => $members) {
            $label = (string) $label;
            $html .= $label !== '' ? '<optgroup label="' . esc_attr($label) . '">' : '';

            foreach ((array) $members as $category) {
                if (!$category instanceof Category) {
                    continue;
                }

                $html .= '<option value="' . esc_attr((string) $category->id) . '"' . selected($selected, (int) $category->id, false) . '>'
                    . esc_html($category->name . ($category->active ? '' : ' (' . __('inactive', 'kontor-shop') . ')')) . '</option>';
            }

            $html .= $label !== '' ? '</optgroup>' : '';
        }

        return $html;
    }

    /**
     * @param array<int, int>|null $only nur diese Lager (fuer Lagerverwalter); null = alle
     */
    public static function warehouses(int $selected, string $none_label = '', ?array $only = null): string
    {
        $html = $none_label !== '' ? '<option value="0">' . esc_html($none_label) . '</option>' : '';

        foreach ((new WarehouseRepository())->all() as $warehouse) {
            if ($only !== null && !in_array((int) $warehouse->id, $only, true)) {
                continue;
            }

            $html .= '<option value="' . esc_attr((string) $warehouse->id) . '"' . selected($selected, (int) $warehouse->id, false) . '>'
                . esc_html($warehouse->name . ($warehouse->active ? '' : ' (' . __('inactive', 'kontor-shop') . ')')) . '</option>';
        }

        return $html;
    }
}
