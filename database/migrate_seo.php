<?php

declare(strict_types=1);

namespace Kontor\Shop\Database;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- eigene Tabellen des Plugins, kein WP-Objektcache-Bezug

use Kontor\Shop\Domain\Seo\Slug;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Schema 10 (SEO/KI-Anbindung, Plan 5.4): Artikel bekommen einen stabilen,
 * eindeutigen Slug, content_updated_at, Marke/GTIN/Bild-Alt; Kategorien eine
 * Hierarchie (parent_id) und die n:m-Zuordnung product_categories.
 *
 * Zwei Schritte, weil dbDelta den UNIQUE-Index auf products.slug nicht auf
 * lauter leere Werte legen koennte:
 *
 *   prepare_seo_slugs()  VOR migrate_v1(): Spalte anlegen und alle Slugs vergeben
 *   backfill_seo()       NACH migrate_v1(): content_updated_at, product_categories
 *
 * Beides ist idempotent und schnell, wenn nichts zu tun ist.
 */
function prepare_seo_slugs(): void
{
    global $wpdb;

    $table = $wpdb->prefix . 'kontor_shop_products';

    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        return; // frische Installation: migrate_v1() legt alles komplett an
    }

    if ($wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM %i LIKE %s', $table, 'slug')) === null) {
        $wpdb->query($wpdb->prepare("ALTER TABLE %i ADD COLUMN slug VARCHAR(190) NOT NULL DEFAULT '' AFTER name", $table));
    }

    $rows = $wpdb->get_results($wpdb->prepare('SELECT id, name, slug FROM %i ORDER BY id ASC', $table), ARRAY_A) ?: [];
    $used = [];

    foreach ($rows as $row) {
        if ((string) $row['slug'] !== '') {
            $used[(string) $row['slug']] = true;
        }
    }

    foreach ($rows as $row) {
        if ((string) $row['slug'] !== '') {
            continue;
        }

        $slug = Slug::unique(Slug::make((string) $row['name']), static fn (string $candidate): bool => isset($used[$candidate]));

        $used[$slug] = true;
        $wpdb->update($table, ['slug' => $slug], ['id' => (int) $row['id']]);
    }
}

function backfill_seo(): void
{
    global $wpdb;

    $p = $wpdb->prefix . 'kontor_shop_';

    // Bisheriger Aenderungsstand als Startwert - nie "jetzt", sonst
    // springt lastmod aller Artikel auf den Migrationszeitpunkt.
    $wpdb->query($wpdb->prepare('UPDATE %i SET content_updated_at = COALESCE(updated_at, created_at) WHERE content_updated_at IS NULL', $p . 'products'));

    // Die bisherige Einzelkategorie ist die Hauptkategorie und steht auch in der n:m-Tabelle.
    $wpdb->query($wpdb->prepare(
        'INSERT IGNORE INTO %i (product_id, category_id)
         SELECT id, category_id FROM %i WHERE category_id IS NOT NULL',
        $p . 'product_categories',
        $p . 'products'
    ));
}
