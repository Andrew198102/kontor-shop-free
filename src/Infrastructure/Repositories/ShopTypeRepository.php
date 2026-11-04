<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- eigene Tabellen des Plugins, kein WP-Objektcache-Bezug

use Kontor\Shop\Domain\Audit\ChangeSet;
use Kontor\Shop\Domain\Catalog\ShopType;
use Kontor\Shop\Infrastructure\Audit\AuditLog;

/**
 * wpdb-Zugriff auf wp_kontor_shop_shop_types.
 */
final class ShopTypeRepository
{
    /** Spalten, die fuer das Aenderungsprotokoll nicht zaehlen */
    private const AUDIT_IGNORE = ['created_at', 'updated_at'];

    private function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kontor_shop_shop_types';
    }

    /**
     * @return array<int, ShopType>
     */
    public function all(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY sort_order ASC, name ASC', $this->table()), ARRAY_A);

        return array_map([ShopType::class, 'from_row'], $rows ?: []);
    }

    /**
     * @return array<int, ShopType>
     */
    public function active(): array
    {
        return array_values(array_filter($this->all(), static fn (ShopType $type): bool => $type->active));
    }

    /**
     * Standard-Shoptyp fuer neue Kategorien, wenn keiner gewaehlt wird (ohne
     * Pro immer): der erste nach Sortierung. Gibt es keinen, wird der neutrale
     * "Allgemein" angelegt (wie bei der Installation, Core\Lifecycle).
     */
    public function default_id(): int
    {
        $types = $this->all();

        if ($types !== []) {
            return (int) $types[0]->id;
        }

        return $this->create([
            'name'       => __('General', 'kontor-shop'),
            'slug'       => 'allgemein',
            'sort_order' => 0,
            'active'     => true,
        ]);
    }

    public function find(int $id): ?ShopType
    {
        $row = $this->raw($id);

        return $row !== null ? ShopType::from_row($row) : null;
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

    public function find_by_slug(string $slug): ?ShopType
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM %i WHERE slug = %s', $this->table(), $slug),
            ARRAY_A
        );

        return $row ? ShopType::from_row($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        global $wpdb;

        $row = $this->prepare_data($data);

        $wpdb->insert($this->table(), $row);

        $id = (int) $wpdb->insert_id;

        AuditLog::record('shop_type.create', 'shop_type', $id, $row['name'], ChangeSet::created($row));

        return $id;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        global $wpdb;

        $before = $this->raw($id);

        $wpdb->update($this->table(), $this->prepare_data($data), ['id' => $id]);
        $this->audit_update($id, $before);
    }

    public function set_page_id(int $id, ?int $page_id): void
    {
        global $wpdb;

        $wpdb->update($this->table(), ['page_id' => $page_id], ['id' => $id]);
    }

    public function set_active(int $id, bool $active): void
    {
        global $wpdb;

        $before = $this->raw($id);

        $wpdb->update($this->table(), ['active' => $active ? 1 : 0], ['id' => $id]);
        $this->audit_update($id, $before);
    }

    public function set_sort_order(int $id, int $sort_order): void
    {
        global $wpdb;

        $wpdb->update($this->table(), ['sort_order' => $sort_order], ['id' => $id]);
    }

    public function delete(int $id): void
    {
        global $wpdb;

        $before = $this->raw($id);

        $wpdb->delete($this->table(), ['id' => $id]);

        if ($before !== null) {
            AuditLog::record('shop_type.delete', 'shop_type', $id, (string) $before['name'], ChangeSet::deleted($before, self::AUDIT_IGNORE));
        }
    }

    /**
     * @param array<string, mixed>|null $before
     */
    private function audit_update(int $id, ?array $before): void
    {
        $after = $this->raw($id);

        if ($before !== null && $after !== null) {
            AuditLog::record_diff('shop_type.update', 'shop_type', $id, (string) $after['name'], $before, $after, self::AUDIT_IGNORE);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function prepare_data(array $data): array
    {
        $accent_color = isset($data['accent_color']) ? trim((string) $data['accent_color']) : '';

        return [
            'name'         => (string) $data['name'],
            'slug'         => (string) $data['slug'],
            'accent_color' => $accent_color !== '' ? $accent_color : null,
            'sort_order'   => (int) ($data['sort_order'] ?? 0),
            'active'       => empty($data['active']) ? 0 : 1,
        ];
    }
}
