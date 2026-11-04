<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- eigene Tabellen des Plugins, kein WP-Objektcache-Bezug

use Kontor\Shop\Domain\Order\OrderStatus;
use Kontor\Shop\Domain\Seo\ContentChange;
use Kontor\Shop\Domain\Stock\MovementType;
use Kontor\Shop\Domain\Stock\StockLedger;
use Kontor\Shop\Infrastructure\Audit\AuditLog;

/**
 * wpdb-Zugriff auf wp_kontor_shop_stock_movements (Abschnitt 5.3). Der
 * aktuelle Bestand wird nicht separat gespeichert, sondern ueber
 * die Summe der Bewegungen berechnet (Ledger-Logik: Domain\Stock\StockLedger).
 * Manuelle Buchungen (ohne Bestellung) erscheinen zusaetzlich im
 * Aenderungsprotokoll beim Artikel; Verkaeufe/Stornos stehen schon an der
 * Bestellung.
 *
 * Varianten: jede Bewegung traegt variant_id (0 = Artikel ohne Varianten).
 * Ohne Angabe einer Variante liefern die Abfragen die Summe ueber alle
 * Varianten des Artikels (= Gesamtbestand).
 */
final class StockRepository
{
    /** Bestand ist wieder > 0; Argumente: Artikel-ID, Varianten-ID (0 = ohne Variante) */
    public const HOOK_RESTOCKED = 'kontor_shop/stock/restocked';

