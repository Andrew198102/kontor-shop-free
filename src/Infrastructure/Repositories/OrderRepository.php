<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- eigene Tabellen des Plugins, kein WP-Objektcache-Bezug

use Kontor\Shop\Domain\Order\Order;
use Kontor\Shop\Domain\Order\OrderItem;
use Kontor\Shop\Domain\Order\OrderNumber;
use Kontor\Shop\Domain\Order\OrderStatus;
use Kontor\Shop\Domain\Order\PaymentMethod;
use Kontor\Shop\Domain\Order\PaymentReference;
use Kontor\Shop\Domain\Order\Shipment;
use Kontor\Shop\Infrastructure\Audit\AuditLog;

/**
 * wpdb-Zugriff auf wp_kontor_shop_orders/order_items/order_status_log
 * (Abschnitt 5.3). order_number/payment_reference werden erst nach dem
 * Insert aus der neuen ID abgeleitet (OrderNumber/PaymentReference) und
 * dann nachgetragen - beide Spalten sind NOT NULL UNIQUE, daher zuerst ein
 * eindeutiger Platzhalter.
 *
 * Eingriffe nach dem Bestellen (Status, Betraege, Storno, Versand, Loeschen,
 * Anonymisieren) landen zusaetzlich im Aenderungsprotokoll (AuditLog) - nur
 * mit Bestellnummer, ohne Kundendaten. Das Anlegen durch den Kunden nicht.
 */
final class OrderRepository
{
    /** Auf Rechnung raus, nicht bezahlt (Order::awaits_payment); Parameter: Zahlungsart, versendet, abgeholt */
    private const UNPAID_INVOICE_SQL = 'payment_method = %s AND status IN (%s, %s) AND paid_at IS NULL AND total > 0';

