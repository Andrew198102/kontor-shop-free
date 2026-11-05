<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Checkout;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- eigene Tabellen des Plugins, kein WP-Objektcache-Bezug

use Kontor\Shop\Domain\Stock\Availability;

/**
 * Bestandsreservierung mit TTL (Abschnitt 6.1, Schritt 2) gegen
 * Ueberverkauf bei gleichzeitigen Checkouts (Abschnitt 12): reserve() sperrt
 * die Produktzeile per SELECT ... FOR UPDATE innerhalb einer Transaktion,
 * bevor Bestand und bereits bestehende fremde Reservierungen geprueft
 * werden - erfordert eine InnoDB-Tabelle (MySQL-Standard-Engine).
 *
 * Reserviert wird je Artikel UND Variante (variant_id, 0 = ohne Varianten);
 * die Sperre liegt auf der Artikelzeile, gilt also fuer alle seine Varianten.
 */
final class ReservationStore
{
    private function reservations_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kontor_shop_stock_reservations';
    }

    private function movements_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kontor_shop_stock_movements';
    }

    private function products_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kontor_shop_products';
    }

    /**
     * Versucht, $quantity Stueck von $product_id fuer $session_key zu
     * reservieren. Ersetzt eine ggf. bereits bestehende Reservierung
     * derselben Session fuer denselben Artikel (z. B. bei erneutem
     * Checkout-Aufruf). Gibt false zurueck, wenn nicht genug frei ist.
     */
    public function reserve(int $product_id, int $quantity, string $session_key, int $ttl_seconds, bool $keep_expiry = false, int $variant_id = 0): bool
    {
        global $wpdb;

        $wpdb->query('START TRANSACTION');

        $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE id = %d FOR UPDATE',
            $this->products_table(),
            $product_id
        ));

        $sold = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COALESCE(SUM(quantity_delta), 0) FROM %i WHERE product_id = %d AND variant_id = %d',
            $this->movements_table(),
            $product_id,
            $variant_id
        ));

        $reserved_by_others = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COALESCE(SUM(quantity), 0) FROM %i WHERE product_id = %d AND variant_id = %d AND expires_at > %s AND session_key != %s',
            $this->reservations_table(),
            $product_id,
            $variant_id,
            current_time('mysql', true),
            $session_key
        ));

        // Gleicher Filter wie free_for() (z. B. Sammelbestellung: bestellbar ohne Bestand).
        $available = Availability::filtered($sold - $reserved_by_others, $product_id, $variant_id > 0 ? $variant_id : null);

        if ($quantity > $available) {
            $wpdb->query('ROLLBACK');

            return false;
        }

        // Haelt dieser Besucher bereits genau diese Menge, bleibt der Ablauf unveraendert -
        // sonst koennte man durch Neuladen der Kasse beliebig lange reservieren.
        if ($keep_expiry) {
            $held = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COALESCE(SUM(quantity), 0) FROM %i WHERE product_id = %d AND variant_id = %d AND session_key = %s AND expires_at > %s',
                $this->reservations_table(),
                $product_id,
                $variant_id,
                $session_key,
                current_time('mysql', true)
            ));

            if ($held === $quantity) {
                $wpdb->query('COMMIT');

                return true;
            }
        }

        $wpdb->delete($this->reservations_table(), [
            'product_id'  => $product_id,
            'variant_id'  => $variant_id,
            'session_key' => $session_key,
        ]);

        if ($wpdb->insert($this->reservations_table(), [
            'product_id'  => $product_id,
            'variant_id'  => $variant_id,
            'quantity'    => $quantity,
            'session_key' => $session_key,
            'expires_at'  => gmdate('Y-m-d H:i:s', time() + $ttl_seconds),
        ]) === false) {
            $error = $wpdb->last_error;
            $wpdb->query('ROLLBACK');

            throw new \RuntimeException('Reservierung konnte nicht gespeichert werden: ' . $error); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- wird nur protokolliert (Logger::exception), nie ausgegeben
        }

        $wpdb->query('COMMIT');

        return true;
    }

    /**
     * Reservierte Stueckzahl je Artikel (nur nicht abgelaufene), optional ohne
     * die Reservierungen einer Sitzung (= die des Besuchers selbst).
     *
     * @param array<int, int> $product_ids
     * @return array<int, int> product_id => reserviert
     */
    public function reserved_for(array $product_ids, ?string $except_session = null): array
    {
        global $wpdb;

        $ids = array_values(array_filter(array_map('intval', $product_ids)));

        if ($ids === []) {
            return [];
        }

        $rows = $wpdb->get_results($this->prepare_reserved('product_id', $ids, $except_session), ARRAY_A) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- in prepare_reserved() vorbereitet
        $map  = [];

        foreach ($rows as $row) {
            $map[(int) $row['product_id']] = (int) $row['reserved'];
        }

        return $map;
    }

    /**
     * Reservierte Stueckzahl je Artikel und Variante (nur nicht abgelaufene).
     *
     * @param array<int, int> $product_ids
     * @return array<int, array<int, int>> product_id => [variant_id => reserviert]
     */
    public function reserved_by_variant(array $product_ids, ?string $except_session = null): array
    {
        global $wpdb;

        $ids = array_values(array_filter(array_map('intval', $product_ids)));

        if ($ids === []) {
            return [];
        }

        $rows = $wpdb->get_results($this->prepare_reserved('product_id, variant_id', $ids, $except_session), ARRAY_A) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- in prepare_reserved() vorbereitet
        $map  = array_fill_keys($ids, []);

        foreach ($rows as $row) {
            $map[(int) $row['product_id']][(int) $row['variant_id']] = (int) $row['reserved'];
        }

        return $map;
    }

    /**
     * Summenabfrage ueber nicht abgelaufene Reservierungen, gruppiert nach
     * $group ('product_id' oder 'product_id, variant_id'), optional ohne die
     * Reservierungen einer Sitzung.
     *
     * @param array<int, int> $ids bereits als int gefiltert, nicht leer
     */
    private function prepare_reserved(string $group, array $ids, ?string $except_session): string
    {
        global $wpdb;

        $columns      = $group === 'product_id' ? 'product_id' : 'product_id, variant_id';
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $session      = $except_session !== null ? ' AND session_key != %s' : '';
        $params       = array_merge([$this->reservations_table(), current_time('mysql', true)], $ids, $except_session !== null ? [$except_session] : []);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Spalten aus Whitelist, sonst nur Platzhalter (je ID ein %d)
        return $wpdb->prepare('SELECT ' . $columns . ', SUM(quantity) AS reserved FROM %i WHERE expires_at > %s AND product_id IN (' . $placeholders . ')' . $session . ' GROUP BY ' . $columns, ...$params);
    }

    /**
     * Was der Besucher (Sitzung) von diesem Artikel bzw. dieser Variante noch
     * in den Warenkorb legen darf: Bestand abzueglich fremder Reservierungen.
     *
     * @param int|null $variant_id null = alle Varianten zusammen, 0 = Artikel ohne Varianten
     */
    public function free_for(int $product_id, ?string $session_key, ?int $variant_id = null): int
    {
        global $wpdb;

        if ($variant_id === null) {
            $balance = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COALESCE(SUM(quantity_delta), 0) FROM %i WHERE product_id = %d',
                $this->movements_table(),
                $product_id
            ));

            return Availability::filtered(Availability::free($balance, $this->reserved_for([$product_id], $session_key)[$product_id] ?? 0), $product_id, null);
        }

        $balance = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COALESCE(SUM(quantity_delta), 0) FROM %i WHERE product_id = %d AND variant_id = %d',
            $this->movements_table(),
            $product_id,
            $variant_id
        ));

        return Availability::filtered(Availability::free($balance, $this->reserved_by_variant([$product_id], $session_key)[$product_id][$variant_id] ?? 0), $product_id, $variant_id);
    }

    /** Wann laeuft die frueheste aktive Reservierung dieser Sitzung ab (UTC-Zeitstempel)? */
    public function expires_at_for_session(string $session_key): ?int
    {
        global $wpdb;

        $value = $wpdb->get_var($wpdb->prepare(
            'SELECT MIN(expires_at) FROM %i WHERE session_key = %s AND expires_at > %s',
            $this->reservations_table(),
            $session_key,
            current_time('mysql', true)
        ));

        $time = $value !== null ? strtotime((string) $value . ' UTC') : false;

        return $time === false ? null : $time;
    }

    /**
     * @param int|null $variant_id null = alle Varianten des Artikels
     */
    public function release_product(int $product_id, string $session_key, ?int $variant_id = null): void
    {
        global $wpdb;

        $where = ['product_id' => $product_id, 'session_key' => $session_key];

        if ($variant_id !== null) {
            $where['variant_id'] = $variant_id;
        }

        $wpdb->delete($this->reservations_table(), $where);
    }

    public function release_for_session(string $session_key): void
    {
        global $wpdb;

        $wpdb->delete($this->reservations_table(), ['session_key' => $session_key]);
    }

    /**
     * Loescht abgelaufene Reservierungen (WP-Cron, Abschnitt 12).
     */
    public function purge_expired(): int
    {
        global $wpdb;

        return (int) $wpdb->query($wpdb->prepare(
            'DELETE FROM %i WHERE expires_at <= %s',
            $this->reservations_table(),
            current_time('mysql', true)
        ));
    }
}