    private function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kontor_shop_stock_movements';
    }

    public function record(
        int $product_id,
        string $type,
        int $quantity_delta,
        ?int $order_id,
        ?string $note,
        ?int $user_id,
        int $variant_id = 0
    ): int {
        global $wpdb;

        $before         = $this->balance($product_id);
        $variant_before = $variant_id > 0 ? $this->balance($product_id, $variant_id) : $before;

        if ($wpdb->insert($this->table(), [
            'product_id'     => $product_id,
            'variant_id'     => max(0, $variant_id),
            'type'           => $type,
            'quantity_delta' => $quantity_delta,
            'order_id'       => $order_id,
            'note'           => $note,
            'user_id'        => $user_id,
        ]) === false) {
            throw new \RuntimeException('Bestandsbewegung konnte nicht gespeichert werden: ' . $wpdb->last_error); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- wird nur geloggt (Logger::exception), nicht als HTML ausgegeben
        }

        $id = (int) $wpdb->insert_id;

        if ($order_id === null) {
            $changes = [
                'type'     => [null, $type],
                'quantity' => [null, (string) $quantity_delta],
                'balance'  => [(string) $variant_before, (string) ($variant_before + $quantity_delta)],
                'note'     => [null, $note],
            ];

            if ($variant_id > 0) {
                $changes = ['variant' => [null, (new VariantRepository())->find($variant_id)?->label() ?? '#' . $variant_id]] + $changes;
            }

            AuditLog::record('stock.movement', 'product', $product_id, (new ProductRepository())->find($product_id)?->name, $changes);
        }

        // Nur der Wechsel ausverkauft <-> verfuegbar ist fuer Suchmaschinen eine
        // Aenderung (lastmod, Cache) - nicht jedes verkaufte Stueck. Bei Varianten
        // zaehlt die einzelne Variante (sie hat im JSON-LD eine eigene Verfuegbarkeit).
        if (ContentChange::availability_flips($variant_before, $quantity_delta)) {
            $products = new ProductRepository();

            if ($products->find($product_id)?->is_sellable()) {
                $products->touch_content($product_id);
            }
        }

        // Fuer Erweiterungen (z. B. Verfuegbarkeitsanfragen): nur der Wechsel von
        // "nichts da" auf "wieder da" - je Variante, bei Artikeln ohne Varianten 0.
        if (StockLedger::is_restock($variant_before, $quantity_delta)) {
            do_action(self::HOOK_RESTOCKED, $product_id, max(0, $variant_id));
        }

        return $id;
    }

    /**
     * @param int|null $variant_id null = alle Varianten zusammen, 0 = Bestand ohne Variante
     */
    public function balance(int $product_id, ?int $variant_id = null): int
    {
        global $wpdb;

        if ($variant_id !== null) {
            return (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COALESCE(SUM(quantity_delta), 0) FROM %i WHERE product_id = %d AND variant_id = %d',
                $this->table(),
                $product_id,
                $variant_id
            ));
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COALESCE(SUM(quantity_delta), 0) FROM %i WHERE product_id = %d', $this->table(), $product_id)
        );
    }

    /**
     * Bestand je Variante mehrerer Artikel in einer Abfrage.
     *
     * @param array<int, int> $product_ids
     * @return array<int, array<int, int>> product_id => [variant_id => Bestand] (nur Varianten mit Bewegungen)
     */
    public function variant_balances(array $product_ids): array
    {
        global $wpdb;

        $ids = array_values(array_filter(array_map('intval', $product_ids)));

        if ($ids === []) {
            return [];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT product_id, variant_id, SUM(quantity_delta) AS bal FROM %i'
            . ' WHERE product_id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ') GROUP BY product_id, variant_id',
            array_merge([$this->table()], $ids)
        ), ARRAY_A) ?: [];

        $map = array_fill_keys($ids, []);

        foreach ($rows as $row) {
            $map[(int) $row['product_id']][(int) $row['variant_id']] = (int) $row['bal'];
        }

        return $map;
    }

    /** Gibt es Bewegungen dieser Variante? (Dann darf sie nicht geloescht werden.) */
    public function variant_has_movements(int $variant_id): bool
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare('SELECT 1 FROM %i WHERE variant_id = %d LIMIT 1', $this->table(), $variant_id)) !== null;
    }

    /**
     * Bestaende mehrerer Artikel in einer Abfrage (statt einer je Zeile).
     *
     * @param array<int, int> $product_ids
     * @return array<int, int> product_id => Bestand (0, wenn es noch keine Bewegung gibt)
     */
    public function balances(array $product_ids): array
    {
        global $wpdb;

        $ids = array_values(array_filter(array_map('intval', $product_ids)));

        if ($ids === []) {
            return [];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT product_id, SUM(quantity_delta) AS bal FROM %i'
            . ' WHERE product_id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ') GROUP BY product_id',
            array_merge([$this->table()], $ids)
        ), ARRAY_A) ?: [];

        $map = array_fill_keys($ids, 0);

        foreach ($rows as $row) {
            $map[(int) $row['product_id']] = (int) $row['bal'];
        }

        return $map;
    }

    /**
     * @param array<int, int> $product_ids
     * @return array<int, int> nur IDs > 0
     */
    private static function id_list(array $product_ids): array
    {
        return array_values(array_filter(array_map('intval', $product_ids)));
    }

    /**
     * Verkaufte Stueck je Artikel und Variante in den letzten $days Tagen
     * (Online + Offline, Storno-Rueckbuchungen abgezogen).
     *
     * @param array<int, int> $product_ids
     * @return array<int, array<int, int>> product_id => [variant_id => Stueck]
     */
    public function sold_since(array $product_ids, int $days): array
    {
        global $wpdb;

        $ids = self::id_list($product_ids);

        if ($ids === []) {
            return [];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT product_id, variant_id, -SUM(quantity_delta) AS sold FROM %i'
            . ' WHERE product_id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ') AND type IN (%s, %s, %s) AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)'
            . ' GROUP BY product_id, variant_id',
            array_merge([$this->table()], $ids, [MovementType::ONLINE_SALE, MovementType::OFFLINE_SALE, MovementType::CANCEL_RESTOCK, $days])
        ), ARRAY_A) ?: [];

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row['product_id']][(int) $row['variant_id']] = max(0, (int) $row['sold']);
        }

        return $map;
    }

    /**
     * Verkaeufe je Woche (aelteste zuerst) fuer den Mini-Verlauf der Liste.
     *
     * @param array<int, int> $product_ids
     * @return array<int, array<int, int>> product_id => [$weeks Werte]
     */
    public function weekly_sales(array $product_ids, int $weeks = 8): array
    {
        global $wpdb;

        $ids = self::id_list($product_ids);

        if ($ids === []) {
            return [];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT product_id, FLOOR(DATEDIFF(NOW(), created_at) / 7) AS wk, -SUM(quantity_delta) AS sold FROM %i'
            . ' WHERE product_id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ') AND type IN (%s, %s, %s) AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)'
            . ' GROUP BY product_id, wk',
            array_merge([$this->table()], $ids, [MovementType::ONLINE_SALE, MovementType::OFFLINE_SALE, MovementType::CANCEL_RESTOCK, $weeks * 7])
        ), ARRAY_A) ?: [];

        $map = [];

        foreach ($rows as $row) {
            $week = (int) $row['wk'];

            if ($week >= 0 && $week < $weeks) {
                $map[(int) $row['product_id']] ??= array_fill(0, $weeks, 0);
                $map[(int) $row['product_id']][$weeks - 1 - $week] = max(0, (int) $row['sold']);
            }
        }

        return $map;
    }

    /**
     * Werte fuer den gemeinsamen Teil "noch nicht versendete Positionen"
     * (FROM %i oi JOIN %i o ... WHERE o.status IN (%s, %s) ...).
     *
     * @return array<int, string>
     */
    private static function orders_filter_args(): array
    {
        global $wpdb;

        return [$wpdb->prefix . 'kontor_shop_order_items', $wpdb->prefix . 'kontor_shop_orders', OrderStatus::OPEN, OrderStatus::PAID];
    }

    /**
     * Verkaufte, aber noch nicht versendete/abgeholte Ware: der Online-Verkauf
     * bucht schon bei der Bestellung ab, das Stueck liegt aber noch im Regal.
     *
     * @param array<int, int> $product_ids
     * @return array<int, array<int, int>> product_id => [variant_id => Stueck]
     */
    public function bound_in_orders(array $product_ids): array
    {
        global $wpdb;

        $ids = self::id_list($product_ids);

        if ($ids === []) {
            return [];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT oi.product_id, oi.variant_id, SUM(oi.quantity) AS qty FROM %i oi JOIN %i o ON o.id = oi.order_id'
            . ' WHERE o.status IN (%s, %s) AND oi.cancelled_at IS NULL AND oi.shipment_id IS NULL'
            . ' AND oi.product_id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ') GROUP BY oi.product_id, oi.variant_id',
            array_merge(self::orders_filter_args(), $ids)
        ), ARRAY_A) ?: [];

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row['product_id']][(int) $row['variant_id']] = (int) $row['qty'];
        }

        return $map;
    }

    /**
     * Noch zu versendende Stueck und Bestellungen (fuer die Kennzahl oben).
     *
     * @param array<int, int>|null $warehouse_ids null = alle Lager
     * @return array{units: int, orders: int}
     */
    public function pending_shipments(?array $warehouse_ids): array
    {
        global $wpdb;

        if ($warehouse_ids === null) {
            $row = $wpdb->get_row($wpdb->prepare(
                'SELECT COALESCE(SUM(oi.quantity), 0) AS units, COUNT(DISTINCT oi.order_id) AS orders FROM %i oi JOIN %i o ON o.id = oi.order_id'
                . ' WHERE o.status IN (%s, %s) AND oi.cancelled_at IS NULL AND oi.shipment_id IS NULL',
                $wpdb->prefix . 'kontor_shop_order_items',
                $wpdb->prefix . 'kontor_shop_orders',
                OrderStatus::OPEN,
                OrderStatus::PAID
            ), ARRAY_A) ?: [];
        } else {
            $ids = self::id_list($warehouse_ids);

            // Keine gueltige Lager-ID: nichts im Scope (frueher "AND 1 = 0").
            if ($ids === []) {
                return ['units' => 0, 'orders' => 0];
            }

            $row = $wpdb->get_row($wpdb->prepare(
                'SELECT COALESCE(SUM(oi.quantity), 0) AS units, COUNT(DISTINCT oi.order_id) AS orders FROM %i oi JOIN %i o ON o.id = oi.order_id'
                . ' WHERE o.status IN (%s, %s) AND oi.cancelled_at IS NULL AND oi.shipment_id IS NULL'
                . ' AND oi.warehouse_id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')',
                array_merge(self::orders_filter_args(), $ids)
            ), ARRAY_A) ?: [];
        }

        return ['units' => (int) ($row['units'] ?? 0), 'orders' => (int) ($row['orders'] ?? 0)];
    }

    /**
     * Letzte Bewegung je Artikel.
     *
     * @param array<int, int> $product_ids
     * @return array<int, array{type: string, created_at: string}>
     */
    public function last_movements(array $product_ids): array
    {
        global $wpdb;

        $ids = self::id_list($product_ids);

        if ($ids === []) {
            return [];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT m.product_id, m.type, m.created_at FROM %i m JOIN (SELECT MAX(id) AS mid FROM %i'
            . ' WHERE product_id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ') GROUP BY product_id) x ON x.mid = m.id',
            array_merge([$this->table(), $this->table()], $ids)
        ), ARRAY_A) ?: [];

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row['product_id']] = ['type' => (string) $row['type'], 'created_at' => (string) $row['created_at']];
        }

        return $map;
    }

    /**
     * Die letzten Bewegungen ueber alle Artikel (im Lager-Scope), neueste zuerst.
     *
     * @param array<int, int>|null $warehouse_ids null = alle Lager
     * @return array<int, array<string, mixed>> Bewegung + product_name, order_number
     */
    public function recent(?array $warehouse_ids, int $limit = 12): array
    {
        global $wpdb;

        $tables = [$this->table(), $wpdb->prefix . 'kontor_shop_products', $wpdb->prefix . 'kontor_shop_orders'];

        if ($warehouse_ids === null) {
            return $wpdb->get_results($wpdb->prepare(
                'SELECT m.*, p.name AS product_name, o.order_number FROM %i m JOIN %i p ON p.id = m.product_id'
                . ' LEFT JOIN %i o ON o.id = m.order_id'
                . ' ORDER BY m.created_at DESC, m.id DESC LIMIT %d',
                $this->table(),
                $wpdb->prefix . 'kontor_shop_products',
                $wpdb->prefix . 'kontor_shop_orders',
                $limit
            ), ARRAY_A) ?: [];
        }

        $ids = self::id_list($warehouse_ids);

        // Keine gueltige Lager-ID: nichts im Scope (frueher "AND 1 = 0").
        if ($ids === []) {
            return [];
        }

        return $wpdb->get_results($wpdb->prepare(
            'SELECT m.*, p.name AS product_name, o.order_number FROM %i m JOIN %i p ON p.id = m.product_id'
            . ' LEFT JOIN %i o ON o.id = m.order_id'
            . ' WHERE p.warehouse_id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ') ORDER BY m.created_at DESC, m.id DESC LIMIT %d',
            array_merge($tables, $ids, [$limit])
        ), ARRAY_A) ?: [];
    }

    public function count_for_product(int $product_id): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE product_id = %d', $this->table(), $product_id));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recent_for_product(int $product_id, int $limit = 50, int $offset = 0): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE product_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
                $this->table(),
                $product_id,
                $limit,
                $offset
            ),
            ARRAY_A
        );

        return $rows ?: [];
    }
}
