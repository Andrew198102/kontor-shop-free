<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin;

use Kontor\Shop\Admin\Ui\Css;
use Kontor\Shop\Admin\Ui\Badge;
use Kontor\Shop\Admin\Ui\Kpis;
use Kontor\Shop\Admin\Ui\ListParams;
use Kontor\Shop\Admin\Ui\Views;
use Kontor\Shop\Admin\Ui\Options;
use Kontor\Shop\Admin\Ui\Pagination;
use Kontor\Shop\Domain\Catalog\Product;
use Kontor\Shop\Domain\Catalog\Variant;
use Kontor\Shop\Domain\Stock\MovementType;
use Kontor\Shop\Domain\Stock\StockSignals;
use Kontor\Shop\Domain\Stock\StockStatus;
use Kontor\Shop\Infrastructure\Repositories\CategoryRepository;
use Kontor\Shop\Infrastructure\Repositories\ProductRepository;
use Kontor\Shop\Infrastructure\Repositories\StockRepository;
use Kontor\Shop\Infrastructure\Repositories\VariantRepository;
use Kontor\Shop\Infrastructure\Repositories\WarehouseRepository;

/**
 * Bestandsliste als Arbeitsplatz (docs/PLAN_bestandsseite.md): Kennzahlen,
 * Ansichten, Signale je Artikel (Absatz, im Regal, mit Pro wartende Kunden),
 * Buchen per Popover, Lieferung erfassen und Inventur. Erweiterungen (Pro:
 * Reichweite, Nachbestellliste, CSV-Export) haengen sich ueber
 * kontor_shop/stock/columns, .../cell, .../toolbar, .../bulk_actions und
 * .../dialogs an.
 * Alle Kennzahlen werden fuer die ganze Seite in wenigen Sammelabfragen
 * geholt; gefiltert, sortiert und geblaettert wird danach in PHP (ein
 * Vereinsshop hat Dutzende, nicht Tausende Artikel - Obergrenze MAX_PRODUCTS).
 * Buchungen selbst fuehrt StockPage aus (Rechte, Ledger).
 */
final class StockOverview
{
    public const RECEIVE_DIALOG = 'ks-stock-receive';
    public const MIN_DIALOG     = 'ks-stock-min';
    public const INVENTORY_DIALOG = 'ks-stock-inventory';

    /** Absatz-Zeitraum der Liste ("Verkauft 30 T.") in Tagen. */
    public const SALES_DAYS = 30;

    private const MAX_PRODUCTS = 500;
    private const RECENT       = 12;
    private const WEEKS        = 8;

    /** @var array<int, array{name: string, color: string}>|null */
    private ?array $warehouse_info = null;

    /** @var array<int, string>|null */
    private ?array $category_names = null;

    public function __construct(
        private readonly ProductRepository $products,
        private readonly WarehouseRepository $warehouses,
        private readonly StockRepository $stock,
        private readonly VariantRepository $variants,
    ) {
    }

    /**
     * Gibt es wartende Kunden (Verfuegbarkeitsanfragen)? Nur mit einer
     * Erweiterung am Filter kontor_shop/stock/open_requests - sonst keine
     * Spalte, keine Kachel, keine Ansicht "Mit Anfragen".
     */
    public static function requests_enabled(): bool
    {
        return has_filter('kontor_shop/stock/open_requests') !== false;
    }

    /**
     * @param array<int, int> $product_ids
     * @return array<int, array<int, int>> Artikel-ID => [Varianten-ID (0 = ohne) => Anzahl]
     */
    private static function open_requests(array $product_ids): array
    {
        /**
         * Filter kontor_shop/stock/open_requests: wartende Kunden je Artikel
         * und Variante (z. B. offene Verfuegbarkeitsanfragen). Zaehlt fuer
         * Signale, Nachbestellliste und Export. Standard: [] (niemand wartet).
         *
         * @param array<int, array<int, int>> $counts      Artikel-ID => [Varianten-ID (0 = ohne Variante) => Anzahl]
         * @param array<int, int>             $product_ids die Artikel der Liste
         */
        $counts = apply_filters('kontor_shop/stock/open_requests', [], $product_ids);
        $map    = [];

        foreach (is_array($counts) ? $counts : [] as $product_id => $variants) {
            foreach (is_array($variants) ? $variants : [] as $variant_id => $n) {
                if ((int) $n > 0) {
                    $map[(int) $product_id][(int) $variant_id] = (int) $n;
                }
            }
        }

        return $map;
    }

    /* ==================== Daten ==================== */

    /**
     * Alle (nicht archivierten) Artikel im Lager-Scope samt Kennzahlen.
     *
     * @param array<int, int>|null $scope
     * @param array{q?: string, warehouse_id?: int, category_id?: int} $filters
     * @return array<int, array<string, mixed>>
     */
    public function rows(?array $scope, array $filters = []): array
    {
        $result = $this->products->query($filters + [
            'warehouse_ids' => $scope,
            'orderby'       => 'name',
            'order'         => 'asc',
            'per_page'      => self::MAX_PRODUCTS,
        ]);

        return $this->build($result['items'], $result['balances']);
    }

    /**
     * @return array<string, mixed>
     */
    public function row_for(Product $product): array
    {
        $id = (int) $product->id;

        return $this->build([$product], [$id => $this->stock->balance($id)])[0];
    }

    /**
     * @param array<int, Product> $products
     * @param array<int, int> $balances
     * @return array<int, array<string, mixed>>
     */
    private function build(array $products, array $balances): array
    {
        $ids = array_map(static fn (Product $p): int => (int) $p->id, $products);

        if ($ids === []) {
            return [];
        }

        $variants = $this->variants->for_products($ids);
        $vbal     = $this->stock->variant_balances($ids);
        $bound    = $this->stock->bound_in_orders($ids);
        $sold     = $this->stock->sold_since($ids, self::SALES_DAYS);
        $weekly   = $this->stock->weekly_sales($ids, self::WEEKS);
        $last     = $this->stock->last_movements($ids);
        $requests = self::open_requests($ids);
        $rows     = [];

        foreach ($products as $product) {
            $id     = (int) $product->id;
            $active = [];

            if ($product->has_variants()) {
                foreach ($variants[$id] ?? [] as $variant) {
                    if ($variant->active) {
                        $active[(int) $variant->id] = $vbal[$id][(int) $variant->id] ?? 0;
                    }
                }
            }

            $rows[] = [
                'product'  => $product,
                'variants' => $variants[$id] ?? [],
                'balance'  => $balances[$id] ?? 0,
                'vbal'     => $vbal[$id] ?? [],
                'bound'    => $bound[$id] ?? [],
                'requests' => $requests[$id] ?? [],
                'sold'     => $sold[$id] ?? [],
                'sold30'   => array_sum($sold[$id] ?? []),
                'weekly'   => $weekly[$id] ?? array_fill(0, self::WEEKS, 0),
                'last'     => $last[$id] ?? null,
                'signals'  => new StockSignals(
                    $balances[$id] ?? 0,
                    $product->min_stock,
                    $active,
                    array_sum($bound[$id] ?? []),
                    array_sum($requests[$id] ?? []),
                    $product->is_sellable(),
                    $product->discontinued
                ),
            ];
        }

        return $rows;
    }

