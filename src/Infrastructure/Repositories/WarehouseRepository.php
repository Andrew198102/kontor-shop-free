<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- eigene Tabellen des Plugins, kein WP-Objektcache-Bezug

use Kontor\Shop\Domain\Audit\ChangeSet;
use Kontor\Shop\Domain\Catalog\Warehouse;
use Kontor\Shop\Infrastructure\Audit\AuditLog;

/**
 * wpdb-Zugriff auf wp_kontor_shop_warehouses (Abschnitt 5.3) samt der
 * Lager-Grenzen: wie viele Lager angelegt werden duerfen
 * (kontor_shop/warehouses/max) und welche sich bearbeiten lassen
 * (kontor_shop/warehouses/editable). Ohne Erweiterung genau ein Lager - das
 * Hauptlager (primary()); weitere (z. B. nach einem Downgrade) bleiben
 * sichtbar, ihre Artikel verkaeuflich, sie selbst nur lesbar.
 */
final class WarehouseRepository
{
    /** Option mit der ID des Hauptlagers (siehe primary()). */
    public const PRIMARY_OPTION = 'kontor_shop_primary_warehouse';

    /** Spalten, die fuer das Aenderungsprotokoll nicht zaehlen */
    private const AUDIT_IGNORE = ['created_at', 'updated_at'];

    /** @var array{0: Warehouse|null}|null Hauptlager dieser Instanz (nach Aenderungen neu gelesen) */
    private ?array $primary = null;

    private function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kontor_shop_warehouses';
    }

    /**
     * @return array<int, Warehouse>
     */
    public function all(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY name ASC', $this->table()), ARRAY_A);

        return array_map([Warehouse::class, 'from_row'], $rows ?: []);
    }

    /**
     * Hauptlager: einmal bestimmt (das aktive mit der kleinsten ID, sonst das
     * erste ueberhaupt) und dann gemerkt (Option PRIMARY_OPTION) - Abschalten
     * macht es also nicht zu einem anderen Lager, nur Loeschen. null, solange
     * es kein Lager gibt.
     */
    public function primary(): ?Warehouse
    {
        global $wpdb;

        if ($this->primary === null) {
            $id  = (int) get_option(self::PRIMARY_OPTION, 0);
            $row = $id > 0 ? $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $this->table(), $id), ARRAY_A) : null;

            if (!$row) {
                $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i ORDER BY active DESC, id ASC LIMIT 1', $this->table()), ARRAY_A);

                if ($row) {
                    update_option(self::PRIMARY_OPTION, (int) $row['id'], false);
                }
            }

            $this->primary = [$row ? Warehouse::from_row($row) : null];
        }

        return $this->primary[0];
    }

    /**
     * Wie viele Lager angelegt werden duerfen.
     */
    public static function max(): int
    {
        /**
         * Filter kontor_shop/warehouses/max: Hoechstzahl der Lager (z. B.
         * Pro: unbegrenzt). Bestehende Lager darueber bleiben stehen, es
         * lassen sich nur keine neuen anlegen. Standard: 1.
         *
         * @param int $max
         */
        return max(1, (int) apply_filters('kontor_shop/warehouses/max', 1));
    }

    /** Darf noch ein Lager angelegt werden? */
    public function can_create(): bool
    {
        return count($this->all()) < self::max();
    }

    /**
     * Laesst sich das Lager bearbeiten (Formular, Ein/Aus, Loeschen, als
     * Lager eines Artikels waehlen)?
     */
    public function is_editable(Warehouse $warehouse): bool
    {
        $primary = $this->primary();

        /**
         * Filter kontor_shop/warehouses/editable: ob ein Lager bearbeitet
         * werden darf (z. B. Pro: jedes). Nicht bearbeitbare Lager bleiben
         * sichtbar und ihre Artikel verkaeuflich. Standard: nur das
         * Hauptlager (WarehouseRepository::primary()).
         *
         * @param bool      $editable
         * @param Warehouse $warehouse
         */
        return (bool) apply_filters('kontor_shop/warehouses/editable', $primary !== null && $primary->id === $warehouse->id, $warehouse);
    }

    public function find(int $id): ?Warehouse
    {
        $row = $this->raw($id);

        return $row !== null ? Warehouse::from_row($row) : null;
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

        $this->primary = null;

        $row = $this->prepare_data($data);

        $wpdb->insert($this->table(), $row);

        $id = (int) $wpdb->insert_id;

        AuditLog::record('warehouse.create', 'warehouse', $id, $row['name'], ChangeSet::created($row));

        return $id;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        global $wpdb;

        $this->primary = null;

        $before = $this->raw($id);

        $wpdb->update($this->table(), $this->prepare_data($data), ['id' => $id]);
        $this->audit_update($id, $before);
    }

    public function set_active(int $id, bool $active): void
    {
        global $wpdb;

        $this->primary = null;

        $before = $this->raw($id);

        $wpdb->update($this->table(), ['active' => $active ? 1 : 0], ['id' => $id]);
        $this->audit_update($id, $before);
    }

    public function delete(int $id): void
    {
        global $wpdb;

        $this->primary = null;

        $before = $this->raw($id);

        $wpdb->delete($this->table(), ['id' => $id]);

        if ($before !== null) {
            AuditLog::record('warehouse.delete', 'warehouse', $id, (string) $before['name'], ChangeSet::deleted($before, self::AUDIT_IGNORE));
        }
    }

    /**
     * @param array<string, mixed>|null $before
     */
    private function audit_update(int $id, ?array $before): void
    {
        $after = $this->raw($id);

        if ($before !== null && $after !== null) {
            AuditLog::record_diff('warehouse.update', 'warehouse', $id, (string) $after['name'], $before, $after, self::AUDIT_IGNORE);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function prepare_data(array $data): array
    {
        $row = [
            'name'            => (string) $data['name'],
            'contact_name'    => (string) ($data['contact_name'] ?? ''),
            'address'         => isset($data['address']) && $data['address'] !== '' ? (string) $data['address'] : null,
            'bank_account_id' => isset($data['bank_account_id']) && $data['bank_account_id'] !== '' ? (string) $data['bank_account_id'] : null,
            'color'           => isset($data['color']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $data['color']) === 1 ? (string) $data['color'] : null,
            'active'          => empty($data['active']) ? 0 : 1,
        ];

        // Lagerverwalter nur, wenn mitgegeben - ein Formular ohne das Feld behaelt den gespeicherten Wert.
        if (array_key_exists('manager_user_id', $data)) {
            $row['manager_user_id'] = (int) $data['manager_user_id'] > 0 ? (int) $data['manager_user_id'] : null;
        }

        return $row;
    }
}
