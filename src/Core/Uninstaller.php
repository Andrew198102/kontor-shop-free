<?php

declare(strict_types=1);

namespace Kontor\Shop\Core;

use Kontor\Shop\Infrastructure\Checkout\ReservationCleanup;
use Kontor\Shop\Infrastructure\Media\ManagedImages;
use Kontor\Shop\Settings\Repository;

/**
 * Was beim Loeschen des Plugins (uninstall.php) passiert. Zwei Modi, vorher
 * waehlbar (Einstellungen → System oder direkt am "Löschen"-Link in der
 * Plugin-Liste):
 *
 *  - keep  (Standard): nur das Plugin geht. Artikel, Bestellungen, Lager,
 *          Einstellungen, Seiten und Bilder bleiben - eine Neuinstallation
 *          macht nahtlos weiter. Entfernt werden nur Zwischenspeicher und
 *          der Cron-Job.
 *  - purge: alle Spuren - Tabellen, Optionen, Zwischenspeicher, Benutzer-
 *          und Bild-Metadaten, Rolle/Capabilities, Cron, Rewrite-Regeln, die
 *          vom Plugin angelegten Seiten (nur solange sie noch einen
 *          Shop-Shortcode enthalten) und die ueber den Shop hochgeladenen
 *          Bilder (nur wenn sie nirgends sonst verwendet werden).
 *
 * Die Konstante KONTOR_SHOP_UNINSTALL_PURGE_ALL (wp-config.php) erzwingt
 * weiterhin "purge". Bei Multisite wird jede Seite des Netzwerks bereinigt.
 */
final class Uninstaller
{
    public const OPTION_MODE = 'kontor_shop_uninstall_mode';
    public const MODE_KEEP   = 'keep';
    public const MODE_PURGE  = 'purge';

    /** Tabellen (ohne Praefix), Kind- vor Elterntabellen. */
    public const TABLES = [
        'mail_outbox', 'api_requests', 'coupon_uses', 'coupons', 'stock_requests', 'audit', 'shipments', 'order_status_log', 'order_items', 'orders',
        'stock_reservations', 'stock_movements', 'product_categories', 'product_variants', 'product_images',
        'product_tombstones', 'products', 'categories', 'shop_types', 'log', 'warehouses',
    ];

    public const CAPABILITIES = [
        'manage_kontor_shop', 'kontor_shop_cashier', 'kontor_shop_warehouse_manager', 'kontor_shop_access', 'kontor_shop_settings',
    ];

    public const ROLE = 'kontor_shop_manager';

    /** Seiten-IDs der Auto-Seiten in den Einstellungen (general.*). */
    private const PAGE_KEYS = ['shop_page_id', 'cart_page_id', 'checkout_page_id'];

    public static function mode(): string
    {
        if (defined('KONTOR_SHOP_UNINSTALL_PURGE_ALL') && KONTOR_SHOP_UNINSTALL_PURGE_ALL === true) {
            return self::MODE_PURGE;
        }

        return get_option(self::OPTION_MODE, self::MODE_KEEP) === self::MODE_PURGE ? self::MODE_PURGE : self::MODE_KEEP;
    }

    public static function set_mode(string $mode): void
    {
        update_option(self::OPTION_MODE, $mode === self::MODE_PURGE ? self::MODE_PURGE : self::MODE_KEEP, false);
    }

    public static function forced_by_constant(): bool
    {
        return defined('KONTOR_SHOP_UNINSTALL_PURGE_ALL') && KONTOR_SHOP_UNINSTALL_PURGE_ALL === true;
    }

    /**
     * Einstieg beim Deinstallieren (Freemius-Hook after_uninstall, src/freemius.php). Der Modus gilt je Seite (Multisite).
     */
    public static function run(): void
    {
        if (!is_multisite()) {
            self::run_site();

            return;
        }

        foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $site_id) {
            switch_to_blog((int) $site_id);
            self::run_site();
            restore_current_blog();
        }