    /**
     * Ansicht, Filter und Sortierung aus der URL.
     *
     * @param array<int, array<string, mixed>> $all
     * @return array{view: string, q: string, warehouse_id: int, category_id: int, orderby: string, order: string, ids: array<int, int>}
     */
    public function params(array $all): array
    {
        $view = ListParams::key('view');
        $ids  = array_values(array_filter(array_map('absint', explode(',', ListParams::text('ids')))));

        $views = self::requests_enabled() ? StockSignals::views() : array_values(array_diff(StockSignals::views(), [StockSignals::VIEW_REQUESTS]));

        if ($ids !== [] && !in_array($view, $views, true)) {
            $view = StockSignals::VIEW_ALL;
        }

        if (!in_array($view, $views, true)) {
            $attention = array_filter($all, static fn (array $row): bool => $row['signals']->needs_attention());
            $view      = $attention !== [] ? StockSignals::VIEW_ATTENTION : StockSignals::VIEW_ALL;
        }

        $sortable = array_keys(array_filter(self::columns(), static fn (array $c): bool => isset($c['compare'])));
        $orderby  = ListParams::orderby(array_merge(['name', 'stock', 'sold'], $sortable), '');

        return [
            'view'        => $view,
            'q'           => ListParams::text('q'),
            'warehouse_id' => max(0, ListParams::int('warehouse_id')),
            'category_id' => ListParams::int('category_id'),
            'orderby'     => $orderby,
            'order'       => ListParams::order('asc'),
            'ids'         => $ids,
        ];
    }

