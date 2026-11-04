<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- eigene Tabellen des Plugins, kein WP-Objektcache-Bezug

use Kontor\Shop\Domain\Audit\ChangeSet;
use Kontor\Shop\Domain\Catalog\Product;
use Kontor\Shop\Domain\Catalog\ProductTools;
use Kontor\Shop\Domain\Catalog\UnitPrice;
use Kontor\Shop\Domain\Catalog\VariantMatrix;
use Kontor\Shop\Domain\Seo\ChangePlan;
use Kontor\Shop\Domain\Seo\Slug;
use Kontor\Shop\Infrastructure\Audit\AuditLog;
use Kontor\Shop\Infrastructure\Seo\CatalogEvents;

/**
 * wpdb-Zugriff auf wp_kontor_shop_products (Abschnitt 5.3). Jede Aenderung
 * landet mit alt/neu im Aenderungsprotokoll (AuditLog).
 */
final class ProductRepository
{
    /** Spalten, die fuer das Aenderungsprotokoll nicht zaehlen */
    private const AUDIT_IGNORE = ['created_at', 'updated_at', 'content_updated_at'];

    private function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kontor_shop_products';
    }

    /**
     * Alle nicht archivierten Artikel (die "Produktivliste").
     *
     * @return array<int, Product>
     */
    public function all(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE archived = 0 ORDER BY name ASC', $this->table()), ARRAY_A);

        return array_map([Product::class, 'from_row'], $rows ?: []);
    }

    /**
     * @return array<int, Product>
     */
    public function archived(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE archived = 1 ORDER BY name ASC', $this->table()), ARRAY_A);

        return array_map([Product::class, 'from_row'], $rows ?: []);
    }

    public function set_archived(int $id, bool $archived): void
    {
        $this->commit($id, ['archived' => $archived ? 1 : 0]);
    }

    /**
     * @return array<int, Product>
     */
    public function for_warehouse(int $warehouse_id): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE warehouse_id = %d AND archived = 0 ORDER BY name ASC',
                $this->table(),
                $warehouse_id
            ),
            ARRAY_A
        );

        return array_map([Product::class, 'from_row'], $rows ?: []);
    }

    public function find(int $id): ?Product
    {
        $row = $this->raw($id);

        return $row !== null ? Product::from_row($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function raw(int $id): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $this->table(), $id),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        global $wpdb;

        $row         = $this->prepare_data($data);
        $row['slug'] = $this->resolve_slug((string) ($data['slug'] ?? ''), (string) $data['name'], 0);
        $row['content_updated_at'] = gmdate('Y-m-d H:i:s');

        $wpdb->insert($this->table(), $row);

        $id = (int) $wpdb->insert_id;

        if ($id > 0) {
            $categories = $this->wanted_categories($row['category_id'], $data['category_ids'] ?? []);
            $this->write_categories($id, $categories);

            sort($categories);
            AuditLog::record('product.create', 'product', $id, (string) $row['name'], ChangeSet::created($row + ['category_ids' => $categories], self::AUDIT_IGNORE));

            if ((bool) $row['active']) {
                CatalogEvents::changed($id);
            }
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $columns = $this->prepare_data($data);

        // Mitgliedsrabatt nur, wenn mitgegeben (das Formular zeigt ihn nur mit Pro) - sonst bleibt der gespeicherte.
        foreach (['member_discount_type', 'member_discount_value'] as $key) {
            if (!array_key_exists($key, $data)) {
                unset($columns[$key]);
            }
        }

        if (trim((string) ($data['slug'] ?? '')) !== '') {
            $columns['slug'] = $this->resolve_slug((string) $data['slug'], (string) $data['name'], $id);
        }

        $this->commit($id, $columns, array_key_exists('category_ids', $data) ? $this->wanted_categories($columns['category_id'], $data['category_ids']) : null);
    }

    /**
     * IDs aller zugeordneten Kategorien (Hauptkategorie eingeschlossen).
     *
     * @return array<int, int>
     */
    public function category_ids(int $product_id): array
    {
        global $wpdb;

        $rows = $wpdb->get_col($wpdb->prepare('SELECT category_id FROM %i WHERE product_id = %d ORDER BY category_id ASC', $this->categories_table(), $product_id));

        return array_map('intval', $rows ?: []);
    }

    /**
     * Zuordnungen mehrerer Artikel in einer Abfrage.
     *
     * @param array<int, int> $product_ids
     * @return array<int, array<int, int>> product_id => category_ids
     */
    public function category_map(array $product_ids): array
    {
        global $wpdb;

        $ids = array_values(array_filter(array_map('intval', $product_ids)));
        $map = array_fill_keys($ids, []);

        if ($ids === []) {
            return $map;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders enthaelt nur %d-Platzhalter, je ID einer
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT product_id, category_id FROM %i WHERE product_id IN (' . $placeholders . ') ORDER BY category_id ASC',
                $this->categories_table(),
                ...$ids
            ),
            ARRAY_A
        ) ?: [];
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        foreach ($rows as $row) {
            $map[(int) $row['product_id']][] = (int) $row['category_id'];
        }

        return $map;
    }

    public function slug_exists(string $slug, int $except_id = 0): bool
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE slug = %s AND id != %d LIMIT 1',
            $this->table(),
            $slug,
            $except_id
        )) !== null;
    }

    /**
     * Gab es diese ID je? Zeile vorhanden (auch inaktiv/archiviert) oder
     * geloeschter Artikel mit Eintrag in product_tombstones.
     */
    public function existed(int $id): bool
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare(
            'SELECT 1 FROM %i WHERE id = %d UNION SELECT 1 FROM %i WHERE product_id = %d LIMIT 1',
            $this->table(),
            $id,
            $this->tombstones_table(),
            $id
        )) !== null;
    }

    private function images_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kontor_shop_product_images';
    }

    /**
     * Zusatzbilder (Galerie) in Anzeigereihenfolge - ohne das Hauptbild.
     *
     * @return array<int, int> Attachment-IDs
     */
    public function gallery_ids(int $product_id): array
    {
        global $wpdb;

        $rows = $wpdb->get_col($wpdb->prepare(
            'SELECT attachment_id FROM %i WHERE product_id = %d ORDER BY sort_order ASC, attachment_id ASC',
            $this->images_table(),
            $product_id
        ));

        return array_map('intval', $rows ?: []);
    }

    /**
     * Galerie ersetzen (Reihenfolge = Reihenfolge der IDs). Das Hauptbild
     * steht nie zusaetzlich in der Galerie.
     *
     * @param array<int, int> $attachment_ids
     * @return array{before: array<int, int>, after: array<int, int>}
     */
    public function set_gallery(int $product_id, array $attachment_ids): array
    {
        global $wpdb;

        $before = $this->gallery_ids($product_id);
        $main   = (int) ($this->find($product_id)?->image_id ?? 0);
        $after  = array_values(array_unique(array_filter(
            array_map('intval', $attachment_ids),
            static fn (int $id): bool => $id > 0 && $id !== $main
        )));

        if ($before === $after) {
            return ['before' => $before, 'after' => $after];
        }

        $wpdb->delete($this->images_table(), ['product_id' => $product_id]);

        foreach ($after as $position => $attachment_id) {
            $wpdb->insert($this->images_table(), ['product_id' => $product_id, 'attachment_id' => $attachment_id, 'sort_order' => $position]);
        }

        return ['before' => $before, 'after' => $after];
    }

    private function categories_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kontor_shop_product_categories';
    }

    private function tombstones_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kontor_shop_product_tombstones';
    }

    /**
     * Stabiler Slug: einmal vergeben, aendert sich nie von selbst (auch nicht
     * beim Umbenennen). Explizit angegebene Slugs werden bereinigt und
     * eindeutig gemacht.
     */
    private function resolve_slug(string $wanted, string $name, int $except_id): string
    {
        $base = Slug::make($wanted !== '' ? $wanted : $name);

        return Slug::unique($base, fn (string $candidate): bool => $this->slug_exists($candidate, $except_id));
    }

    /**
     * @param mixed $extra
     * @return array<int, int>
     */
    private function wanted_categories(mixed $primary, mixed $extra): array
    {
        $ids = is_array($extra) ? array_map('intval', $extra) : [];

        if ((int) $primary > 0) {
            $ids[] = (int) $primary;
        }

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /**
     * @param array<int, int> $category_ids
     */
    private function write_categories(int $product_id, array $category_ids): void
    {
        global $wpdb;

        $wpdb->delete($this->categories_table(), ['product_id' => $product_id]);

        foreach (array_unique($category_ids) as $category_id) {
            $wpdb->insert($this->categories_table(), ['product_id' => $product_id, 'category_id' => (int) $category_id]);
        }
    }

    /**
     * Zentrale Schreibstelle fuer Aenderungen an bestehenden Artikeln: schreibt
     * die Spalten, pflegt die Kategorie-Zuordnung und hebt content_updated_at
     * (und meldet CatalogEvents) nur dann, wenn sich etwas aendert, das
     * Suchmaschinen sehen - Domain\Seo\ContentChange. Aenderungen an
     * unsichtbaren (inaktiven/archivierten) Artikeln zaehlen nicht.
     *
     * @param array<string, mixed> $columns Spalte => Wert
     * @param array<int, int>|null $category_ids null = Zuordnung nur bei geaenderter Hauptkategorie anpassen
     */
    private function commit(int $id, array $columns, ?array $category_ids = null): void
    {
        global $wpdb;

        $before = $this->raw($id);

        if ($before === null) {
            return;
        }

        $old      = Product::from_row($before);
        $old_cats = $this->category_ids($id);
        $plan     = ChangePlan::for($old, $old_cats, $columns, $category_ids);
        $changed  = $plan->public_change;
        $new_cats = $plan->categories;

        if ($changed) {
            $columns['content_updated_at'] = gmdate('Y-m-d H:i:s');
        }

        if ($columns !== []) {
            $wpdb->update($this->table(), $columns, ['id' => $id]);
        }

        if ($category_ids !== null || array_key_exists('category_id', $columns)) {
            $this->write_categories($id, $new_cats);
        }

        if ($changed) {
            CatalogEvents::changed($id);
        }

        $after = $this->raw($id) ?? $before;

        AuditLog::record_diff(
            'product.update',
            'product',
            $id,
            (string) $after['name'],
            $before + ['category_ids' => $old_cats],
            $after + ['category_ids' => $this->category_ids($id)],
            self::AUDIT_IGNORE
        );
    }

    /**
     * Setzt content_updated_at auf jetzt und meldet die Aenderung - fuer den
     * Wechsel der Verfuegbarkeit (0 <-> > 0) durch eine Bestandsbuchung.
     */
    public function touch_content(int $id): void
    {
        global $wpdb;

        $wpdb->update($this->table(), ['content_updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);

        CatalogEvents::changed($id);
    }

    private function variants_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kontor_shop_product_variants';
    }

    private function movements_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kontor_shop_stock_movements';
    }

    /**
     * Seitenweise Artikelliste mit Suche, Filtern und Sortierung; der aktuelle
     * Bestand kommt per Gruppen-Subselect in derselben Abfrage mit (statt einer
     * Abfrage je Zeile).
     *
     * @param array{view?: string, q?: string, warehouse_id?: int, warehouse_ids?: array<int, int>|null, category_id?: int, visibility?: string, stock?: string, orderby?: string, order?: string, page?: int, per_page?: int} $args
     * @return array{items: array<int, Product>, balances: array<int, int>, total: int}
     */
    public function query(array $args): array
    {
        global $wpdb;

        $where  = [];
        $params = [];

        $where[] = 'p.archived = ' . (($args['view'] ?? 'active') === 'archive' ? 1 : 0);

        $q = trim((string) ($args['q'] ?? ''));

        if ($q !== '') {
            $like     = '%' . $wpdb->esc_like($q) . '%';
            $where[]  = '(p.name LIKE %s OR p.sku LIKE %s OR p.gtin LIKE %s OR p.description LIKE %s OR p.id IN (SELECT v.product_id FROM %i v WHERE v.sku LIKE %s OR v.gtin LIKE %s))';
            array_push($params, $like, $like, $like, $like, $this->variants_table(), $like, $like);
        }

        if (!empty($args['warehouse_id'])) {
            $where[]  = 'p.warehouse_id = %d';
            $params[] = (int) $args['warehouse_id'];
        }

        if (isset($args['warehouse_ids']) && is_array($args['warehouse_ids'])) {
            $ids = array_values(array_filter(array_map('intval', $args['warehouse_ids'])));

            $where[] = $ids === [] ? '1 = 0' : 'p.warehouse_id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')';
            array_push($params, ...$ids);
        }

        if (!empty($args['category_id'])) {
            if ((int) $args['category_id'] === -1) {
                $where[] = 'p.category_id IS NULL';
            } else {
                $where[]  = 'p.category_id = %d';
                $params[] = (int) $args['category_id'];
            }
        }

        $visibility = (string) ($args['visibility'] ?? '');

        if ($visibility === 'visible') {
            $where[] = 'p.active = 1';
        } elseif ($visibility === 'hidden') {
            $where[] = 'p.active = 0';
        }

        $stock = (string) ($args['stock'] ?? '');

        if ($stock === 'out') {
            $where[] = 'COALESCE(s.bal, 0) <= 0';
        } elseif ($stock === 'low') {
            $where[] = '(COALESCE(s.bal, 0) > 0 AND p.min_stock IS NOT NULL AND p.min_stock > 0 AND COALESCE(s.bal, 0) <= p.min_stock)';
        } elseif ($stock === 'warn') {
            $where[] = '(COALESCE(s.bal, 0) <= 0 OR (p.min_stock IS NOT NULL AND p.min_stock > 0 AND COALESCE(s.bal, 0) <= p.min_stock))';
        }

        $orderby = match ((string) ($args['orderby'] ?? 'name')) {
            'price' => 'p.price',
            'sku'   => 'p.sku',
            'stock' => 'COALESCE(s.bal, 0)',
            'date'  => 'p.created_at',
            default => 'p.name',
        };
        $order = strtoupper((string) ($args['order'] ?? 'asc')) === 'DESC' ? 'DESC' : 'ASC';

        // Tabellen stehen vor dem WHERE, ihre Platzhalter also vor den Filterwerten.
        $from   = ' FROM %i p LEFT JOIN (SELECT product_id, SUM(quantity_delta) AS bal FROM %i GROUP BY product_id) s ON s.product_id = p.id WHERE ' . implode(' AND ', $where);
        $params = array_merge([$this->table(), $this->movements_table()], $params);

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- WHERE aus festen Fragmenten mit Platzhaltern, ORDER BY aus Whitelist
        $count_sql = 'SELECT COUNT(*)' . $from;
        $total     = (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$params));

        $per_page = max(1, min(500, (int) ($args['per_page'] ?? 25)));
        $page     = max(1, (int) ($args['page'] ?? 1));

        $sql = 'SELECT p.*, COALESCE(s.bal, 0) AS stock_balance' . $from
            . ' ORDER BY ' . $orderby . ' ' . $order . ', p.id ASC LIMIT %d OFFSET %d';

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...array_merge($params, [$per_page, ($page - 1) * $per_page])), ARRAY_A) ?: [];
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $items    = [];
        $balances = [];

        foreach ($rows as $row) {
            $product                    = Product::from_row($row);
            $items[]                    = $product;
            $balances[(int) $product->id] = (int) $row['stock_balance'];
        }

        return ['items' => $items, 'balances' => $balances, 'total' => $total];
    }

    /**
     * Zaehler fuer die Ansichts-Links der Artikelliste.
     *
     * @param array<int, int>|null $warehouse_ids null = alle Lager
     * @return array{active: int, archived: int, visible: int, hidden: int, warn: int}
     */
    public function counts(?array $warehouse_ids = null): array
    {
        global $wpdb;

        $scope = '';
        $ids   = [];

        if ($warehouse_ids !== null) {
            $ids   = array_values(array_filter(array_map('intval', $warehouse_ids)));
            $scope = $ids === [] ? ' AND 1 = 0' : ' AND p.warehouse_id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')';
        }

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $scope enthaelt nur %d-Platzhalter, je ID einer
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT
                SUM(p.archived = 0) AS active,
                SUM(p.archived = 1) AS archived,
                SUM(p.archived = 0 AND p.active = 1) AS visible,
                SUM(p.archived = 0 AND p.active = 0) AS hidden,
                SUM(p.archived = 0 AND (COALESCE(s.bal, 0) <= 0 OR (p.min_stock IS NOT NULL AND p.min_stock > 0 AND COALESCE(s.bal, 0) <= p.min_stock))) AS warn
             FROM %i p LEFT JOIN (SELECT product_id, SUM(quantity_delta) AS bal FROM %i GROUP BY product_id) s ON s.product_id = p.id WHERE 1 = 1' . $scope,
            $this->table(),
            $this->movements_table(),
            ...$ids
        ), ARRAY_A) ?: [];
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return [
            'active'   => (int) ($row['active'] ?? 0),
            'archived' => (int) ($row['archived'] ?? 0),
            'visible'  => (int) ($row['visible'] ?? 0),
            'hidden'   => (int) ($row['hidden'] ?? 0),
            'warn'     => (int) ($row['warn'] ?? 0),
        ];
    }

    /**
     * Anzahl nicht archivierter Artikel je Lager bzw. Kategorie
     * (fuer die Zaehlspalte der Stammdaten-Listen).
     *
     * @return array<int, int>
     */
    public function count_by(string $column): array
    {
        global $wpdb;

        $column = $column === 'category_id' ? 'category_id' : 'warehouse_id';
        $rows   = $wpdb->get_results(
            $wpdb->prepare('SELECT %i AS ref, COUNT(*) AS n FROM %i WHERE archived = 0 AND %i IS NOT NULL GROUP BY %i', $column, $this->table(), $column, $column),
            ARRAY_A
        ) ?: [];

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row['ref']] = (int) $row['n'];
        }

        return $map;
    }

    /**
     * Anzahl aller (auch archivierter) Artikel, die noch ein Lager bzw. eine
     * Kategorie verwenden - Schutz vor dem Loeschen.
     */
    public function count_using(string $column, int $id): int
    {
        global $wpdb;

        $column = $column === 'category_id' ? 'category_id' : 'warehouse_id';

        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE %i = %d', $this->table(), $column, $id));
    }

    public function set_active(int $id, bool $active): void
    {
        $this->commit($id, ['active' => $active ? 1 : 0]);
    }

    /**
     * Sammelaktionen: dieselbe Aenderung fuer alle IDs (je Artikel ueber commit(),
     * damit content_updated_at nur bei sichtbaren Aenderungen steigt).
     *
     * @param array<int, int>      $ids
     * @param array<string, mixed> $fields Spalte => Wert (nur Whitelist)
     */
    public function update_many(array $ids, array $fields): int
    {
        global $wpdb;

        $ids = array_values(array_filter(array_map('intval', $ids)));

        if ($ids === [] || $fields === []) {
            return 0;
        }

        $allowed = ['active', 'archived', 'category_id', 'warehouse_id'];
        $columns = [];

        foreach ($fields as $column => $value) {
            if (in_array($column, $allowed, true)) {
                $columns[$column] = $value === null ? null : (int) $value;
            }
        }

        if ($columns === []) {
            return 0;
        }

        foreach ($ids as $id) {
            $this->commit($id, $columns);
        }

        return count($ids);
    }

    /**
     * Preise mehrerer Artikel um einen Prozentsatz aendern.
     *
     * @param array<int, int> $ids
     */
    public function adjust_prices(array $ids, float $percent): int
    {
        $changed = 0;

        foreach ($ids as $id) {
            $product = $this->find((int) $id);

            if ($product === null) {
                continue;
            }

            $this->commit((int) $id, ['price' => ProductTools::adjust_price($product->price, $percent)]);
            (new VariantRepository())->adjust_prices((int) $id, $percent);
            $changed++;
        }

        return $changed;
    }

    /**
     * Schnellbearbeitung: nur die im Inline-Formular gezeigten Felder.
     *
     * @param array<string, mixed> $data name, sku, price, category_id, min_stock, active
     */
    public function quick_update(int $id, array $data): void
    {
        $this->commit($id, [
            'name'        => (string) $data['name'],
            'sku'         => (string) ($data['sku'] ?? ''),
            'price'       => (float) $data['price'],
            'category_id' => !empty($data['category_id']) ? (int) $data['category_id'] : null,
            'min_stock'   => isset($data['min_stock']) && $data['min_stock'] !== null && $data['min_stock'] !== '' ? max(0, (int) $data['min_stock']) : null,
            'active'      => empty($data['active']) ? 0 : 1,
        ]);
    }

    /** Nur den Mindestbestand setzen (Bestandsliste; null = keiner). */
    public function set_min_stock(int $id, ?int $min_stock): void
    {
        $this->commit($id, ['min_stock' => $min_stock !== null ? max(0, $min_stock) : null]);
    }

    /**
     * Kopie eines Artikels: gleiche Stammdaten und Bild, aber unsichtbar
     * (bis er geprueft ist), ohne SKU und mit Bestand 0.
     */
    public function duplicate(int $id): ?int
    {
        $source = $this->find($id);

        if ($source === null) {
            return null;
        }

        $copy = $this->create([
            'warehouse_id'          => $source->warehouse_id,
            'category_id'           => $source->category_id,
            'image_id'              => $source->image_id,
            'tax_rate'              => $source->tax_rate,
            'sku'                   => '',
            'name'                  => ProductTools::copy_name($source->name),
            'description'           => $source->description,
            'price'                 => $source->price,
            'member_discount_type'  => $source->member_discount_type,
            'member_discount_value' => $source->member_discount_value,
            'active'                => false,
            'weight'                => $source->weight,
            'min_stock'             => $source->min_stock,
            'brand'                 => $source->brand,
            'image_alt'             => $source->image_alt,
            'variant_options'       => $source->variant_options,
            'unit_amount'           => $source->unit_amount,
            'unit_type'             => $source->unit_type,
            'discontinued'          => $source->discontinued,
            'category_ids'          => $this->category_ids($id),
            // Slug und GTIN werden bewusst nicht kopiert: beide sind je Artikel einmalig.
        ]);

        if ($copy > 0) {
            $this->set_gallery($copy, $this->gallery_ids($id));
            (new VariantRepository())->copy($id, $copy);
        }

        return $copy;
    }

    public function delete(int $id): void
    {
        global $wpdb;

        $row = $this->raw($id);

        if ($row === null) {
            return;
        }

        $product = Product::from_row($row);

        // Grabstein: die Adresse antwortet danach mit 410 statt 404.
        $wpdb->replace($this->tombstones_table(), [
            'product_id' => $id,
            'slug'       => $product->slug,
            'removed_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $wpdb->delete($this->categories_table(), ['product_id' => $id]);
        $wpdb->delete($this->images_table(), ['product_id' => $id]);
        (new VariantRepository())->delete_for_product($id);
        $wpdb->delete($this->table(), ['id' => $id]);

        if ($product->is_sellable()) {
            CatalogEvents::changed($id);
        }

        AuditLog::record('product.delete', 'product', $id, $product->name, ChangeSet::deleted($row, self::AUDIT_IGNORE));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function prepare_data(array $data): array
    {
        return [
            'warehouse_id'          => (int) $data['warehouse_id'],
            'category_id'           => !empty($data['category_id']) ? (int) $data['category_id'] : null,
            'image_id'              => !empty($data['image_id']) ? (int) $data['image_id'] : null,
            'tax_rate'              => isset($data['tax_rate']) && $data['tax_rate'] !== null && $data['tax_rate'] !== '' ? (float) $data['tax_rate'] : null,
            'image_alt'             => (string) ($data['image_alt'] ?? ''),
            'sku'                   => (string) ($data['sku'] ?? ''),
            'gtin'                  => (string) ($data['gtin'] ?? ''),
            'brand'                 => (string) ($data['brand'] ?? ''),
            'name'                  => (string) $data['name'],
            'description'           => (string) ($data['description'] ?? ''),
            'price'                 => (float) $data['price'],
            'member_discount_type'  => (string) ($data['member_discount_type'] ?? Product::DISCOUNT_NONE),
            'member_discount_value' => (float) ($data['member_discount_value'] ?? 0),
            'active'                => empty($data['active']) ? 0 : 1,
            'discontinued'          => empty($data['discontinued']) ? 0 : 1,
            'weight'                => isset($data['weight']) && $data['weight'] !== null && $data['weight'] !== ''
                ? (float) $data['weight']
                : null,
            'min_stock'             => isset($data['min_stock']) && $data['min_stock'] !== null && $data['min_stock'] !== ''
                ? max(0, (int) $data['min_stock'])
                : null,
            'variant_options'       => VariantMatrix::encode(is_array($data['variant_options'] ?? null) ? $data['variant_options'] : []),
            'unit_amount'           => isset($data['unit_amount']) && $data['unit_amount'] !== null && $data['unit_amount'] !== '' && (float) $data['unit_amount'] > 0
                ? (float) $data['unit_amount']
                : null,
            'unit_type'             => UnitPrice::is_unit((string) ($data['unit_type'] ?? '')) ? (string) $data['unit_type'] : '',
        ];
    }
}