        delete_site_option(Repository::OPTION_KEY);
    }

    private static function run_site(): void
    {
        wp_clear_scheduled_hook(ReservationCleanup::HOOK);
        self::delete_transients();

        if (self::mode() !== self::MODE_PURGE) {
            return;
        }

        // Reihenfolge: erst lesen, was in Tabellen/Optionen steht (Seiten, Bilder), dann loeschen.
        $pages = self::plugin_pages();

        self::delete_managed_images($pages);
        self::delete_pages($pages);
        self::drop_tables();
        self::delete_options();
        self::delete_meta();
        self::remove_capabilities();

        // WordPress baut die Regeln beim naechsten Aufruf ohne die Shop-Routen neu auf.
        delete_option('rewrite_rules');
    }

    /**
     * Vom Plugin angelegte Seiten: Shop/Warenkorb/Kasse und Shoptyp-Seiten.
     *
     * @return array<int, int>
     */
    private static function plugin_pages(): array
    {
        global $wpdb;

        $settings = get_option(Repository::OPTION_KEY, []);
        $general  = is_array($settings) && isset($settings['general']) && is_array($settings['general']) ? $settings['general'] : [];
        $ids      = [];

        foreach (self::PAGE_KEYS as $key) {
            $ids[] = (int) ($general[$key] ?? 0);
        }

        $types = $wpdb->prefix . 'kontor_shop_shop_types';

        if (self::table_exists($types)) {
            $ids = array_merge($ids, array_map('intval', (array) $wpdb->get_col($wpdb->prepare('SELECT page_id FROM %i WHERE page_id IS NOT NULL', $types))));
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * Nur loeschen, solange die Seite noch ein Shop-Shortcode ist - wer sie
     * inzwischen fuer etwas anderes verwendet, behaelt sie.
     *
     * @param array<int, int> $page_ids
     */
    private static function delete_pages(array $page_ids): void
    {
        foreach ($page_ids as $id) {
            $post = get_post($id);

            if ($post !== null && $post->post_type === 'page' && str_contains((string) $post->post_content, '[kontor_shop')) {
                wp_delete_post($id, true);
            }
        }
    }

    /**
     * Ueber den Shop hochgeladene Bilder: loeschen, wenn sie sonst nirgends
     * verwendet werden (Beitragsbild, Inhalt, Logo/Icon); sonst nur die
     * Shop-Markierung entfernen.
     *
     * @param array<int, int> $deleted_pages Seiten, die gleich mitgeloescht werden (zaehlen nicht als Verwendung)
     */
    private static function delete_managed_images(array $deleted_pages): void
    {
        global $wpdb;

        $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
            ManagedImages::META_ORIGIN
        )));

        foreach (array_unique($ids) as $id) {
            if (get_post_type($id) === 'attachment' && !self::image_used_elsewhere($id, $deleted_pages)) {
                wp_delete_attachment($id, true);
            }
        }
    }

    /**
     * @param array<int, int> $ignore_posts
     */
    private static function image_used_elsewhere(int $id, array $ignore_posts): bool
    {
        global $wpdb;

        if ((int) get_option('site_icon') === $id || (int) get_theme_mod('custom_logo') === $id) {
            return true;
        }

        // Ausgenommene Seiten nur als %d-Platzhalter, die Werte gehen an prepare().
        $ignore_ids = array_map('intval', $ignore_posts);
        $not_in     = $ignore_ids !== [] ? implode(',', array_fill(0, count($ignore_ids), '%d')) : '';

        $thumb_sql = "SELECT COUNT(*) FROM %i WHERE meta_key = '_thumbnail_id' AND meta_value = %s" . ($not_in !== '' ? ' AND post_id NOT IN (' . $not_in . ')' : '');

        if ((int) $wpdb->get_var($wpdb->prepare($thumb_sql, $wpdb->postmeta, (string) $id, ...$ignore_ids)) > 0) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- nur Platzhalter, Werte ueber prepare()
            return true;
        }

        $file     = (string) get_post_meta($id, '_wp_attached_file', true);
        $basename = $file !== '' ? pathinfo($file, PATHINFO_FILENAME) : '';
        $like     = '%' . $wpdb->esc_like('wp-image-' . $id) . '%';

        $sql = "SELECT COUNT(*) FROM %i WHERE post_type NOT IN ('attachment', 'revision') AND (post_content LIKE %s"
            . ($basename !== '' ? ' OR post_content LIKE %s' : '') . ')'
            . ($not_in !== '' ? ' AND ID NOT IN (' . $not_in . ')' : '');
        $params = array_merge(
            [$wpdb->posts, $like],
            $basename !== '' ? ['%' . $wpdb->esc_like($basename) . '%'] : [],
            $ignore_ids
        );

        return (int) $wpdb->get_var($wpdb->prepare($sql, ...$params)) > 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- nur Platzhalter, Werte ueber prepare()
    }

    private static function drop_tables(): void
    {
        global $wpdb;

        $prefix = $wpdb->prefix . 'kontor_shop_';
        $tables = array_map(static fn (string $t): string => $prefix . $t, self::TABLES);

        // Auch Tabellen, die eine kuenftige/fruehere Version angelegt hat.
        foreach ((array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($prefix) . '%')) as $table) {
            if (!in_array($table, $tables, true)) {
                $tables[] = (string) $table;
            }
        }

        foreach ($tables as $table) {
            $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $table));
        }
    }

    /**
     * Alle Optionen kontor_shop_* (Einstellungen, Schema, API-Zugaenge,
     * Audit-Kette, Alarm, Mailfehler ...).
     */
    private static function delete_options(): void
    {
        global $wpdb;

        $names = (array) $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like('kontor_shop_') . '%'
        ));

        foreach ($names as $name) {
            delete_option((string) $name);
        }
    }

    /**
     * Zwischenspeicher (Mitgliedsstatus, Rate-Limits, Flash-Meldungen,
     * API-Token-Anzeige, Alarm-Drossel ...). Auch im Modus "keep": das sind
     * keine Daten.
     */
    private static function delete_transients(): void
    {
        global $wpdb;

        foreach (['_transient_', '_transient_timeout_', '_site_transient_', '_site_transient_timeout_'] as $prefix) {
            $names = (array) $wpdb->get_col($wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like($prefix . 'kontor_shop_') . '%'
            ));

            foreach ($names as $name) {
                delete_option((string) $name);
            }
        }
    }

    /**
     * Benutzer-Metadaten (zuletzt gewaehlter Versanddienst, Zeilen pro
     * Seite) und Shop-Markierungen an verbliebenen Bildern.
     */
    private static function delete_meta(): void
    {
        global $wpdb;

        $user_keys = (array) $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
            $wpdb->esc_like('kontor_shop_') . '%'
        ));

        foreach ($user_keys as $key) {
            delete_metadata('user', 0, (string) $key, '', true);
        }

        foreach ([ManagedImages::META_ORIGIN, ManagedImages::META_PENDING] as $key) {
            delete_metadata('post', 0, $key, '', true);
        }
    }

    /**
     * Rolle entfernen und Capabilities von der Administrator-Rolle und von
     * jedem Benutzer, dem Core\Capabilities::grant() sie direkt gegeben hat.
     */
    private static function remove_capabilities(): void
    {
        remove_role(self::ROLE);

        $administrator = get_role('administrator');

        if ($administrator) {
            foreach (self::CAPABILITIES as $capability) {
                $administrator->remove_cap($capability);
            }
        }

        foreach (get_users(['fields' => ['ID']]) as $user) {
            $wp_user = new \WP_User($user->ID);

            foreach (self::CAPABILITIES as $capability) {
                if (isset($wp_user->caps[$capability])) {
                    $wp_user->remove_cap($capability);
                }
            }
        }
    }

    private static function table_exists(string $table): bool
    {
        global $wpdb;

        return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
    }
}
