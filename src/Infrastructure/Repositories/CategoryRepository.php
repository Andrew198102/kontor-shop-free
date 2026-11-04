<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Repositories;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- eigene Tabellen des Plugins, kein WP-Objektcache-Bezug

use Kontor\Shop\Domain\Audit\ChangeSet;
use Kontor\Shop\Domain\Catalog\Category;
use Kontor\Shop\Infrastructure\Audit\AuditLog;
use Kontor\Shop\Infrastructure\Seo\CatalogEvents;

/**
 * wpdb-Zugriff auf wp_kontor_shop_categories.
 */
final class CategoryRepository
{
    /** Spalten, die fuer das Aenderungsprotokoll nicht zaehlen */
    private const AUDIT_IGNORE = ['created_at', 'updated_at'];

    private function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kontor_shop_categories';
    }

    /**
     * @return array<int, Category>
     */
    public function all(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY sort_order ASC, name ASC', $this->table()), ARRAY_A);

        return array_map([Category::class, 'from_row'], $rows ?: []);
    }

    /**
     * @return array<int, Category>
     */
    public function active(): array
    {
        return array_values(array_filter($this->all(), static fn (Category $category): bool => $category->active));
    }

    /**
     * @return array<int, Category>
     */
    public function for_shop_type(int $shop_type_id): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (Category $category): bool => $category->shop_type_id === $shop_type_id
        ));
    }

    public function count_for_shop_type(int $shop_type_id): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM %i WHERE shop_type_id = %d', $this->table(), $shop_type_id)
        );
    }

    public function assign_shop_type(int $id, int $shop_type_id): void
    {
        global $wpdb;

        $wpdb->update($this->table(), ['shop_type_id' => $shop_type_id], ['id' => $id]);
    }

    public function find(int $id): ?Category
    {
        $row = $this->raw($id);

        return $row !== null ? Category::from_row($row) : null;
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

    public function find_by_slug(string $slug): ?Category
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM %i WHERE slug = %s', $this->table(), $slug),
            ARRAY_A
        );

        return $row ? Category::from_row($row) : null;
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

        AuditLog::record('category.create', 'category', $id, $row['name'], ChangeSet::created($row));

        CatalogEvents::changed();

        return $id;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        global $wpdb;

        $before = $this->raw($id);

        $row = $this->prepare_data($data);

        // Fehlt shop_type_id (Formular ohne Shoptyp-Auswahl, z. B. ohne Pro), bleibt der gespeicherte Wert.
        if (!array_key_exists('shop_type_id', $data)) {
            unset($row['shop_type_id']);
        }

        $wpdb->update($this->table(), $row, ['id' => $id]);
        $this->audit_update($id, $before);

        // Name/Slug/Eltern-Kategorie stehen in Brotkrumen und Listen der Artikelseiten.
        CatalogEvents::changed();
    }

    public function set_active(int $id, bool $active): void
    {
        global $wpdb;

        $before = $this->raw($id);

        $wpdb->update($this->table(), ['active' => $active ? 1 : 0], ['id' => $id]);
        $this->audit_update($id, $before);

        CatalogEvents::changed();
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
            AuditLog::record('category.delete', 'category', $id, (string) $before['name'], ChangeSet::deleted($before, self::AUDIT_IGNORE));
        }

        // Untergeordnete Kategorien ruecken nach oben, Zuordnungen fallen weg.
        $wpdb->update($this->table(), ['parent_id' => null], ['parent_id' => $id]);
        $wpdb->delete($wpdb->prefix . 'kontor_shop_product_categories', ['category_id' => $id]);

        CatalogEvents::changed();
    }

    /**
     * @return array<int, int|null> id => parent_id
     */
    public function parent_map(): array
    {
        $map = [];

        foreach ($this->all() as $category) {
            $map[(int) $category->id] = $category->parent_id;
        }

        return $map;
    }

    /**
     * @param array<string, mixed>|null $before
     */
    private function audit_update(int $id, ?array $before): void
    {
        $after = $this->raw($id);

        if ($before !== null && $after !== null) {
            AuditLog::record_diff('category.update', 'category', $id, (string) $after['name'], $before, $after, self::AUDIT_IGNORE);
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
            'shop_type_id' => !empty($data['shop_type_id']) ? (int) $data['shop_type_id'] : null,
            'parent_id'    => !empty($data['parent_id']) ? (int) $data['parent_id'] : null,
            'name'         => (string) $data['name'],
            'slug'         => (string) $data['slug'],
            'accent_color' => $accent_color !== '' ? $accent_color : null,
            'sort_order'   => (int) ($data['sort_order'] ?? 0),
            'active'       => empty($data['active']) ? 0 : 1,
        ];
    }
}