    /**
     * Alle Artikel der aktuellen Ansicht/Filter, sortiert.
     *
     * @param array<int, int>|null $scope
     * @param array<int, array<string, mixed>> $all
     * @param array{view: string, q: string, warehouse_id: int, category_id: int, orderby: string, order: string} $p
     * @return array<int, array<string, mixed>>
     */
    public function select(?array $scope, array $all, array $p): array
    {
        $rows = $all;

        if ($p['q'] !== '' || $p['warehouse_id'] > 0 || $p['category_id'] !== 0) {
            $match = $this->products->query([
                'q'             => $p['q'],
                'warehouse_id'  => $p['warehouse_id'],
                'category_id'   => $p['category_id'],
                'warehouse_ids' => $scope,
                'per_page'      => self::MAX_PRODUCTS,
            ]);
            $ids  = array_flip(array_map(static fn (Product $prod): int => (int) $prod->id, $match['items']));
            $rows = array_values(array_filter($rows, static fn (array $row): bool => isset($ids[(int) $row['product']->id])));
        }

        $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['signals']->matches($p['view'])
            && (($p['ids'] ?? []) === [] || in_array((int) $row['product']->id, $p['ids'], true))));
        $dir  = $p['order'] === 'desc' ? -1 : 1;
        $cols = self::columns();

        usort($rows, static function (array $a, array $b) use ($p, $dir, $cols): int {
            $by_name = strnatcasecmp($a['product']->name, $b['product']->name);

            $cmp = match (true) {
                $p['orderby'] === 'stock'              => $a['balance'] <=> $b['balance'],
                $p['orderby'] === 'sold'               => $a['sold30'] <=> $b['sold30'],
                $p['orderby'] === 'name'               => $by_name,
                isset($cols[$p['orderby']]['compare']) => (int) ($cols[$p['orderby']]['compare'])($a, $b),
                default                                => $p['view'] === StockSignals::VIEW_ATTENTION ? $a['signals']->severity() <=> $b['signals']->severity() : $by_name,
            };

            return ($cmp * ($p['orderby'] === '' ? 1 : $dir)) ?: $by_name;
        });

        return $rows;
    }

    /* ==================== Uebersicht ==================== */

    /**
     * Zusaetzliche Spalten der Bestandsliste (nach "Verkauft 30 T.").
     *
     * @return array<string, array{label: string, class: string, compare?: callable}>
     */
    public static function columns(): array
    {
        /**
         * Filter kontor_shop/stock/columns: weitere Spalten der
         * Bestandsliste, z. B. Pro: "Reicht fuer". Den Inhalt je Zeile
         * liefert kontor_shop/stock/cell. Mit compare ist die Spalte
         * sortierbar (?orderby=<Schluessel>). Standard: keine.
         *
         * @param array<string, array{label: string, class?: string, compare?: callable(array, array): int}> $columns
         *        Schluessel = Spalten-Name; compare vergleicht zwei Zeilen aus rows()
         */
        $columns = (array) apply_filters('kontor_shop/stock/columns', []);
        $out     = [];

        foreach ($columns as $key => $column) {
            if (is_string($key) && preg_match('/^[a-z0-9_]+$/', $key) === 1 && is_array($column) && isset($column['label'])) {
                $out[$key] = ['label' => (string) $column['label'], 'class' => (string) ($column['class'] ?? '')]
                    + (isset($column['compare']) && is_callable($column['compare']) ? ['compare' => $column['compare']] : []);
            }
        }

        return $out;
    }

    /**
     * Zelle einer Zusatzspalte.
     *
     * @param array<string, mixed> $row
     */
    private static function cell(string $key, array $row, ?int $variant_id): string
    {
        /**
         * Filter kontor_shop/stock/cell: Inhalt einer Zusatzspalte aus
         * kontor_shop/stock/columns (HTML, escapen!). Standard: ''.
         *
         * @param string               $html
         * @param string               $key        Spalten-Schluessel
         * @param array<string, mixed> $row        Zeile aus rows() (product, balance, sold30, signals …)
         * @param int|null             $variant_id null = Artikelzeile, sonst die Varianten-Unterzeile
         */
        return (string) apply_filters('kontor_shop/stock/cell', '', $key, $row, $variant_id);
    }

    /**
     * @param array<int, int>|null $scope
     */
    public function render(?array $scope): void
    {
        $all      = $this->rows($scope);
        $p        = $this->params($all);
        $rows     = $this->select($scope, $all, $p);
        $per_page = ListParams::per_page();
        $page     = ListParams::page();
        $total    = count($rows);
        $page_rows = array_slice($rows, ($page - 1) * $per_page, $per_page);
        $base     = ['page' => StockPage::SLUG];
        $keep     = [
            'view'         => $p['view'],
            'q'            => $p['q'],
            'warehouse_id' => $p['warehouse_id'] ?: '',
            'category_id'  => $p['category_id'] ?: '',
            'orderby'      => $p['orderby'],
            'order'        => $p['orderby'] !== '' ? $p['order'] : '',
            'ids'          => implode(',', $p['ids']),
        ];
        $show_wh = $this->show_warehouse($scope);
        $columns = self::columns();
        $context = ['overview' => $this, 'scope' => $scope, 'all' => $all, 'params' => $p, 'keep' => $keep];

        echo '<div class="ks-stock-head"><h1 class="wp-heading-inline">' . esc_html__('Stock', 'kontor-shop') . '</h1>';
        echo '<button type="button" class="button button-primary" data-ks-dialog-open="' . esc_attr(self::RECEIVE_DIALOG) . '" data-ks-receive-open>' . esc_html__('+ Record delivery', 'kontor-shop') . '</button>';

        /**
         * Aktion kontor_shop/stock/toolbar: weitere Knoepfe im Kopf der
         * Bestandsliste (HTML, escapen!) - 'start' nach "+ Lieferung
         * erfassen" (z. B. Pro: "Nachbestellliste"), 'end' rechts aussen
         * (z. B. Pro: "CSV exportieren"). Ein Download-Link mit
         * data-ks-download zeigt beim Laden einen Spinner (stock.js).
         *
         * @param string $position 'start' | 'end'
         * @param array{overview: StockOverview, scope: array<int, int>|null, all: array<int, array<string, mixed>>, params: array<string, mixed>, keep: array<string, mixed>} $context
         *        all: alle Zeilen im Lager-Scope (rows()), params/keep: Ansicht und Filter der URL
         */
        do_action('kontor_shop/stock/toolbar', 'start', $context);

        echo '<a class="button" href="' . esc_url(StockPage::url(['mode' => 'count'])) . '">' . esc_html__('Start stock count', 'kontor-shop') . '</a>';
        echo '<button type="button" class="button" data-ks-dialog-open="' . esc_attr(self::INVENTORY_DIALOG) . '">' . esc_html__('Stock count list', 'kontor-shop') . '</button>';
        echo '<span class="ks-stock-head__spacer"></span>';

        // kontor_shop/stock/toolbar (Doku oben), rechts aussen.
        do_action('kontor_shop/stock/toolbar', 'end', $context);

        echo '</div><hr class="wp-header-end" />';

        $this->render_kpis($all, $scope, $p['view']);
        $this->render_views($all, $p['view'], $keep);
        $this->render_filters($scope, $p);

        if ($p['ids'] !== []) {
            echo '<p class="ks-direct-filter">' . esc_html(sprintf(
                /* translators: %d: count */
                _n('Filtered to %d product from the overview.', 'Filtered to %d products from the overview.', count($p['ids']), 'kontor-shop'),
                count($p['ids'])
            )) . ' <a href="' . esc_url(StockPage::url()) . '">' . esc_html__('Show all products', 'kontor-shop') . '</a></p>';
        }

        echo '<div class="ks-stock-bulk" data-ks-stock-bulk hidden><strong data-ks-bulk-count></strong>';

        /**
         * Aktion kontor_shop/stock/bulk_actions: weitere Knoepfe in der
         * Auswahl-Leiste (HTML), z. B. Pro: "Auf die Nachbestellliste". Ein
         * Knopf mit data-ks-bulk="<aktion>" loest in stock.js das Ereignis
         * "kontor-shop:stock-bulk" (detail: action, ids) am document aus.
         *
         * @param array<string, mixed> $context wie kontor_shop/stock/toolbar
         */
        do_action('kontor_shop/stock/bulk_actions', $context);

        echo '<button type="button" class="button button-small" data-ks-bulk="receive">' . esc_html__('Goods receipt for selection', 'kontor-shop') . '</button>'
            . '<button type="button" class="button button-small" data-ks-bulk="min">' . esc_html__('Set minimum stock …', 'kontor-shop') . '</button>'
            . '<button type="button" class="button-link" data-ks-bulk="clear">' . esc_html__('Clear selection', 'kontor-shop') . '</button></div>';

        if ($page_rows === []) {
            echo '<div class="ks-empty"><p><strong>' . esc_html(
                $p['view'] === StockSignals::VIEW_ATTENTION && $p['q'] === '' && $p['warehouse_id'] === 0 && $p['category_id'] === 0
                    ? __('Everything is in the green – nothing to do.', 'kontor-shop')
                    : __('No products found.', 'kontor-shop')
            ) . '</strong></p></div>';
        } else {
            echo '<div class="tablenav top">';
            Pagination::render($total, $page, $per_page, $base, $keep);
            Pagination::per_page_form($per_page, $base, $keep);
            echo '<br class="clear" /></div>';

            echo '<div class="ks-stock-tablewrap"><table class="wp-list-table widefat ks-table ks-stock-table"><thead><tr>';
            echo '<td class="manage-column check-column"><input type="checkbox" data-ks-stock-all aria-label="' . esc_attr__('Select all', 'kontor-shop') . '" /></td>';
            echo ListParams::sortable_th(__('Product', 'kontor-shop'), 'name', $p['orderby'], $p['order'], $base, $keep, 'column-primary'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ListParams::sortable_th liefert escaptes HTML
            echo $show_wh ? '<th>' . esc_html__('Warehouse', 'kontor-shop') . '</th>' : '';
            echo ListParams::sortable_th(__('Stock', 'kontor-shop'), 'stock', $p['orderby'], $p['order'], $base, $keep, 'ks-col-stock'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ListParams::sortable_th liefert escaptes HTML
            echo '<th class="ks-col-min">' . esc_html__('Minimum stock', 'kontor-shop') . '</th>';
            echo ListParams::sortable_th(__('Sold 30 d.', 'kontor-shop'), 'sold', $p['orderby'], $p['order'], $base, $keep, 'ks-col-sales'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ListParams::sortable_th liefert escaptes HTML

            foreach ($columns as $key => $column) {
                echo isset($column['compare'])
                    ? ListParams::sortable_th($column['label'], $key, $p['orderby'], $p['order'], $base, $keep, $column['class']) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ListParams::sortable_th liefert escaptes HTML
                    : '<th class="' . esc_attr($column['class']) . '">' . esc_html($column['label']) . '</th>';
            }

            echo self::requests_enabled() ? '<th>' . esc_html__('Requests', 'kontor-shop') . '</th>' : '';
            echo '<th>' . esc_html__('Last movement', 'kontor-shop') . '</th>';
            echo '<th class="ks-col-book">' . esc_html__('Book', 'kontor-shop') . '</th>';
            echo '</tr></thead>';

            foreach ($page_rows as $row) {
                echo $this->row_html($row, $show_wh, $columns); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- row_html liefert escaptes HTML (Formularfelder, daher kein wp_kses_post)
            }

            echo '</table></div>';

            echo '<div class="tablenav bottom">';
            Pagination::render($total, $page, $per_page, $base, $keep);
            echo '<br class="clear" /></div>';
        }

        $this->render_recent($scope);
        $this->render_receive_dialog($all);

        /**
         * Aktion kontor_shop/stock/dialogs: weitere Dialoge der
         * Bestandsliste (HTML), z. B. Pro: die Nachbestellliste.
         *
         * @param array<string, mixed> $context wie kontor_shop/stock/toolbar
         */
        do_action('kontor_shop/stock/dialogs', $context);
        $this->render_min_dialog();
        $this->render_inventory_dialog($scope, $p['warehouse_id'], $p['category_id']);
    }

    /**
     * @param array<int, array<string, mixed>> $all
     * @param array<int, int>|null $scope
     */
    private function render_kpis(array $all, ?array $scope, string $view): void
    {
        $count = static fn (string $v): int => count(array_filter($all, static fn (array $row): bool => $row['signals']->matches($v)));
        $req   = array_sum(array_map(static fn (array $row): int => $row['signals']->sellable ? $row['signals']->requests : 0, $all));
        $neg   = count(array_filter($all, static fn (array $row): bool => $row['signals']->is_negative()));
        $ship  = $this->stock->pending_shipments($scope);

        $tile = static fn (string $href, string $tone, int $value, string $label, string $sub, bool $current): string
            => Kpis::tile($href, $tone, number_format_i18n($value), $label, $sub, $current);

        echo '<div class="ks-kpis">';

        if ($neg > 0) {
            echo $tile(StockPage::url(['view' => StockSignals::VIEW_ATTENTION]), 'bad', $neg, __('Negative stock', 'kontor-shop'), __('Booking error – please check', 'kontor-shop'), false); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Kpis::tile liefert escaptes HTML
        }

        $out = $count(StockSignals::VIEW_OUT);
        echo $tile(StockPage::url(['view' => StockSignals::VIEW_OUT]), $out > 0 ? 'bad' : 'neutral', $out, __('Sold out', 'kontor-shop'), __('incl. individual sizes', 'kontor-shop'), $view === StockSignals::VIEW_OUT); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Kpis::tile liefert escaptes HTML

        $low = $count(StockSignals::VIEW_LOW);
        echo $tile(StockPage::url(['view' => StockSignals::VIEW_LOW]), $low > 0 ? 'warn' : 'neutral', $low, __('Low', 'kontor-shop'), __('at or below minimum stock', 'kontor-shop'), $view === StockSignals::VIEW_LOW); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Kpis::tile liefert escaptes HTML

        if (self::requests_enabled()) {
            $req_sub = sprintf(
                /* translators: %d: number of products */
                _n('on %d product', 'on %d products', $count(StockSignals::VIEW_REQUESTS), 'kontor-shop'),
                $count(StockSignals::VIEW_REQUESTS)
            );
            echo $tile(StockPage::url(['view' => StockSignals::VIEW_REQUESTS]), $req > 0 ? 'info' : 'neutral', $req, __('Customers waiting', 'kontor-shop'), $req_sub, $view === StockSignals::VIEW_REQUESTS); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Kpis::tile liefert escaptes HTML
        }

        $ship_sub = sprintf(
            /* translators: %d: number of orders */
            _n('in %d open order →', 'in %d open orders →', $ship['orders'], 'kontor-shop'),
            $ship['orders']
        );
        echo $tile(OrdersPage::url(), 'neutral', $ship['units'], __('units to ship', 'kontor-shop'), $ship_sub, false); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Kpis::tile liefert escaptes HTML

        echo '</div>';
    }

    /**
     * @param array<int, array<string, mixed>> $all
     * @param array<string, mixed> $keep
     */
    private function render_views(array $all, string $current, array $keep): void
    {
        $labels = [
            StockSignals::VIEW_ATTENTION => __('Needs action', 'kontor-shop'),
            StockSignals::VIEW_ALL       => __('All', 'kontor-shop'),
            StockSignals::VIEW_OUT       => __('Sold out', 'kontor-shop'),
            StockSignals::VIEW_LOW       => __('Low', 'kontor-shop'),
            StockSignals::VIEW_REQUESTS  => __('With requests', 'kontor-shop'),
            StockSignals::VIEW_NO_MIN    => __('Without minimum stock', 'kontor-shop'),
            StockSignals::VIEW_HIDDEN    => __('Not in shop', 'kontor-shop'),
        ];

        if (!self::requests_enabled()) {
            unset($labels[StockSignals::VIEW_REQUESTS]);
        }
        $views = [];

        foreach ($labels as $view => $label) {
            $n = count(array_filter($all, static fn (array $row): bool => $row['signals']->matches($view)));

            if ($n === 0 && in_array($view, [StockSignals::VIEW_HIDDEN, StockSignals::VIEW_REQUESTS], true) && $view !== $current) {
                continue;
            }

            $views[$view] = [
                'label' => $label,
                'url'   => ListParams::url(['page' => StockPage::SLUG], ['q' => $keep['q'], 'warehouse_id' => $keep['warehouse_id'], 'category_id' => $keep['category_id']], ['view' => $view]),
                'count' => $n,
                'hot'   => $view === StockSignals::VIEW_ATTENTION,
            ];
        }

        Views::render($views, $current);
    }

    /**
     * @param array<int, int>|null $scope
     * @param array{view: string, q: string, warehouse_id: int, category_id: int, orderby: string, order: string} $p
     */
    private function render_filters(?array $scope, array $p): void
    {
        echo '<form method="get" class="ks-filter-bar"><input type="hidden" name="page" value="' . esc_attr(StockPage::SLUG) . '" />';
        echo '<input type="hidden" name="view" value="' . esc_attr($p['view']) . '" />';
        echo '<input type="search" name="q" value="' . esc_attr($p['q']) . '" placeholder="' . esc_attr__('Scan name, product no., or EAN …', 'kontor-shop') . '" class="regular-text" aria-label="' . esc_attr__('Search products', 'kontor-shop') . '" data-ks-stock-search />';

        if ($this->show_warehouse($scope)) {
            echo '<select name="warehouse_id" aria-label="' . esc_attr__('Warehouse', 'kontor-shop') . '">' . Options::warehouses($p['warehouse_id'], __('All warehouses', 'kontor-shop'), $scope) . '</select>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Options liefert escaptes HTML
        }

        echo '<select name="category_id" aria-label="' . esc_attr__('Category', 'kontor-shop') . '">' . Options::categories($p['category_id'], __('All categories', 'kontor-shop')) . '</select>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Options liefert escaptes HTML
        echo '<button type="submit" class="button">' . esc_html__('Filter', 'kontor-shop') . '</button>';

        if ($p['q'] !== '' || $p['warehouse_id'] > 0 || $p['category_id'] !== 0) {
            echo ' <a href="' . esc_url(StockPage::url(['view' => $p['view']])) . '">' . esc_html__('Reset filter', 'kontor-shop') . '</a>';
        }

        echo '</form>';
    }

    /**
     * Eine Artikelzeile samt (eingeklappten) Varianten-Unterzeilen als eigenes
     * tbody - so kann sie nach einer Buchung per AJAX ersetzt werden.
     *
     * @param array<string, mixed> $row
     * @param array<string, array{label: string, class: string, compare?: callable}>|null $columns Zusatzspalten (null = columns())
     */
    public function row_html(array $row, bool $show_wh, ?array $columns = null): string
    {
        $columns ??= self::columns();
        /** @var Product $product */
        $product = $row['product'];
        /** @var StockSignals $signals */
        $signals = $row['signals'];
        $id      = (int) $product->id;
        $wh      = $this->warehouse_info(null)[$product->warehouse_id] ?? ['name' => '—', 'color' => '#c3c4c7'];
        $status  = $signals->status();
        $empty   = $signals->empty_variants();
        $active  = array_values(array_filter($row['variants'], static fn (Variant $v): bool => $v->active));
        $tone    = $signals->is_out() || $signals->is_negative() ? ' is-out' : ($signals->is_low() ? ' is-low' : '');

        $meta = array_filter([
            $product->sku,
            $product->category_id !== null ? ($this->category_names()[$product->category_id] ?? '') : '',
        ]);

        $name_cell = '<div class="ks-stock-art">' . $this->thumb($product)
            . '<div><strong><a href="' . esc_url(StockPage::url(['product_id' => $id])) . '">' . esc_html($product->name) . '</a></strong>'
            . ($product->active ? '' : ' ' . Badge::html(__('not in shop', 'kontor-shop'), Badge::MUTED))
            . ($product->discontinued ? ' ' . Badge::html(__('Discontinued product', 'kontor-shop'), Badge::MUTED) : '')
            . '<div class="ks-muted ks-stock-meta">' . esc_html(implode(' · ', $meta)) . '</div>'
            . ($empty > 0 ? '<div class="ks-stock-meta ks-stock--out"><strong>' . esc_html(sprintf(
                /* translators: %d: number of empty sizes/variants */
                _n('%d variant empty', '%d variants empty', $empty, 'kontor-shop'),
                $empty
            )) . '</strong></div>' : '');

        if ($active !== []) {
            $name_cell .= '<div class="ks-chips">';

            foreach ($active as $variant) {
                $name_cell .= $this->chip($variant->label() . ' ' . ($row['vbal'][(int) $variant->id] ?? 0), $row['vbal'][(int) $variant->id] ?? 0, $product->min_stock);
            }

            $name_cell .= ' <button type="button" class="button-link ks-stock-expand" data-ks-stock-expand aria-expanded="false">' . esc_html__('▸ Variants', 'kontor-shop') . '</button></div>';
        }

        $name_cell .= '</div></div>';

        $bound = array_sum($row['bound']);
        $req   = array_sum($row['requests']);

        $html = '<tbody class="ks-stock-item" data-ks-stock-item="' . $id . '" data-ks-stock="' . esc_attr((string) wp_json_encode($this->booking_data($row))) . '">';
        $html .= '<tr class="ks-stock-row' . $tone . '">';
        $html .= '<th scope="row" class="check-column"><input type="checkbox" value="' . $id . '" data-ks-stock-select aria-label="' . esc_attr(sprintf(
            /* translators: %s: product name */
            __('Select %s', 'kontor-shop'),
            $product->name
        )) . '" /></th>';
        $html .= '<td class="column-primary">' . $name_cell . '</td>';
        $html .= $show_wh ? '<td class="ks-stock-wh"><span class="ks-wh-dot ' . Css::wh($product->warehouse_id) . '"></span>' . esc_html($wh['name']) . '</td>' : '';
        $html .= '<td class="ks-col-stock">' . $this->stock_cell($row['balance'], $product->min_stock, $bound, $status) . '</td>';
        $html .= '<td class="ks-col-min"><button type="button" class="ks-min' . ($product->min_stock === null ? ' is-missing' : '') . '" data-ks-min="' . $id . '" data-value="'
            . esc_attr($product->min_stock !== null ? (string) $product->min_stock : '') . '" title="' . esc_attr__('Change minimum stock', 'kontor-shop') . '">'
            . esc_html($product->min_stock !== null ? (string) $product->min_stock : __('missing', 'kontor-shop')) . ' <span aria-hidden="true">✎</span></button></td>';
        $html .= '<td class="ks-col-sales"><span class="ks-sales"><strong>' . (int) $row['sold30'] . '</strong>' . $this->sparkline($row['weekly']) . '</span></td>';

        foreach ($columns as $key => $column) {
            $html .= '<td class="' . esc_attr($column['class']) . '">' . self::cell($key, $row, null) . '</td>';
        }

        $html .= self::requests_enabled() ? '<td>' . ($req > 0 ? $this->requests_link($id, $req) : '<span class="ks-muted">—</span>') . '</td>' : '';
        $html .= '<td class="ks-stock-last">' . $this->last_html($row['last']) . '</td>';
        $html .= '<td class="ks-col-book">' . $this->book_buttons($id, null) . '</td>';
        $html .= '</tr>';

        foreach ($active as $variant) {
            $vid = (int) $variant->id;
            $bal = $row['vbal'][$vid] ?? 0;
            $vb  = $row['bound'][$vid] ?? 0;
            $vr  = $row['requests'][$vid] ?? 0;

            $html .= '<tr class="ks-stock-vrow" hidden><td></td><td class="ks-stock-vname">' . esc_html($variant->label())
                . ($variant->sku !== '' ? ' <span class="ks-muted">' . esc_html($variant->sku) . '</span>' : '') . '</td>'
                . ($show_wh ? '<td></td>' : '')
                . '<td>' . $this->chip((string) $bal, $bal, $product->min_stock)
                . ($vb > 0 ? ' <span class="ks-muted">' . esc_html(sprintf(
                    /* translators: %d: units */
                    __('+%d for orders', 'kontor-shop'),
                    $vb
                )) . '</span>' : '') . '</td><td></td>'
                . '<td>' . (int) ($row['sold'][$vid] ?? 0) . '</td>'
                . implode('', array_map(static fn (string $key): string => '<td>' . self::cell($key, $row, $vid) . '</td>', array_keys($columns)))
                . (self::requests_enabled() ? '<td>' . ($vr > 0 ? $this->requests_link($id, $vr) : '') . '</td>' : '') . '<td></td>'
                . '<td class="ks-col-book">' . $this->book_buttons($id, $vid) . '</td></tr>';
        }

        return $html . '</tbody>';
    }

    /**
     * Daten fuer das Buchen-Popover (Varianten mit aktuellem Bestand).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function booking_data(array $row): array
    {
        /** @var Product $product */
        $product  = $row['product'];
        $variants = [];

        foreach ($row['variants'] as $variant) {
            $bal = $row['vbal'][(int) $variant->id] ?? 0;

            if ($variant->active || $bal !== 0) {
                $variants[] = ['id' => (int) $variant->id, 'label' => $variant->label() . ($variant->active ? '' : ' (' . __('inactive', 'kontor-shop') . ')'), 'balance' => $bal];
            }
        }

        if ($product->has_variants() && ($row['vbal'][0] ?? 0) !== 0) {
            $variants[] = ['id' => 0, 'label' => __('no variant', 'kontor-shop'), 'balance' => $row['vbal'][0], 'outOnly' => true];
        }

        return [
            'id'       => (int) $product->id,
            'name'     => $product->name,
            'balance'  => $row['balance'],
            'variants' => $product->has_variants() ? $variants : [],
        ];
    }

    private function book_buttons(int $id, ?int $variant_id): string
    {
        $v = $variant_id !== null ? ' data-variant="' . $variant_id . '"' : '';

        return '<span class="ks-book">'
            . '<button type="button" class="button button-small ks-book--in" data-ks-book="in" data-product="' . $id . '"' . $v . '>' . esc_html($variant_id !== null ? '+' : __('+ Stock in', 'kontor-shop')) . '</button>'
            . '<button type="button" class="button button-small ks-book--out" data-ks-book="out" data-product="' . $id . '"' . $v . '>' . esc_html($variant_id !== null ? '−' : __('− Stock out', 'kontor-shop')) . '</button>'
            . '</span>';
    }

    private function thumb(Product $product): string
    {
        if ($product->image_id !== null) {
            $img = wp_get_attachment_image($product->image_id, [40, 40], false, ['class' => 'ks-stock-thumb', 'alt' => '']);

            if ($img !== '') {
                return $img;
            }
        }

        return '<span class="ks-stock-thumb ks-stock-thumb--empty" aria-hidden="true">' . esc_html(mb_strtoupper(mb_substr($product->name, 0, 1))) . '</span>';
    }

    private function chip(string $text, int $balance, ?int $min): string
    {
        $status = StockStatus::classify($balance, $min);

        return '<span class="ks-chip ks-chip--' . esc_attr($status) . '">' . esc_html($text) . '</span>';
    }

    private function stock_cell(int $balance, ?int $min, int $bound, string $status): string
    {
        $scale  = max($min !== null ? $min * 3 : 0, $balance, 1);
        $pct    = max(0, min(100, $balance / $scale * 100));
        $marker = $min !== null && $min > 0 ? '<i class="' . Css::at($min / $scale * 100) . '"></i>' : '';

        return '<div class="ks-stock-cell"><span class="ks-stock-line">' . Badge::stock($balance, $min) . '</span>'
            . '<span class="ks-stock-bar" title="' . esc_attr__('Line = minimum stock', 'kontor-shop') . '"><b class="ks-stock-bar--' . esc_attr($status) . ' ' . Css::pct($pct) . '"></b>' . $marker . '</span>'
            . ($bound > 0 ? '<span class="ks-muted ks-stock-shelf">' . esc_html(sprintf(
                /* translators: 1: units on shelf, 2: of which for orders */
                __('on shelf %1$d · %2$d for orders', 'kontor-shop'),
                $balance + $bound,
                $bound
            )) . '</span>' : '')
            . '</div>';
    }

    /**
     * @param array<int, int> $weekly
     */
    private function sparkline(array $weekly): string
    {
        $w    = 64;
        $h    = 18;
        $max  = max(1, ...$weekly);
        $step = $w / max(1, count($weekly) - 1);
        $pts  = [];

        foreach (array_values($weekly) as $i => $value) {
            $pts[] = round($i * $step, 1) . ',' . round($h - 2 - ($value / $max) * ($h - 4), 1);
        }

        [$lx, $ly] = explode(',', (string) end($pts));

        return '<svg class="ks-spark" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" aria-hidden="true">'
            . '<polygon points="0,' . $h . ' ' . implode(' ', $pts) . ' ' . $w . ',' . $h . '" fill="currentColor" fill-opacity=".12" />'
            . '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="currentColor" stroke-width="1.5" />'
            . '<circle cx="' . $lx . '" cy="' . $ly . '" r="2.2" fill="currentColor" /></svg>'
            . '<span class="screen-reader-text">' . esc_html(sprintf(
                /* translators: %s: sales per week */
                __('Sales per week: %s', 'kontor-shop'),
                implode(', ', $weekly)
            )) . '</span>';
    }

    private function requests_link(int $product_id, int $count): string
    {
        $label = sprintf(
            /* translators: %d: number of waiting customers */
            _n('%d waiting', '%d waiting', $count, 'kontor-shop'),
            $count
        );

        /**
         * Filter kontor_shop/stock/requests_link: Link vom Zaehler "x wartet"
         * auf die Anfragen zum Artikel - oder null (nur der Zaehler, z. B.
         * ohne Recht auf die Anfragen-Seite). Standard: null.
         *
         * @param string|null $url
         * @param int         $product_id
         */
        $url = apply_filters('kontor_shop/stock/requests_link', null, $product_id);

        return is_string($url) && $url !== ''
            ? '<a href="' . esc_url($url) . '">' . Badge::html($label, Badge::INFO) . '</a>'
            : Badge::html($label, Badge::INFO);
    }

    /**
     * @param array{type: string, created_at: string}|null $last
     */
    private function last_html(?array $last): string
    {
        if ($last === null) {
            return '<span class="ks-muted">' . esc_html__('none yet', 'kontor-shop') . '</span>';
        }

        return '<span title="' . esc_attr(mysql2date('d.m.Y H:i', $last['created_at'])) . '">' . esc_html(self::days_ago($last['created_at'])) . '</span><br />'
            . '<span class="ks-muted">' . esc_html(MovementType::labels()[$last['type']] ?? $last['type']) . '</span>';
    }

    public static function days_ago(string $mysql_date): string
    {
        $days = (int) floor((strtotime(current_time('Y-m-d')) - strtotime(substr($mysql_date, 0, 10))) / DAY_IN_SECONDS);

        return match (true) {
            $days <= 0 => __('today', 'kontor-shop'),
            $days === 1 => __('yesterday', 'kontor-shop'),
            /* translators: %d: days */
            default    => sprintf(__('%d days ago', 'kontor-shop'), $days),
        };
    }

    /**
     * @param array<int, int>|null $scope
     */
    private function render_recent(?array $scope): void
    {
        $rows = $this->stock->recent($scope, self::RECENT);

        if ($rows === []) {
            return;
        }

        $variant_ids = array_values(array_unique(array_filter(array_map(static fn (array $r): int => (int) ($r['variant_id'] ?? 0), $rows))));
        $labels      = [];

        foreach ($variant_ids as $vid) {
            $labels[$vid] = $this->variants->find($vid)?->label() ?? '#' . $vid;
        }

        $types = MovementType::labels();

        echo '<section class="ks-card ks-stock-recent"><h2>' . esc_html__('Recent movements', 'kontor-shop') . '</h2><ul class="ks-feed">';

        foreach ($rows as $r) {
            $delta = (int) $r['quantity_delta'];
            $user  = !empty($r['user_id']) ? get_userdata((int) $r['user_id']) : false;
            $vid   = (int) ($r['variant_id'] ?? 0);
            $what  = $types[(string) $r['type']] ?? (string) $r['type'];

            if (!empty($r['order_id'])) {
                $what = esc_html($what) . ' · <a href="' . esc_url(OrdersPage::url(['order' => (int) $r['order_id']])) . '">' . esc_html(!empty($r['order_number'])
                    ? (string) $r['order_number']
                    /* translators: %d: order ID */
                    : sprintf(__('Order #%d', 'kontor-shop'), (int) $r['order_id'])) . '</a>';
            } else {
                $what = esc_html($what);
            }

            if (!empty($r['note'])) {
                $what .= ' · ' . esc_html((string) $r['note']);
            }

            echo '<li><span class="ks-feed__delta ' . ($delta < 0 ? 'is-neg' : 'is-pos') . '">' . esc_html(($delta > 0 ? '+' : '') . $delta) . '</span>'
                . '<span><a href="' . esc_url(StockPage::url(['product_id' => (int) $r['product_id']])) . '"><strong>' . esc_html((string) $r['product_name'] . ($vid > 0 ? ' – ' . ($labels[$vid] ?? '') : '')) . '</strong></a><br />'
                . '<span class="ks-muted">' . $what . ' · ' . esc_html($user ? $user->display_name : __('System', 'kontor-shop')) . '</span></span>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $what oben aus esc_html/esc_url-Teilen gebaut
                . '<span class="ks-muted ks-feed__when">' . esc_html(mysql2date('d.m. H:i', (string) $r['created_at'])) . '</span></li>';
        }

        echo '</ul></section>';
    }

    /* ==================== Dialoge ==================== */

    /**
     * @param array<int, array<string, mixed>> $all
     */
    private function render_receive_dialog(array $all): void
    {
        $options = '';

        foreach ($all as $row) {
            /** @var Product $product */
            $product = $row['product'];

            if ($product->has_variants()) {
                foreach ($row['variants'] as $variant) {
                    if ($variant->active) {
                        $options .= $this->receive_option((int) $product->id, (int) $variant->id, $product->name . ' – ' . $variant->label(), $row['vbal'][(int) $variant->id] ?? 0, $variant->sku, $variant->gtin);
                    }
                }
            } else {
                $options .= $this->receive_option((int) $product->id, 0, $product->name, $row['balance'], $product->sku, $product->gtin);
            }
        }

        echo '<dialog class="ks-dialog ks-dialog--wide" id="' . esc_attr(self::RECEIVE_DIALOG) . '" aria-labelledby="' . esc_attr(self::RECEIVE_DIALOG . '-title') . '">';
        echo '<form method="post" action="' . esc_url(StockPage::url()) . '" class="ks-dialog__form" data-ks-receive>';
        wp_nonce_field(StockPage::NONCE_ACTION, 'kontor_shop_stock_nonce');
        echo '<input type="hidden" name="stock_action" value="receive" />';
        echo '<div class="ks-dialog__head"><h2 id="' . esc_attr(self::RECEIVE_DIALOG . '-title') . '">' . esc_html__('Record delivery', 'kontor-shop') . '</h2>'
            . '<button type="button" class="ks-dialog__close" data-ks-dialog-close aria-label="' . esc_attr__('Close', 'kontor-shop') . '">×</button></div>';
        echo '<div class="ks-dialog__body">';
        echo '<p class="description">' . esc_html__('Book in all products of a delivery at once. Rows without a quantity are skipped.', 'kontor-shop') . '</p>';
        echo '<p><label for="ks-receive-note"><strong>' . esc_html__('Delivery note / note (applies to all rows)', 'kontor-shop') . '</strong></label><br />'
            . '<input type="text" id="ks-receive-note" name="note" class="large-text" placeholder="' . esc_attr__('e.g. delivery note 2026-118, Huber Embroidery', 'kontor-shop') . '" /></p>';
        echo '<p class="ks-receive-add"><select data-ks-receive-pick aria-label="' . esc_attr__('Add product', 'kontor-shop') . '"><option value="">'
            . esc_html__('Add product or variant …', 'kontor-shop') . '</option>' . $options . '</select>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $options aus receive_option (Text escapt, sonst nur int-Werte)
            . ' <input type="search" data-ks-receive-scan placeholder="' . esc_attr__('or scan product no./EAN + Enter', 'kontor-shop') . '" /></p>';
        echo '<table class="widefat ks-table ks-dialog-table"><thead><tr><th>' . esc_html__('Product', 'kontor-shop') . '</th><th class="ks-col-num">' . esc_html__('Stock', 'kontor-shop')
            . '</th><th class="ks-col-num">' . esc_html__('Quantity', 'kontor-shop') . '</th><th class="ks-col-num">' . esc_html__('then', 'kontor-shop') . '</th><th></th></tr></thead>'
            . '<tbody data-ks-receive-rows><tr class="ks-receive-empty"><td colspan="5" class="ks-muted">' . esc_html__('No products yet – select above or scan.', 'kontor-shop') . '</td></tr></tbody></table>';
        echo '</div><div class="ks-dialog__foot"><button type="button" class="button" data-ks-dialog-close>' . esc_html__('Cancel', 'kontor-shop') . '</button>'
            . '<button type="submit" class="button button-primary" data-ks-receive-submit>' . esc_html__('Book delivery', 'kontor-shop') . '</button></div>';
        echo '</form></dialog>';
    }

    private function receive_option(int $product_id, int $variant_id, string $name, int $balance, string $sku, string $gtin): string
    {
        return '<option value="' . $product_id . ':' . $variant_id . '" data-product="' . $product_id . '" data-balance="' . $balance . '" data-codes="'
            . esc_attr(strtolower(trim($sku . ' ' . $gtin))) . '" data-name="' . esc_attr($name) . '">' . esc_html($name . ' (' . $balance . ')') . '</option>';
    }

    private function render_min_dialog(): void
    {
        echo '<dialog class="ks-dialog" id="' . esc_attr(self::MIN_DIALOG) . '" aria-labelledby="' . esc_attr(self::MIN_DIALOG . '-title') . '">';
        echo '<form method="post" action="' . esc_url(StockPage::url()) . '" class="ks-dialog__form" data-ks-min-bulk>';
        wp_nonce_field(StockPage::NONCE_ACTION, 'kontor_shop_stock_nonce');
        echo '<input type="hidden" name="stock_action" value="min_bulk" /><input type="hidden" name="product_ids" value="" data-ks-min-ids />';
        echo '<div class="ks-dialog__head"><h2 id="' . esc_attr(self::MIN_DIALOG . '-title') . '">' . esc_html__('Set minimum stock', 'kontor-shop') . '</h2>'
            . '<button type="button" class="ks-dialog__close" data-ks-dialog-close aria-label="' . esc_attr__('Close', 'kontor-shop') . '">×</button></div>';
        echo '<div class="ks-dialog__body"><p data-ks-min-summary></p>';
        echo '<p><label for="ks-min-all"><strong>' . esc_html__('Minimum stock', 'kontor-shop') . '</strong></label><br /><input type="number" min="0" step="1" id="ks-min-all" name="min_stock" class="small-text" value="3" /></p>';
        echo '<p class="description">' . esc_html__('If stock is at or below this value, the product counts as “low”. Leave empty to remove the minimum stock.', 'kontor-shop') . '</p></div>';
        echo '<div class="ks-dialog__foot"><button type="button" class="button" data-ks-dialog-close>' . esc_html__('Cancel', 'kontor-shop') . '</button>'
            . '<button type="submit" class="button button-primary">' . esc_html__('Apply', 'kontor-shop') . '</button></div>';
        echo '</form></dialog>';
    }

    /* ==================== Inventur ==================== */

    /**
     * Zaehlliste: je Artikel bzw. Variante das Soll im Regal und ein Feld fuer
     * die gezaehlte Menge. Gebucht werden nur Abweichungen (StockPage::count_commit).
     *
     * @param array<int, int>|null $scope
     */
    public function render_count(?array $scope): void
    {
        $wh   = max(0, ListParams::int('warehouse_id'));
        $rows = $this->rows($scope, ['warehouse_id' => $wh]);

        echo '<h1 class="wp-heading-inline">' . esc_html__('Stock count', 'kontor-shop') . '</h1> ';
        echo '<a href="' . esc_url(StockPage::url()) . '" class="page-title-action">' . esc_html__('Cancel, back to dashboard', 'kontor-shop') . '</a><hr class="wp-header-end" />';
        echo '<div class="ks-count-intro"><p>' . esc_html__('Count what is actually on the shelf and enter it. “Expected on shelf” includes goods that are already sold but not yet shipped or picked up – count those too. Empty fields are not booked; only differences are saved as a correction with the note “Stock count”.', 'kontor-shop') . '</p>';

        if ($this->show_warehouse($scope)) {
            echo '<form method="get" class="ks-filter-bar"><input type="hidden" name="page" value="' . esc_attr(StockPage::SLUG) . '" /><input type="hidden" name="mode" value="count" />'
                . '<select name="warehouse_id" aria-label="' . esc_attr__('Warehouse', 'kontor-shop') . '">' . Options::warehouses($wh, __('All warehouses', 'kontor-shop'), $scope) . '</select>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Options liefert escaptes HTML
                . '<button type="submit" class="button">' . esc_html__('Select warehouse', 'kontor-shop') . '</button></form>';
        }

        echo '<p><a class="button" data-ks-download href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?' . http_build_query(array_filter([
            'action'       => StockPage::INVENTORY_ACTION,
            'warehouse_id' => $wh,
        ]))), StockPage::INVENTORY_ACTION)) . '">' . esc_html__('Count sheet as CSV (for printing)', 'kontor-shop') . '</a></p>';
        echo '</div>';

        echo '<form method="post" action="' . esc_url(StockPage::url(['mode' => 'count', 'warehouse_id' => $wh ?: null])) . '" data-ks-count data-ks-count-key="' . esc_attr('ks-count-' . $wh) . '">';
        wp_nonce_field(StockPage::NONCE_ACTION, 'kontor_shop_stock_nonce');
        echo '<input type="hidden" name="stock_action" value="count" />';

        echo '<div class="ks-count-bar"><strong data-ks-count-summary>' . esc_html__('Nothing counted yet.', 'kontor-shop') . '</strong>'
            . '<input type="text" name="note" class="regular-text" placeholder="' . esc_attr__('Additional note (optional), e.g. annual stock count', 'kontor-shop') . '" aria-label="' . esc_attr__('Note', 'kontor-shop') . '" />'
            . '<button type="submit" class="button button-primary">' . esc_html__('Book differences', 'kontor-shop') . '</button></div>';

        echo '<div class="ks-stock-tablewrap"><table class="wp-list-table widefat striped ks-table ks-count-table"><thead><tr><th>' . esc_html__('Product', 'kontor-shop') . '</th>'
            . '<th class="ks-col-num">' . esc_html__('Expected on shelf', 'kontor-shop') . '</th><th class="ks-col-num">' . esc_html__('Counted', 'kontor-shop') . '</th>'
            . '<th class="ks-col-num">' . esc_html__('Difference', 'kontor-shop') . '</th></tr></thead><tbody>';

        $n = 0;

        foreach ($rows as $row) {
            /** @var Product $product */
            $product = $row['product'];

            foreach (self::count_lines($row) as $line) {
                [$vid, $sku, $bal, $bound] = [$line['variant_id'], $line['sku'], $line['balance'], $line['bound']];
                $name = $product->name . ($line['variant'] !== '' ? ' – ' . $line['variant'] : '');
                $soll = $bal + $bound;
                $n++;

                echo '<tr><td><strong>' . esc_html($name) . '</strong>' . ($product->active ? '' : ' ' . Badge::html(__('not in shop', 'kontor-shop'), Badge::MUTED)) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Badge::html liefert escaptes HTML
                    . ($sku !== '' ? '<br /><small class="ks-muted">' . esc_html($sku) . '</small>' : '') . '</td>'
                    . '<td class="ks-col-num"><strong>' . (int) $soll . '</strong>' . ($bound > 0 ? '<br /><small class="ks-muted">' . esc_html(sprintf(
                        /* translators: %d: units */
                        __('of which %d sold, not shipped', 'kontor-shop'),
                        $bound
                    )) . '</small>' : '') . '</td>'
                    . '<td class="ks-col-num"><input type="number" min="0" step="1" class="small-text ks-count-input" id="' . esc_attr('ks-count-' . (int) $product->id . '-' . (int) $vid) . '"'
                    . ' name="' . esc_attr('counts[' . (int) $product->id . '][' . (int) $vid . ']') . '" placeholder="' . esc_attr((string) (int) $soll) . '" data-soll="' . esc_attr((string) (int) $soll) . '" aria-label="' . esc_attr(sprintf(
                        /* translators: %s: product */
                        __('Counted: %s', 'kontor-shop'),
                        $name
                    )) . '" /></td>'
                    . '<td class="ks-col-num"><span class="ks-diff" data-ks-diff>–</span></td></tr>';
            }
        }

        if ($n === 0) {
            echo '<tr><td colspan="4">' . esc_html__('No products in this warehouse.', 'kontor-shop') . '</td></tr>';
        }

        echo '</tbody></table></div></form>';
    }

    /**
     * Zaehlzeilen eines Artikels: ohne Varianten eine Zeile, sonst je aktiver
     * (oder noch bestueckter) Variante plus ein Restbestand "ohne Variante".
     * Soll im Regal = balance + bound (verkauft, noch nicht verschickt).
     *
     * @param array<string, mixed> $row
     * @return array<int, array{variant_id: int, variant: string, sku: string, gtin: string, balance: int, bound: int}>
     */
    public static function count_lines(array $row): array
    {
        /** @var Product $product */
        $product = $row['product'];

        if (!$product->has_variants()) {
            return [['variant_id' => 0, 'variant' => '', 'sku' => $product->sku, 'gtin' => $product->gtin, 'balance' => (int) $row['balance'], 'bound' => (int) array_sum($row['bound'])]];
        }

        $lines = [];

        foreach ($row['variants'] as $variant) {
            $vid = (int) $variant->id;
            $bal = $row['vbal'][$vid] ?? 0;

            if ($variant->active || $bal !== 0) {
                $lines[] = ['variant_id' => $vid, 'variant' => $variant->label(), 'sku' => $variant->sku, 'gtin' => $variant->gtin, 'balance' => $bal, 'bound' => $row['bound'][$vid] ?? 0];
            }
        }

        if (($row['vbal'][0] ?? 0) !== 0) {
            $lines[] = ['variant_id' => 0, 'variant' => __('no variant', 'kontor-shop'), 'sku' => '', 'gtin' => '', 'balance' => $row['vbal'][0], 'bound' => $row['bound'][0] ?? 0];
        }

        return $lines;
    }

    /**
     * Auswahl fuer die Inventurliste (Zaehlliste zum Ausdrucken bzw. Abhaken
     * in Excel), vorbelegt mit dem Lager-/Kategoriefilter der Uebersicht.
     *
     * @param array<int, int>|null $scope
     */
    private function render_inventory_dialog(?array $scope, int $warehouse_id, int $category_id): void
    {
        $id = self::INVENTORY_DIALOG;

        echo '<dialog class="ks-dialog" id="' . esc_attr($id) . '" aria-labelledby="' . esc_attr($id . '-title') . '">';
        echo '<form method="get" action="' . esc_url(admin_url('admin-post.php')) . '" class="ks-dialog__form" data-ks-download-form>';
        echo '<input type="hidden" name="action" value="' . esc_attr(StockPage::INVENTORY_ACTION) . '" />';
        wp_nonce_field(StockPage::INVENTORY_ACTION, '_wpnonce', false);
        echo '<div class="ks-dialog__head"><h2 id="' . esc_attr($id . '-title') . '">' . esc_html__('Export stock count list', 'kontor-shop') . '</h2>'
            . '<button type="button" class="ks-dialog__close" data-ks-dialog-close aria-label="' . esc_attr__('Close', 'kontor-shop') . '">×</button></div>';
        echo '<div class="ks-dialog__body"><p>' . esc_html__('Count list with the expected quantity on the shelf and empty “Counted” and “Notes” columns – to print or fill in with Excel. Sorted by warehouse, category, and product.', 'kontor-shop') . '</p>';

        if ($this->show_warehouse($scope)) {
            echo '<p><label for="' . esc_attr($id . '-wh') . '"><strong>' . esc_html__('Warehouse', 'kontor-shop') . '</strong></label><br />'
                . '<select id="' . esc_attr($id . '-wh') . '" name="warehouse_id">' . Options::warehouses($warehouse_id, __('All warehouses', 'kontor-shop'), $scope) . '</select></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Options liefert escaptes HTML
        }

        echo '<p><label for="' . esc_attr($id . '-cat') . '"><strong>' . esc_html__('Category', 'kontor-shop') . '</strong></label><br />'
            . '<select id="' . esc_attr($id . '-cat') . '" name="category_id">' . Options::categories($category_id, __('All categories', 'kontor-shop'), '<option value="-1"' . selected($category_id, -1, false) . '>' . esc_html__('no category', 'kontor-shop') . '</option>') . '</select></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Options liefert escaptes HTML, Zusatzoption oben escapt
        echo '<p><label><input type="checkbox" name="skip_empty" value="1" /> ' . esc_html__('Leave out products with an expected quantity of 0', 'kontor-shop') . '</label><br />'
            . '<span class="description">' . esc_html__('For a full stock count, better to keep them in – that also catches goods that the system says are no longer there.', 'kontor-shop') . '</span></p>';
        echo '<p class="ks-dialog__error notice notice-error inline" data-ks-download-error hidden></p></div>';
        echo '<div class="ks-dialog__foot"><button type="button" class="button" data-ks-dialog-close>' . esc_html__('Cancel', 'kontor-shop') . '</button>'
            . '<button type="submit" class="button button-primary">' . esc_html__('Download CSV', 'kontor-shop') . '</button></div>';
        echo '</form></dialog>';
    }

    /**
     * Zeilen der Inventurliste (ohne Kopf).
     *
     * @param array<int, int>|null $scope
     * @return array<int, array<int, string>>
     */
    public function inventory_rows(?array $scope, int $warehouse_id, int $category_id, bool $skip_empty): array
    {
        $rows       = $this->rows($scope, array_filter(['warehouse_id' => $warehouse_id, 'category_id' => $category_id]));
        $warehouses = $this->warehouse_info(null);
        $categories = $this->category_names();
        $out        = [];

        foreach ($rows as $row) {
            /** @var Product $product */
            $product = $row['product'];
            $wh      = $warehouses[$product->warehouse_id]['name'] ?? '';
            $cat     = $product->category_id !== null ? ($categories[$product->category_id] ?? '') : '';

            foreach (self::count_lines($row) as $line) {
                $soll = $line['balance'] + $line['bound'];

                if ($skip_empty && $soll === 0) {
                    continue;
                }

                $out[] = [
                    $wh, $cat, $product->name, $line['variant'], $line['sku'], $line['gtin'],
                    (string) $soll, $line['bound'] > 0 ? (string) $line['bound'] : '', '', $product->active ? '' : __('not in shop', 'kontor-shop'),
                ];
            }
        }

        // Reihenfolge zum Abgehen: Lager, Kategorie, dann Artikelname (rows() liefert schon nach Name).
        usort($out, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return $out;
    }

    /* ==================== Hilfen ==================== */

    /**
     * Lager-Spalte nur, wenn der Benutzer mehr als ein Lager sieht.
     *
     * @param array<int, int>|null $scope
     */
    public function show_warehouse(?array $scope): bool
    {
        return count($this->warehouse_info($scope)) > 1;
    }

    /**
     * Lager im Scope: id => Name/Farbe.
     *
     * @param array<int, int>|null $scope
     * @return array<int, array{name: string, color: string}>
     */
    private function warehouse_info(?array $scope): array
    {
        if ($this->warehouse_info === null) {
            $this->warehouse_info = [];

            foreach ($this->warehouses->all() as $warehouse) {
                $this->warehouse_info[(int) $warehouse->id] = ['name' => $warehouse->name, 'color' => $warehouse->display_color()];
            }
        }

        return $scope === null ? $this->warehouse_info : array_intersect_key($this->warehouse_info, array_flip($scope));
    }

    /**
     * @return array<int, string>
     */
    private function category_names(): array
    {
        if ($this->category_names === null) {
            $this->category_names = [];

            foreach ((new CategoryRepository())->all() as $category) {
                $this->category_names[(int) $category->id] = $category->name;
            }
        }

        return $this->category_names;
    }
}