    private function orders_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kontor_shop_orders';
    }

    private function items_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kontor_shop_order_items';
    }

    private function shipments_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kontor_shop_shipments';
    }

    private function status_log_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kontor_shop_order_status_log';
    }

    /** Einloesungen von Rabattcodes (Tabelle gehoert zu Pro, Datenschutz bleibt im Kern). */
    private function coupon_uses_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kontor_shop_coupon_uses';
    }

    /**
     * @param array<string, mixed>              $order
     * @param array<int, array<string, mixed>>  $items
     */
    public function create(array $order, array $items): Order
    {
        global $wpdb;

        $placeholder = 'tmp-' . wp_generate_password(24, false);

        // Jeder Schreibvorgang wird geprueft: bei einem Fehler wird eine Exception geworfen,
        // damit der Aufrufer (Checkout) die Transaktion zurueckrollen und sauber melden kann.
        if ($wpdb->insert($this->orders_table(), array_merge($this->prepare_order_data($order), [
            'order_number'      => $placeholder,
            'payment_reference' => $placeholder,
            // Ortszeit der Website wie ueberall im Plugin - der Datenbank-Standardwert waere die Serverzeit (oft UTC).
            'created_at'        => current_time('mysql'),
        ])) === false) {
            throw new \RuntimeException('Bestellung konnte nicht gespeichert werden: ' . $wpdb->last_error); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Meldung wird nur protokolliert (Logger), nie ausgegeben
        }

        $id = (int) $wpdb->insert_id;

        if ($id <= 0) {
            throw new \RuntimeException('Bestellung ohne ID angelegt.');
        }

        if ($wpdb->update($this->orders_table(), [
            'order_number'      => OrderNumber::for_id($id),
            'payment_reference' => PaymentReference::for_id($id),
        ], ['id' => $id]) === false) {
            throw new \RuntimeException('Bestellnummer konnte nicht gesetzt werden: ' . $wpdb->last_error); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Meldung wird nur protokolliert (Logger), nie ausgegeben
        }

        foreach ($items as $item) {
            if ($wpdb->insert($this->items_table(), $this->prepare_item_data($id, $item)) === false) {
                throw new \RuntimeException('Bestellposition konnte nicht gespeichert werden: ' . $wpdb->last_error); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Meldung wird nur protokolliert (Logger), nie ausgegeben
            }
        }

        if ($wpdb->insert($this->status_log_table(), [
            'order_id'    => $id,
            'from_status' => null,
            'to_status'   => OrderStatus::OPEN,
            'user_id'     => null,
            'note'        => 'Bestellung angelegt',
            'created_at'  => current_time('mysql'),
        ]) === false) {
            throw new \RuntimeException('Statusverlauf konnte nicht gespeichert werden: ' . $wpdb->last_error); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Meldung wird nur protokolliert (Logger), nie ausgegeben
        }

        /** @var Order $created */
        $created = $this->find($id);

        return $created;
    }

    public function find(int $id): ?Order
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $this->orders_table(), $id),
            ARRAY_A
        );

        return $row ? Order::from_row($row) : null;
    }

    /**
     * Bestellung ueber Bestellnummer (B-000123) oder Zahlungsreferenz
     * (KS-000123) - so adressiert die Kassen-Schnittstelle Bestellungen.
     */
    public function find_by_reference(string $reference): ?Order
    {
        global $wpdb;

        $reference = trim($reference);

        if ($reference === '' || preg_match('/^[A-Za-z0-9._-]{1,64}$/', $reference) !== 1) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM %i WHERE order_number = %s OR payment_reference = %s LIMIT 1', $this->orders_table(), $reference, $reference),
            ARRAY_A
        );

        return $row ? Order::from_row($row) : null;
    }

    /**
     * Liste fuer die Kassen-Schnittstelle (seitenweise).
     *
     *  - status: open | paid | shipped | picked_up | cancelled (Bestellstatus),
     *    todo (Standard: offene Zahlung ODER offene Rueckzahlung),
     *    refund_pending (nur offene Rueckzahlungen), all
     *  - updated_since: nur seither geaenderte Bestellungen ("Y-m-d H:i:s",
     *    Shop-Ortszeit), dann aufsteigend nach Aenderung sortiert - so kann
     *    eine Gegenstelle inkrementell abgleichen
     *  - q: Suche in Nummer/Referenz/Name/E-Mail/Rabattcode
     *
     * @param array{status?: string, updated_since?: string, q?: string} $filters
     * @return array{items: array<int, Order>, total: int}
     */
    public function receivables(array $filters, int $page, int $per_page): array
    {
        global $wpdb;

        $where  = [];
        $params = [];
        $status = (string) ($filters['status'] ?? 'todo');

        if ($status === 'todo') {
            // offen, Rueckzahlung offen, oder auf Rechnung versendet und noch nicht bezahlt
            $where[] = '(status = %s OR refund_total > refunded_total OR (' . self::UNPAID_INVOICE_SQL . '))';
            array_push($params, OrderStatus::OPEN, PaymentMethod::INVOICE, OrderStatus::SHIPPED, OrderStatus::PICKED_UP);
        } elseif ($status === 'refund_pending') {
            $where[] = 'refund_total > refunded_total';
        } elseif (OrderStatus::is_known($status)) {
            $where[]  = 'status = %s';
            $params[] = $status;
        }

        $since = (string) ($filters['updated_since'] ?? '');

        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $since) === 1) {
            $where[]  = 'updated_at >= %s';
            $params[] = $since;
        }

        $q = trim((string) ($filters['q'] ?? ''));

        if ($q !== '') {
            $like    = '%' . $wpdb->esc_like($q) . '%';
            $where[] = '(order_number LIKE %s OR payment_reference LIKE %s OR customer_name LIKE %s OR customer_email LIKE %s OR discount_code LIKE %s'
                . ' OR id IN (SELECT order_id FROM %i WHERE product_name LIKE %s OR variant_label LIKE %s))';
            array_push($params, $like, $like, $like, $like, $like, $this->items_table(), $like, $like);
        }

        $where_sql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
        $order_sql = $since !== '' ? ' ORDER BY updated_at ASC, id ASC' : ' ORDER BY created_at DESC, id DESC';

        // WHERE/ORDER BY bestehen nur aus festen Fragmenten mit Platzhaltern, alle Werte gehen ueber prepare().
        $count_sql = 'SELECT COUNT(*) FROM %i' . $where_sql;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- WHERE aus festen Fragmenten, Werte via prepare()
        $total     = (int) $wpdb->get_var($wpdb->prepare($count_sql, $this->orders_table(), ...$params));

        $per_page = max(1, min(200, $per_page));
        $sql      = 'SELECT * FROM %i' . $where_sql . $order_sql . ' LIMIT %d OFFSET %d';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- WHERE/ORDER BY aus festen Fragmenten, Werte via prepare()
        $rows     = $wpdb->get_results($wpdb->prepare($sql, $this->orders_table(), ...array_merge($params, [$per_page, (max(1, $page) - 1) * $per_page])), ARRAY_A);

        return ['items' => array_map([Order::class, 'from_row'], $rows ?: []), 'total' => $total];
    }

    /**
     * Bestellungen eines Checkouts (bei aufgeteilten Bestellungen mehrere)
     * ueber das geheime Token der Bestaetigungsseite. Nur binnen $max_age_days
     * nach der Bestellung abrufbar.
     *
     * @return array<int, Order>
     */
    public function find_by_token(string $token): array
    {
        global $wpdb;

        if (preg_match('/^[A-Za-z0-9]{32}$/', $token) !== 1) {
            return [];
        }

        // Gueltigkeit (laeuft noch / X Tage nach Abschluss) prueft Domain\Order\OrderLink.
        $rows = $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM %i WHERE checkout_token = %s ORDER BY id ASC', $this->orders_table(), $token),
            ARRAY_A
        );

        return array_map([Order::class, 'from_row'], $rows ?: []);
    }

    /**
     * Haengt einen Vermerk (z. B. "Bestaetigung erneut gesendet") an den
     * Statusverlauf, ohne den Status zu aendern.
     */
    public function add_log_note(int $id, ?int $user_id, string $note, ?string $actor = null): void
    {
        global $wpdb;

        $current = $this->find($id);

        $wpdb->insert($this->status_log_table(), [
            'order_id'    => $id,
            'from_status' => $current?->status,
            'to_status'   => (string) ($current?->status ?? OrderStatus::OPEN),
            'user_id'     => $user_id,
            'actor'       => $actor,
            'note'        => $note,
            'created_at'  => current_time('mysql'),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function status_log(int $order_id): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM %i WHERE order_id = %d ORDER BY created_at ASC, id ASC', $this->status_log_table(), $order_id),
            ARRAY_A
        );

        return $rows ?: [];
    }

    /**
     * @return array<int, Order>
     */
    public function all(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY created_at DESC', $this->orders_table()), ARRAY_A);

        return array_map([Order::class, 'from_row'], $rows ?: []);
    }

    /**
     * Wie viele Bestellungen ein Bankkonto (ID) als Zahlungsziel nutzen - ein
     * Konto, das noch in Bestellungen steht, darf nicht entfernt werden
     * (Bestaetigungsmails und Detailansicht loesen es ueber die ID auf).
     */
    public function count_using_bank_account(string $bank_account_id): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE bank_account_id = %s',
            $this->orders_table(),
            $bank_account_id
        ));
    }

    /**
     * Retournierte Stueck einer Position hochzaehlen. Nur wenn sich die
     * Position inzwischen nicht geaendert hat und nie mehr als geliefert.
     *
     * @return bool false = zwischenzeitlich geaendert
     */
    public function add_returned(int $item_id, int $quantity, int $expected_returned): bool
    {
        global $wpdb;

        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE %i SET returned_qty = returned_qty + %d, returned_at = %s'
                . ' WHERE id = %d AND returned_qty = %d AND returned_qty + %d <= quantity',
            $this->items_table(),
            $quantity,
            current_time('mysql'),
            $item_id,
            $expected_returned,
            $quantity
        ));

        if ($updated === false) {
            throw new \RuntimeException('Retoure konnte nicht gespeichert werden: ' . $wpdb->last_error); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Meldung wird nur protokolliert (Logger), nie ausgegeben
        }

        return $updated === 1;
    }

    /**
     * Auf Rechnung versendet/abgeholt, aber noch nicht bezahlt (Zahlungserinnerung).
     *
     * @return array<int, Order>
     */
    public function unpaid_invoices(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Platzhalter stecken in UNPAID_INVOICE_SQL
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Klassenkonstante mit festen Platzhaltern, Werte via prepare()
            'SELECT * FROM %i WHERE ' . self::UNPAID_INVOICE_SQL . ' ORDER BY shipped_at ASC, id ASC',
            $this->orders_table(),
            PaymentMethod::INVOICE,
            OrderStatus::SHIPPED,
            OrderStatus::PICKED_UP
        ), ARRAY_A);

        return array_map([Order::class, 'from_row'], $rows ?: []);
    }

    /**
     * Offene Vorkasse-Bestellungen, deren Zahlungsfrist abgelaufen ist
     * (gleiche Regel wie Domain\Order\PaymentDeadline::is_overdue).
     */
    public function count_overdue(int $payment_days): int
    {
        global $wpdb;

        if ($payment_days <= 0) {
            return 0;
        }

        $cutoff = gmdate('Y-m-d', (int) strtotime(current_time('Y-m-d') . ' 00:00:00 UTC') - $payment_days * DAY_IN_SECONDS);

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE status = %s AND payment_method = %s AND created_at < %s',
            $this->orders_table(),
            OrderStatus::OPEN,
            PaymentMethod::PREPAYMENT,
            $cutoff
        ));
    }

    /**
     * WHERE-Teil fuer die Bestellsuche (alle Filter optional, kombinierbar).
     *
     * @param array{status?: string, q?: string, from?: string, to?: string} $filters
     * @return array{0: string, 1: array<int, mixed>} SQL-Teil (leer oder " WHERE ...") und Parameter
     */
    private function where_for(array $filters, bool $ignore_status = false): array
    {
        global $wpdb;

        $where  = [];
        $params = [];

        $status = (string) ($filters['status'] ?? '');

        if (!$ignore_status && $status !== '' && OrderStatus::is_known($status)) {
            $where[]  = 'status = %s';
            $params[] = $status;
        }

        $q = trim((string) ($filters['q'] ?? ''));

        if ($q !== '') {
            $like    = '%' . $wpdb->esc_like($q) . '%';
            $where[] = '(order_number LIKE %s OR payment_reference LIKE %s OR customer_name LIKE %s OR customer_email LIKE %s OR discount_code LIKE %s'
                . ' OR id IN (SELECT order_id FROM %i WHERE product_name LIKE %s OR variant_label LIKE %s))';
            array_push($params, $like, $like, $like, $like, $like, $this->items_table(), $like, $like);
        }

        $from = (string) ($filters['from'] ?? '');

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1) {
            $where[]  = 'created_at >= %s';
            $params[] = $from . ' 00:00:00';
        }

        $to = (string) ($filters['to'] ?? '');

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1) {
            $where[]  = 'created_at <= %s';
            $params[] = $to . ' 23:59:59';
        }

        return [$where !== [] ? ' WHERE ' . implode(' AND ', $where) : '', $params];
    }

    /**
     * Filtert Bestellungen (alle Filter optional, kombinierbar).
     *
     * @param array{status?: string, q?: string, from?: string, to?: string} $filters
     *        from/to als Datum (Y-m-d), inklusive Grenzen
     * @return array<int, Order>
     */
    public function search(array $filters): array
    {
        global $wpdb;

        [$where, $params] = $this->where_for($filters);

        $sql = 'SELECT * FROM %i' . $where . ' ORDER BY created_at DESC, id DESC';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- WHERE aus where_for() (feste Fragmente), Werte via prepare()
        $rows = $wpdb->get_results($wpdb->prepare($sql, $this->orders_table(), ...$params), ARRAY_A);

        return array_map([Order::class, 'from_row'], $rows ?: []);
    }

    /**
     * Seitenweise Bestellliste mit Sortierung.
     *
     * @param array{status?: string, q?: string, from?: string, to?: string} $filters
     * @return array{items: array<int, Order>, total: int}
     */
    public function search_paged(array $filters, string $orderby, string $order, int $page, int $per_page): array
    {
        global $wpdb;

        [$where, $params] = $this->where_for($filters);

        $column = match ($orderby) {
            'total'    => 'total',
            'number'   => 'order_number',
            'customer' => 'customer_name',
            default    => 'created_at',
        };
        $direction = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

        $count_sql = 'SELECT COUNT(*) FROM %i' . $where;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- WHERE aus where_for() (feste Fragmente), Werte via prepare()
        $total     = (int) $wpdb->get_var($wpdb->prepare($count_sql, $this->orders_table(), ...$params));

        $per_page = max(1, min(500, $per_page));
        $sql      = 'SELECT * FROM %i' . $where . ' ORDER BY ' . $column . ' ' . $direction . ', id DESC LIMIT %d OFFSET %d';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- WHERE aus where_for(), Spalte/Richtung aus Whitelist, Werte via prepare()
        $rows     = $wpdb->get_results($wpdb->prepare($sql, $this->orders_table(), ...array_merge($params, [$per_page, (max(1, $page) - 1) * $per_page])), ARRAY_A);

        return ['items' => array_map([Order::class, 'from_row'], $rows ?: []), 'total' => $total];
    }

    /**
     * Anzahl je Status (unter Beruecksichtigung von Suche/Zeitraum, ohne den
     * Statusfilter) - fuer die Zaehler an den Status-Ansichten.
     *
     * @param array{status?: string, q?: string, from?: string, to?: string} $filters
     * @return array<string, int> Status => Anzahl
     */
    public function count_by_status(array $filters = []): array
    {
        global $wpdb;

        [$where, $params] = $this->where_for($filters, true);

        $sql  = 'SELECT status, COUNT(*) AS n FROM %i' . $where . ' GROUP BY status';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- WHERE aus where_for() (feste Fragmente), Werte via prepare()
        $rows = $wpdb->get_results($wpdb->prepare($sql, $this->orders_table(), ...$params), ARRAY_A) ?: [];

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * Bestellungen zu einer E-Mail-Adresse (fuer den WP-Datenschutz-Export/-Eraser),
     * seitenweise.
     *
     * @return array<int, Order>
     */
    public function for_email(string $email, int $page, int $per_page): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE customer_email = %s ORDER BY id ASC LIMIT %d OFFSET %d',
                $this->orders_table(),
                $email,
                $per_page,
                max(0, ($page - 1) * $per_page)
            ),
            ARRAY_A
        );

        return array_map([Order::class, 'from_row'], $rows ?: []);
    }

    /**
     * Entfernt den Personenbezug einer Bestellung (Name, E-Mail, Adresse),
     * behaelt aber die Buchungsdaten (Betraege, Positionen, Datum). Vermerke im
     * Statusverlauf, die eine Empfaengeradresse nennen, werden neutralisiert.
     */
    public function anonymize(int $id): void
    {
        global $wpdb;

        $order = $this->find($id);

        $wpdb->update($this->orders_table(), [
            'customer_name'    => '(anonymisiert)',
            'customer_email'   => 'anonymisiert-' . $id . '@invalid.local',
            'delivery_address' => $order?->delivery_address !== null ? '(anonymisiert)' : null,
            'customer_phone'  => null,
            'billing_name'    => $order?->billing_name !== null ? '(anonymisiert)' : null,
            'billing_address' => $order?->billing_address !== null ? '(anonymisiert)' : null,
            'billing_vat_id'  => null,
        ], ['id' => $id]);

        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET note = %s WHERE order_id = %d AND note LIKE %s',
            $this->status_log_table(),
            '(Vermerk anonymisiert)',
            $id,
            $wpdb->esc_like('Bestaetigungs-Mail erneut gesendet an') . '%'
        ));

        // Einloesungen eines Rabattcodes nennen die E-Mail-Adresse: schlichtes SQL im Kern,
        // damit die Loeschung auch ohne Pro (nach einem Downgrade) vollstaendig ist.
        $wpdb->update($this->coupon_uses_table(), ['customer_email' => ''], ['order_id' => $id]);

        AuditLog::record('order.anonymize', 'order', $id, $order?->order_number);

        /**
         * Aktion kontor_shop/order/anonymized: der Personenbezug der Bestellung
         * wurde entfernt (Datenschutz-Loeschung). Erweiterungen entfernen ihn
         * aus eigenen Daten zur Bestellung.
         *
         * @param int $id Bestell-ID
         */
        do_action('kontor_shop/order/anonymized', $id);
    }

    /**
     * Loescht eine Bestellung samt Positionen und Statusverlauf. Die
     * zugehoerigen Bestandsbewegungen bleiben als Lager-Protokoll erhalten.
     */
    public function delete(int $id): void
    {
        global $wpdb;

        $order = $this->find($id);

        $wpdb->delete($this->items_table(), ['order_id' => $id]);
        $wpdb->delete($this->shipments_table(), ['order_id' => $id]);
        $wpdb->delete($this->status_log_table(), ['order_id' => $id]);
        $wpdb->delete($this->orders_table(), ['id' => $id]);
        // Einloesungen eines Rabattcodes (mit E-Mail-Adresse) gehen mit - auch ohne Pro.
        $wpdb->delete($this->coupon_uses_table(), ['order_id' => $id]);

        if ($order !== null) {
            AuditLog::record('order.delete', 'order', $id, $order->order_number, [
                'status' => [$order->status, null],
                'total'  => [number_format($order->total, 2, '.', ''), null],
            ]);
        }

        /**
         * Aktion kontor_shop/order/deleted: die Bestellung samt Positionen,
         * Sendungen und Verlauf ist geloescht. Erweiterungen raeumen eigene
         * Daten zur Bestellung auf.
         *
         * @param int $id Bestell-ID
         */
        do_action('kontor_shop/order/deleted', $id);
    }

    /**
     * Setzt den Status und schreibt den Wechsel ins order_status_log
     * (Abschnitt 5.1/8). $extra transportiert Begleitspalten desselben
     * Wechsels (z. B. paid_at/paid_by_user_id, shipped_at/shipped_by_user_id).
     *
     * Mit $expected_from wird nur umgestellt, wenn die Bestellung noch in
     * diesem Status ist (Schutz, wenn Shop-Admin und Gegenstelle gleichzeitig
     * buchen) - dann false und kein Eintrag im Verlauf.
     *
     * @param array<string, mixed> $extra
     */
    public function update_status(int $id, string $to_status, ?int $user_id, ?string $note, array $extra = [], ?string $actor = null, ?string $expected_from = null): bool
    {
        global $wpdb;

        $from  = $this->find($id);
        $where = ['id' => $id];

        if ($expected_from !== null) {
            if ($from === null || $from->status !== $expected_from) {
                return false;
            }

            $where['status'] = $expected_from;
        }

        $updated = $wpdb->update($this->orders_table(), array_merge(['status' => $to_status], $extra), $where);

        if ($updated === false) {
            throw new \RuntimeException('Status konnte nicht gespeichert werden: ' . $wpdb->last_error); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Meldung wird nur protokolliert (Logger), nie ausgegeben
        }

        if ($expected_from !== null && $updated === 0) {
            return false;
        }

        $wpdb->insert($this->status_log_table(), [
            'order_id'    => $id,
            'from_status' => $from?->status,
            'to_status'   => $to_status,
            'user_id'     => $user_id,
            'actor'       => $actor,
            'note'        => $note,
            'created_at'  => current_time('mysql'),
        ]);

        $changes = ['status' => [$from?->status, $to_status]];

        foreach ($extra as $column => $value) {
            $changes[(string) $column] = [null, $value === null ? null : (string) $value];
        }

        AuditLog::record('order.status', 'order', $id, $from?->order_number, $changes, $actor);

        /**
         * Aktion kontor_shop/order/status_changed: der Status einer Bestellung
         * wurde umgestellt (laeuft innerhalb einer evtl. offenen Transaktion,
         * z. B. beim Bestellen). Pro gibt damit bei Storno die Einloesung eines
         * Rabattcodes wieder frei.
         *
         * @param int         $id   Bestell-ID
         * @param string|null $from bisheriger Status (null, wenn die Bestellung nicht gefunden wurde)
         * @param string      $to   neuer Status (OrderStatus::*)
         */
        do_action('kontor_shop/order/status_changed', $id, $from?->status, $to_status);

        return true;
    }

    /**
     * Legt eine Sendung an und haengt die (noch offenen) Positionen daran.
     *
     * @param array<int, int> $item_ids
     * @return int ID der Sendung
     */
    public function create_shipment(int $order_id, ?int $warehouse_id, string $type, ?string $carrier, ?string $tracking_number, ?string $note, ?int $user_id, array $item_ids): int
    {
        global $wpdb;

        $item_ids = array_values(array_filter(array_map('intval', $item_ids)));

        if ($item_ids === []) {
            throw new \InvalidArgumentException('Sendung ohne Positionen');
        }

        $ok = $wpdb->insert($this->shipments_table(), [
            'order_id'        => $order_id,
            'warehouse_id'    => $warehouse_id,
            'type'            => $type,
            'carrier'         => $carrier,
            'tracking_number' => $tracking_number,
            'note'            => $note,
            'user_id'         => $user_id,
            'created_at'      => current_time('mysql'),
        ]);

        if ($ok === false) {
            throw new \RuntimeException('Sendung konnte nicht gespeichert werden: ' . $wpdb->last_error); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Meldung wird nur protokolliert (Logger), nie ausgegeben
        }

        $shipment_id = (int) $wpdb->insert_id;

        $wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IN-Liste per array_fill, Werte per Spread
            'UPDATE %i SET shipment_id = %d WHERE order_id = %d AND shipment_id IS NULL AND id IN (' . implode(',', array_fill(0, count($item_ids), '%d')) . ')',
            $this->items_table(),
            $shipment_id,
            $order_id,
            ...$item_ids
        ));

        AuditLog::record('order.shipment', 'order', $order_id, $this->find($order_id)?->order_number, [
            'shipment_id'     => [null, (string) $shipment_id],
            'type'            => [null, $type],
            'carrier'         => [null, $carrier],
            'tracking_number' => [null, $tracking_number],
            'item_ids'        => [null, implode(', ', $item_ids)],
        ]);

        return $shipment_id;
    }

    /**
     * Positionen als storniert markieren (nur noch nicht versendete, nicht bereits stornierte).
     *
     * @param array<int, int> $item_ids
     * @return int Anzahl tatsaechlich stornierter Positionen
     */
    public function cancel_items(int $order_id, array $item_ids, ?string $note): int
    {
        global $wpdb;

        $item_ids = array_values(array_filter(array_map('intval', $item_ids)));

        if ($item_ids === []) {
            return 0;
        }

        $cancelled = (int) $wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IN-Liste per array_fill, Werte per Spread
            'UPDATE %i SET cancelled_at = %s, cancel_note = %s
             WHERE order_id = %d AND cancelled_at IS NULL AND shipment_id IS NULL AND id IN (' . implode(',', array_fill(0, count($item_ids), '%d')) . ')',
            $this->items_table(),
            current_time('mysql'),
            $note,
            $order_id,
            ...$item_ids
        ));

        if ($cancelled > 0) {
            AuditLog::record('order.cancel_items', 'order', $order_id, $this->find($order_id)?->order_number, [
                'item_ids'  => [null, implode(', ', $item_ids)],
                'cancelled' => [null, (string) $cancelled],
            ]);
        }

        return $cancelled;
    }

    /**
     * Einzelne Spalten einer Bestellung aendern (Summen, Rueckzahlung) - ohne Statuswechsel.
     *
     * @param array<string, mixed> $fields
     */
    public function update_order_fields(int $id, array $fields): void
    {
        global $wpdb;

        $allowed = array_intersect_key($fields, array_flip(['items_total', 'total', 'refund_total', 'refunded_total', 'discount_total']));

        if ($allowed === []) {
            return;
        }

        $before = $this->find($id);

        if ($wpdb->update($this->orders_table(), $allowed, ['id' => $id]) === false) {
            throw new \RuntimeException('Bestellung konnte nicht aktualisiert werden: ' . $wpdb->last_error); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Meldung wird nur protokolliert (Logger), nie ausgegeben
        }

        if ($before !== null) {
            $old = array_intersect_key(get_object_vars($before), $allowed);

            AuditLog::record_diff('order.amounts', 'order', $id, $before->order_number, $old, $allowed);
        }
    }

    /**
     * Bereits zurueckgezahlte Summe erhoehen - nur, wenn sie seit dem Lesen
     * unveraendert ist (keine doppelte Buchung bei gleichzeitigen Klicks).
     */
    public function add_refunded(int $id, float $expected_current, float $new_total): bool
    {
        global $wpdb;

        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE %i SET refunded_total = %s WHERE id = %d AND refunded_total = %s AND refund_total >= %s',
            $this->orders_table(),
            number_format($new_total, 2, '.', ''),
            $id,
            number_format($expected_current, 2, '.', ''),
            number_format($new_total, 2, '.', '')
        ));

        if ($updated === false) {
            throw new \RuntimeException('Rueckzahlung konnte nicht gespeichert werden: ' . $wpdb->last_error); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Meldung wird nur protokolliert (Logger), nie ausgegeben
        }

        if ((int) $updated > 0) {
            AuditLog::record('order.refund', 'order', $id, $this->find($id)?->order_number, [
                'refunded_total' => [number_format($expected_current, 2, '.', ''), number_format($new_total, 2, '.', '')],
            ]);
        }

        return (int) $updated > 0;
    }

    /**
     * Bestellungen mit offener Rueckzahlung.
     */
    public function count_refund_pending(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE refund_total > refunded_total', $this->orders_table()));
    }

    /**
     * @return array<int, Shipment>
     */
    public function shipments_for_order(int $order_id): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM %i WHERE order_id = %d ORDER BY created_at ASC, id ASC', $this->shipments_table(), $order_id),
            ARRAY_A
        );

        return array_map([Shipment::class, 'from_row'], $rows ?: []);
    }

    /**
     * Positionen mehrerer Bestellungen in einer Abfrage (Bestellliste).
     *
     * @param array<int, int> $order_ids
     * @return array<int, array<int, OrderItem>> order_id => Positionen
     */
    public function items_for_orders(array $order_ids): array
    {
        global $wpdb;

        $ids = array_values(array_filter(array_map('intval', $order_ids)));

        if ($ids === []) {
            return [];
        }

        $map  = [];
        $rows = $wpdb->get_results($wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IN-Liste per array_fill, Werte per Spread
            'SELECT * FROM %i WHERE order_id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ') ORDER BY id ASC',
            $this->items_table(),
            ...$ids
        ), ARRAY_A);

        foreach ($rows ?: [] as $row) {
            $map[(int) $row['order_id']][] = OrderItem::from_row($row);
        }

        return $map;
    }

    /**
     * Zeitpunkt des letzten Verlaufseintrags eines bestimmten Absenders je
     * Bestellung (z. B. PaymentReminder::LOG_ACTOR = letzte Zahlungserinnerung).
     *
     * @param array<int, int> $order_ids leer = alle Bestellungen
     * @return array<int, string> order_id => created_at
     */
    public function last_log_by_actor(array $order_ids, string $actor): array
    {
        global $wpdb;

        $ids = array_values(array_filter(array_map('intval', $order_ids)));

        if ($ids === []) {
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT order_id, MAX(created_at) AS at FROM %i WHERE actor = %s GROUP BY order_id',
                $this->status_log_table(),
                $actor
            ), ARRAY_A) ?: [];
        } else {
            $rows = $wpdb->get_results($wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IN-Liste per array_fill, Werte per Spread
                'SELECT order_id, MAX(created_at) AS at FROM %i WHERE actor = %s AND order_id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ') GROUP BY order_id',
                $this->status_log_table(),
                $actor,
                ...$ids
            ), ARRAY_A) ?: [];
        }

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row['order_id']] = (string) $row['at'];
        }

        return $map;
    }

    /**
     * @return array<int, OrderItem>
     */
    public function items_for_order(int $order_id): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM %i WHERE order_id = %d', $this->items_table(), $order_id),
            ARRAY_A
        );

        return array_map([OrderItem::class, 'from_row'], $rows ?: []);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function prepare_order_data(array $data): array
    {
        return [
            'status'             => (string) ($data['status'] ?? OrderStatus::OPEN),
            'is_member'          => empty($data['is_member']) ? 0 : 1,
            'customer_name'      => (string) $data['customer_name'],
            'customer_email'     => (string) $data['customer_email'],
            'customer_phone'     => isset($data['customer_phone']) && $data['customer_phone'] !== '' ? (string) $data['customer_phone'] : null,
            'billing_name'       => isset($data['billing_name']) && $data['billing_name'] !== '' ? (string) $data['billing_name'] : null,
            'billing_address'    => isset($data['billing_address']) && $data['billing_address'] !== '' ? (string) $data['billing_address'] : null,
            'billing_vat_id'     => isset($data['billing_vat_id']) && $data['billing_vat_id'] !== '' ? (string) $data['billing_vat_id'] : null,
            'delivery_address'   => isset($data['delivery_address']) && $data['delivery_address'] !== null
                ? (string) $data['delivery_address']
                : null,
            'self_pickup'        => empty($data['self_pickup']) ? 0 : 1,
            'shipping_cost'      => (float) ($data['shipping_cost'] ?? 0),
            'items_total'        => (float) ($data['items_total'] ?? 0),
            'total'              => (float) ($data['total'] ?? 0),
            'payment_method'     => (string) $data['payment_method'],
            'checkout_token'     => isset($data['checkout_token']) && $data['checkout_token'] !== '' ? (string) $data['checkout_token'] : null,
            'shipping_tax'       => isset($data['shipping_tax']) && is_array($data['shipping_tax']) && $data['shipping_tax'] !== []
                ? wp_json_encode($data['shipping_tax'])
                : null,
            'bank_account_id'    => isset($data['bank_account_id']) && $data['bank_account_id'] !== '' ? (string) $data['bank_account_id'] : null,
            'legal_accepted_at'  => isset($data['legal_accepted_at']) && $data['legal_accepted_at'] !== null
                ? (string) $data['legal_accepted_at']
                : null,
            'discount_code'      => isset($data['discount_code']) && $data['discount_code'] !== '' ? (string) $data['discount_code'] : null,
            'discount_total'     => (float) ($data['discount_total'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function prepare_item_data(int $order_id, array $item): array
    {
        return [
            'order_id'     => $order_id,
            'product_id'    => (int) $item['product_id'],
            'variant_id'    => (int) ($item['variant_id'] ?? 0),
            'warehouse_id'  => (int) $item['warehouse_id'],
            'product_name'  => (string) $item['product_name'],
            'variant_label' => (string) ($item['variant_label'] ?? ''),
            'quantity'     => (int) $item['quantity'],
            'list_price'   => (float) $item['list_price'],
            'unit_price'   => (float) $item['unit_price'],
            'line_total'   => (float) $item['line_total'],
            'tax_rate'     => isset($item['tax_rate']) && $item['tax_rate'] !== null ? (float) $item['tax_rate'] : null,
            'discount'     => (float) ($item['discount'] ?? 0),
        ];
    }
}
