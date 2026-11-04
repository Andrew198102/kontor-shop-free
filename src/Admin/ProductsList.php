<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin;

use Kontor\Shop\Admin\Ui\Badge;
use Kontor\Shop\Admin\Ui\ListParams;
use Kontor\Shop\Admin\Ui\Options;
use Kontor\Shop\Admin\Ui\Pagination;
use Kontor\Shop\Domain\Catalog\Product;
use Kontor\Shop\Infrastructure\Media\ManagedImages;
use Kontor\Shop\Infrastructure\Repositories\CategoryRepository;
use Kontor\Shop\Infrastructure\Repositories\ProductRepository;
use Kontor\Shop\Infrastructure\Repositories\VariantRepository;
use Kontor\Shop\Infrastructure\Repositories\WarehouseRepository;
use Kontor\Shop\Settings\Repository;

/**
 * Artikelliste: Ansichten (alle/sichtbar/unsichtbar/Bestand knapp/Archiv),
 * Suche, Filter, Sortierung, Seitennavigation, Sammelaktionen, Zeilenaktionen
 * (Bearbeiten, Schnellbearbeitung, Duplizieren, Archivieren) und
 * Sichtbarkeits-Schalter direkt in der Zeile. Die Zeile wird auch von der
 * AJAX-Schnellbearbeitung neu gerendert (row_html()).
 */
final class ProductsList
{
    private const SORTABLE = ['name', 'sku', 'price', 'stock'];

    public function __construct(
        private readonly ProductRepository $products,
        private readonly Repository $settings
    ) {
    }

    /**
     * @return array{view: string, q: string, warehouse_id: int, category_id: int, orderby: string, order: string}
     */
    public static function state(): array
    {
        $view = ListParams::key('view');

        return [
            'view'         => in_array($view, ['visible', 'hidden', 'warn', 'archive', 'images'], true) ? $view : 'all',
            'q'            => ListParams::text('q'),
            'warehouse_id' => max(0, ListParams::int('warehouse_id')),
            'category_id'  => ListParams::int('category_id'),
            'orderby'      => ListParams::orderby(self::SORTABLE, 'name'),
            'order'        => ListParams::order('asc'),
        ];
    }

    /**
     * Zu erhaltende Parameter fuer Links (Standardwerte fallen weg).
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public static function keep(array $state): array
    {
        return [
            'view'         => $state['view'] === 'all' ? '' : $state['view'],
            'q'            => $state['q'],
            'warehouse_id' => $state['warehouse_id'],
            'category_id'  => $state['category_id'],
            'orderby'      => $state['orderby'] === 'name' ? '' : $state['orderby'],
            'order'        => $state['orderby'] === 'name' && $state['order'] === 'asc' ? '' : $state['order'],
        ];
    }

    /**
     * @param array<string, mixed>|null $state null = aktuelle Listen-Parameter
     */
    public static function list_url(?array $state = null): string
    {
        $state ??= self::state();

        return ListParams::url(['page' => ProductsPage::SLUG], self::keep($state));
    }

    public function render(): void
    {
        $state      = self::state();
        $archive    = $state['view'] === 'archive';
        $per_page   = ListParams::per_page();
        $page       = ListParams::page();
        $keep       = self::keep($state);
        $base       = ['page' => ProductsPage::SLUG];
        $counts     = $this->products->counts();

        $result = $this->products->query([
            'view'         => $archive ? 'archive' : 'active',
            'q'            => $state['q'],
            'warehouse_id' => $state['warehouse_id'],
            'category_id'  => $state['category_id'],
            'visibility'   => match ($state['view']) {
                'visible' => 'visible',
                'hidden'  => 'hidden',
                default   => '',
            },
            'stock'        => $state['view'] === 'warn' ? 'warn' : '',
            'orderby'      => $state['orderby'],
            'order'        => $state['order'],
            'page'         => $page,
            'per_page'     => $per_page,
        ]);

        $ctx = self::context();

        echo '<h1 class="wp-heading-inline">' . esc_html__('Products', 'kontor-shop') . '</h1> ';
        echo '<a href="' . esc_url(ProductsPage::url(['do' => 'new'])) . '" class="page-title-action">' . esc_html__('Add new product', 'kontor-shop') . '</a>';
        echo '<hr class="wp-header-end" />';

        $this->render_views($state, $counts);
        $this->render_filters($state, $ctx);

        if ($result['items'] === [] && $result['total'] === 0) {
            $this->render_empty($state, $counts);

            return;
        }

        echo '<form method="post" action="' . esc_url(self::list_url($state)) . '" class="ks-list-form">';
        wp_nonce_field(ProductsPage::BULK_NONCE, 'kontor_shop_bulk_nonce');

        $this->render_tablenav('top', $archive, $ctx, $result['total'], $page, $per_page, $base, $keep);

        echo '<table class="wp-list-table widefat fixed striped ks-table ks-products"><thead><tr>';
        echo '<td class="manage-column column-cb check-column"><input type="checkbox" id="cb-select-all-1" /></td>';
        echo '<th class="ks-col-image"><span class="screen-reader-text">' . esc_html__('Image', 'kontor-shop') . '</span></th>';
        echo ListParams::sortable_th(__('Name', 'kontor-shop'), 'name', $state['orderby'], $state['order'], $base, $keep, 'column-primary'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ListParams::sortable_th liefert escaptes HTML
        echo ListParams::sortable_th(__('SKU', 'kontor-shop'), 'sku', $state['orderby'], $state['order'], $base, $keep, 'ks-col-sku'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ListParams::sortable_th liefert escaptes HTML
        echo '<th>' . esc_html__('Warehouse', 'kontor-shop') . '</th>';
        echo '<th>' . esc_html__('Category', 'kontor-shop') . '</th>';
        echo ListParams::sortable_th(__('Price', 'kontor-shop'), 'price', $state['orderby'], $state['order'], $base, $keep, 'ks-col-num'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ListParams::sortable_th liefert escaptes HTML
        echo ListParams::sortable_th(__('Stock', 'kontor-shop'), 'stock', $state['orderby'], $state['order'], $base, $keep, 'ks-col-num'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ListParams::sortable_th liefert escaptes HTML
        echo '<th class="ks-col-toggle">' . esc_html__('Visible in shop', 'kontor-shop') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($result['items'] as $product) {
            echo $this->row($product, $result['balances'][(int) $product->id] ?? 0, $ctx, $archive); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ProductsList::row liefert escaptes HTML
        }

        echo '</tbody></table>';

        $this->render_tablenav('bottom', $archive, $ctx, $result['total'], $page, $per_page, $base, $keep);
        echo '</form>';

        if (!$archive) {
            $this->render_quickedit_template($ctx);
        }
    }

    /**
     * @param array<string, mixed> $state
     * @param array{active: int, archived: int, visible: int, hidden: int, warn: int} $counts
     */
    private function render_views(array $state, array $counts): void
    {
        $views = [
            'all'     => [__('All', 'kontor-shop'), $counts['active']],
            'visible' => [__('Visible', 'kontor-shop'), $counts['visible']],
            'hidden'  => [__('Hidden', 'kontor-shop'), $counts['hidden']],
            'warn'    => [__('Low/out of stock', 'kontor-shop'), $counts['warn']],
            'archive' => [__('Archived', 'kontor-shop'), $counts['archived']],
            'images'  => [__('Unused images', 'kontor-shop'), count(ManagedImages::pending_unused($this->settings))],
        ];

        $links = [];

        foreach ($views as $key => [$label, $count]) {
            $url     = ProductsPage::url($key === 'all' ? [] : ['view' => $key]);
            $current = $state['view'] === $key ? ' class="current" aria-current="page"' : '';
            $links[] = '<li><a href="' . esc_url($url) . '"' . $current . '>' . esc_html($label) . ' <span class="count">(' . (int) $count . ')</span></a>';
        }

        echo '<ul class="subsubsub">' . implode(' | </li>', $links) . '</li></ul><br class="clear" />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $links oben mit esc_url/esc_html gebaut
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $ctx
     */
    private function render_filters(array $state, array $ctx): void
    {
        echo '<form method="get" class="ks-filter-bar">';
        echo '<input type="hidden" name="page" value="' . esc_attr(ProductsPage::SLUG) . '" />';

        if ($state['view'] !== 'all') {
            echo '<input type="hidden" name="view" value="' . esc_attr($state['view']) . '" />';
        }

        echo '<input type="search" name="q" value="' . esc_attr($state['q']) . '" placeholder="' . esc_attr__('Search: name, SKU, description', 'kontor-shop') . '" class="regular-text" aria-label="' . esc_attr__('Search products', 'kontor-shop') . '" />';

        echo '<select name="warehouse_id" aria-label="' . esc_attr__('Warehouse', 'kontor-shop') . '">'
            . Options::warehouses($state['warehouse_id'], __('All warehouses', 'kontor-shop')) . '</select>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Options liefert escaptes HTML

        echo '<select name="category_id" aria-label="' . esc_attr__('Category', 'kontor-shop') . '">'
            . Options::categories( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Options liefert escaptes HTML
                $state['category_id'],
                __('All categories', 'kontor-shop'), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Options liefert escaptes HTML
                '<option value="-1"' . selected($state['category_id'], -1, false) . '>' . esc_html__('– no category –', 'kontor-shop') . '</option>'
            ) . '</select>';

        echo '<button type="submit" class="button">' . esc_html__('Filter', 'kontor-shop') . '</button>';

        if ($state['q'] !== '' || $state['warehouse_id'] > 0 || $state['category_id'] !== 0) {
            echo ' <a href="' . esc_url(ProductsPage::url($state['view'] === 'all' ? [] : ['view' => $state['view']])) . '">'
                . esc_html__('Reset filter', 'kontor-shop') . '</a>';
        }

        echo '</form>';
    }

    /**
     * @param array<string, mixed> $state
     * @param array{active: int, archived: int, visible: int, hidden: int, warn: int} $counts
     */
    private function render_empty(array $state, array $counts): void
    {
        $filtered = $state['q'] !== '' || $state['warehouse_id'] > 0 || $state['category_id'] !== 0 || $state['view'] !== 'all';

        echo '<div class="ks-empty"><p><strong>';

        if ($state['view'] === 'archive') {
            echo esc_html__('The archive is empty.', 'kontor-shop');
        } elseif ($filtered) {
            echo esc_html__('No products found for this selection.', 'kontor-shop');
        } else {
            echo esc_html__('No products yet.', 'kontor-shop');
        }

        echo '</strong></p>';

        if (!$filtered || $counts['active'] === 0) {
            echo '<p><a class="button button-primary" href="' . esc_url(ProductsPage::url(['do' => 'new'])) . '">'
                . esc_html__('Add your first product', 'kontor-shop') . '</a></p>';
        }

        echo '</div>';
    }

    /**
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $base
     * @param array<string, mixed> $keep
     */
    private function render_tablenav(string $position, bool $archive, array $ctx, int $total, int $page, int $per_page, array $base, array $keep): void
    {
        $suffix = $position === 'top' ? '' : '2';
        $top    = $position === 'top';

        echo '<div class="tablenav ' . esc_attr($position) . '"><div class="alignleft actions bulkactions ks-bulk">';
        echo '<select name="bulk_action' . esc_attr($suffix) . '" class="ks-bulk-select" aria-label="' . esc_attr__('Bulk action', 'kontor-shop') . '">';
        echo '<option value="">' . esc_html__('Bulk action …', 'kontor-shop') . '</option>';

        if ($archive) {
            echo '<option value="restore">' . esc_html__('Restore', 'kontor-shop') . '</option>';
            echo '<option value="delete">' . esc_html__('Delete permanently', 'kontor-shop') . '</option>';
        } else {
            echo '<option value="show">' . esc_html__('Make visible', 'kontor-shop') . '</option>';
            echo '<option value="hide">' . esc_html__('Make hidden', 'kontor-shop') . '</option>';
            echo '<option value="duplicate">' . esc_html__('Duplicate', 'kontor-shop') . '</option>';

            if ($top) {
                echo '<option value="category">' . esc_html__('Assign category …', 'kontor-shop') . '</option>';
                echo '<option value="price">' . esc_html__('Change price by % …', 'kontor-shop') . '</option>';
            }

            echo '<option value="archive">' . esc_html__('Archive', 'kontor-shop') . '</option>';
        }

        echo '</select>';

        if ($top && !$archive) {
            echo '<select name="bulk_category" class="ks-bulk-extra ks-bulk-extra--category" hidden aria-label="' . esc_attr__('Category', 'kontor-shop') . '">'
                . Options::categories(0, __('– no category –', 'kontor-shop')) . '</select>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Options liefert escaptes HTML
            echo '<span class="ks-bulk-extra ks-bulk-extra--price" hidden><input type="number" name="bulk_percent" step="0.1" min="-99" max="500" class="small-text" placeholder="+10" aria-label="' . esc_attr__('Percent', 'kontor-shop') . '" /> %</span>';
        }

        echo '<button type="submit" class="button action" data-ks-bulk-apply>' . esc_html__('Apply', 'kontor-shop') . '</button>';
        echo '</div>';

        Pagination::render($total, $page, $per_page, $base, $keep);

        if ($top) {
            Pagination::per_page_form($per_page, $base, $keep);
        }

        echo '<br class="clear" /></div>';
    }

    /**
     * Nachschlage-Tabellen fuer die Zeilen (einmal je Seitenaufruf).
     *
     * @return array{warehouses: array<int, string>, categories: array<int, string>}
     */
    public static function context(): array
    {
        $warehouses = [];

        foreach ((new WarehouseRepository())->all() as $warehouse) {
            $warehouses[(int) $warehouse->id] = $warehouse->name;
        }

        $categories = [];

        foreach ((new CategoryRepository())->all() as $category) {
            $categories[(int) $category->id] = $category->name;
        }

        return ['warehouses' => $warehouses, 'categories' => $categories];
    }

    /**
     * Preis in der Liste; bei Varianten mit eigenen Preisen die Spanne ("19,90–24,90 €").
     */
    private function price_label(Product $product): string
    {
        $prices = [$product->price];

        if ($product->has_variants()) {
            $prices = array_map(
                static fn ($variant): float => $product->list_price($variant),
                (new VariantRepository())->for_product((int) $product->id, true)
            ) ?: $prices;
        }

        $min = min($prices);
        $max = max($prices);

        return $max - $min > 0.0001
            ? number_format_i18n($min, 2) . '–' . number_format_i18n($max, 2) . ' €'
            : number_format_i18n($min, 2) . ' €';
    }

    /**
     * Eine einzelne Zeile neu rendern (fuer die AJAX-Schnellbearbeitung).
     */
    public function row_html(int $id): string
    {
        $product = $this->products->find($id);

        if ($product === null) {
            return '';
        }

        $balance = (new \Kontor\Shop\Infrastructure\Repositories\StockRepository())->balance($id);

        return $this->row($product, $balance, self::context(), $product->archived);
    }

    /**
     * @param array{warehouses: array<int, string>, categories: array<int, string>} $ctx
     */
    private function row(Product $product, int $balance, array $ctx, bool $archive): string
    {
        $id        = (int) $product->id;
        $name_link = ProductsPage::url(['do' => 'edit', 'id' => $id]);
        /**
         * Filter kontor_shop/admin/products_list/price_note: kurzer Zusatz
         * unter dem Preis in der Artikelliste, z. B. Pro: "Mitglieder: 15,92 €".
         * Reiner Text, wird escaped. Standard: ''.
         *
         * @param string  $note
         * @param Product $product
         */
        $note   = (string) apply_filters('kontor_shop/admin/products_list/price_note', '', $product);
        $member = $note !== '' ? '<br /><small class="ks-muted">' . esc_html($note) . '</small>' : '';

        $actions = [];

        if (!$archive) {
            $actions[] = '<span class="edit"><a href="' . esc_url($name_link) . '">' . esc_html__('Edit', 'kontor-shop') . '</a></span>';
            $actions[] = '<span class="inline"><button type="button" class="button-link ks-quickedit-open">' . esc_html__('Quick edit', 'kontor-shop') . '</button></span>';
            $actions[] = '<span class="duplicate"><a href="' . esc_url(ProductsPage::action_url('duplicate', $id)) . '">' . esc_html__('Duplicate', 'kontor-shop') . '</a></span>';
            $actions[] = '<span class="archive"><a href="' . esc_url(ProductsPage::action_url('archive', $id)) . '">' . esc_html__('Archive', 'kontor-shop') . '</a></span>';
        } else {
            $actions[] = '<span class="restore"><a href="' . esc_url(ProductsPage::action_url('restore', $id)) . '">' . esc_html__('Restore', 'kontor-shop') . '</a></span>';
            $actions[] = '<span class="delete"><a href="' . esc_url(ProductsPage::action_url('delete', $id)) . '" class="submitdelete" data-ks-confirm="'
                . esc_attr__('Delete product permanently? This can’t be undone.', 'kontor-shop') . '">' . esc_html__('Delete permanently', 'kontor-shop') . '</a></span>';
        }

        $image = $product->image_id !== null
            ? wp_get_attachment_image($product->image_id, [48, 48], false, ['class' => 'ks-thumb'])
            : '<span class="ks-thumb ks-thumb--empty dashicons dashicons-format-image" aria-hidden="true"></span>';

        $html  = '<tr data-id="' . $id . '" data-name="' . esc_attr($product->name) . '" data-sku="' . esc_attr($product->sku) . '"'
            . ' data-price="' . esc_attr(number_format($product->price, 2, '.', '')) . '" data-category="' . esc_attr((string) ($product->category_id ?? 0)) . '"'
            . ' data-min="' . esc_attr((string) ($product->min_stock ?? '')) . '" data-active="' . ($product->active ? '1' : '0') . '">';
        $html .= '<th scope="row" class="check-column"><input type="checkbox" name="ids[]" value="' . $id . '" aria-label="' . esc_attr(sprintf(
            /* translators: %s: product name */
            __('Select %s', 'kontor-shop'),
            $product->name
        )) . '" /></th>';
        $html .= '<td class="ks-col-image">' . $image . '</td>';
        $html .= '<td class="column-primary has-row-actions"><strong>' . ($archive ? esc_html($product->name) : '<a class="row-title" href="' . esc_url($name_link) . '">' . esc_html($product->name) . '</a>') . '</strong>'
            . ($product->active || $archive ? '' : ' ' . Badge::html(__('hidden', 'kontor-shop'), Badge::MUTED))
            . ($product->discontinued && !$archive ? ' ' . Badge::html(__('Discontinued product', 'kontor-shop'), Badge::MUTED) : '')
            . ($product->has_variants() ? ' ' . Badge::html(implode(' × ', array_map(static fn (array $option): string => $option['name'], $product->variant_options)), Badge::INFO) : '')
            . '<div class="row-actions">' . implode(' | ', $actions) . '</div></td>';
        $html .= '<td class="ks-col-sku">' . esc_html($product->sku !== '' ? $product->sku : '—') . '</td>';
        $html .= '<td>' . esc_html($ctx['warehouses'][$product->warehouse_id] ?? '—') . '</td>';
        $html .= '<td>' . esc_html($ctx['categories'][(int) $product->category_id] ?? '—') . '</td>';
        $html .= '<td class="ks-col-num">' . esc_html($this->price_label($product)) . $member . '</td>';
        $html .= '<td class="ks-col-num">' . Badge::stock($balance, $product->min_stock) . '</td>';
        $html .= '<td class="ks-col-toggle">' . ($archive ? '—' : self::toggle('product', $id, $product->active, __('Visible in shop', 'kontor-shop'))) . '</td>';
        $html .= '</tr>';

        return $html;
    }

    /**
     * Schalter (Ein/Aus) - wird per admin.js ueber AJAX gespeichert.
     */
    public static function toggle(string $entity, int $id, bool $on, string $label): string
    {
        return '<button type="button" class="ks-toggle' . ($on ? ' is-on' : '') . '" role="switch" aria-checked="' . ($on ? 'true' : 'false') . '"'
            . ' data-ks-toggle="' . esc_attr($entity) . '" data-id="' . $id . '" aria-label="' . esc_attr($label) . '" title="' . esc_attr($label) . '">'
            . '<span class="ks-toggle__knob"></span></button>';
    }

    /**
     * Vorlage des Inline-Formulars; admin.js klont sie in die Zeile.
     *
     * @param array<string, mixed> $ctx
     */
    private function render_quickedit_template(array $ctx): void
    {
        echo '<template id="ks-quickedit-template"><tr class="ks-quickedit-row"><td colspan="9"><div class="ks-quickedit">';
        echo '<strong class="ks-quickedit__title">' . esc_html__('Quick edit', 'kontor-shop') . '</strong>';
        echo '<label>' . esc_html__('Name', 'kontor-shop') . '<input type="text" data-field="name" maxlength="190" /></label>';
        echo '<label>' . esc_html__('SKU', 'kontor-shop') . '<input type="text" data-field="sku" maxlength="64" /></label>';
        echo '<label>' . esc_html__('Price (€)', 'kontor-shop') . '<input type="text" inputmode="decimal" data-field="price" class="small-text" /></label>';
        echo '<label>' . esc_html__('Category', 'kontor-shop') . '<select data-field="category_id">' . Options::categories(0, __('– none –', 'kontor-shop')) . '</select></label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Options liefert escaptes HTML
        echo '<label>' . esc_html__('Minimum stock', 'kontor-shop') . '<input type="number" min="0" data-field="min_stock" class="small-text" /></label>';
        echo '<label class="ks-quickedit__check"><input type="checkbox" data-field="active" /> ' . esc_html__('Visible in shop', 'kontor-shop') . '</label>';
        echo '<span class="ks-quickedit__buttons"><button type="button" class="button button-primary ks-qe-save">' . esc_html__('Save', 'kontor-shop') . '</button> ';
        echo '<button type="button" class="button ks-qe-cancel">' . esc_html__('Cancel', 'kontor-shop') . '</button> <span class="spinner"></span></span>';
        echo '<span class="ks-quickedit__error" role="alert"></span>';
        echo '</div></td></tr></template>';
    }
}
