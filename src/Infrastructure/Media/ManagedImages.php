<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Media;

use Kontor\Shop\Settings\Repository;

/**
 * Verwaltung der ueber das Bildfeld hochgeladenen Bilder, damit die
 * Mediathek nicht mit verwaisten Dateien vollaeuft - ohne je etwas
 * ungefragt oder Fremdes zu loeschen:
 *  - Nur Bilder, die ueber Kontor Shop hochgeladen wurden (Meta "origin"),
 *    werden ueberhaupt zum Loeschen angeboten. Bilder, die jemand aus der
 *    Mediathek gewaehlt hat (Logo, Blogbilder ...), fasst das Plugin nie an.
 *  - Geloescht wird nur, wenn das Bild nirgends mehr verwendet wird
 *    (Artikel, Platzhalter-Einstellung, Beitragsbilder, Inhalte); die Pruefung
 *    ist bewusst konservativ - im Zweifel gilt ein Bild als verwendet.
 *  - "pending" markiert Bilder, die aktuell keinem Artikel zugeordnet sind und
 *    in der Aufraeumliste zur Entscheidung (loeschen/behalten) auftauchen.
 */
final class ManagedImages
{
    public const META_ORIGIN  = '_kontor_shop_origin';
    public const META_PENDING = '_kontor_shop_pending';

    /** Frisch hochgeladene Bilder erscheinen erst nach dieser Zeit in der Aufraeumliste (Formular evtl. noch offen). */
    public const GRACE_SECONDS = 600;

    public static function flag_upload(int $attachment_id): void
    {
        update_post_meta($attachment_id, self::META_ORIGIN, time());
        update_post_meta($attachment_id, self::META_PENDING, time());
        // Noch nirgends im Shop: der Dateiname darf beim ersten Zuordnen sauber werden (ProductImageNames).
        update_post_meta($attachment_id, ProductImageNames::META_RENAME, 1);
    }

    public static function is_managed(int $attachment_id): bool
    {
        return $attachment_id > 0 && get_post_meta($attachment_id, self::META_ORIGIN, true) !== '';
    }

    /**
     * Das Bild wurde einem Artikel/einer Einstellung zugeordnet: nicht mehr "offen".
     */
    public static function claim(?int $attachment_id): void
    {
        if ($attachment_id !== null && $attachment_id > 0) {
            delete_post_meta($attachment_id, self::META_PENDING);
        }
    }

    /**
     * Ein Bild wurde von einem Artikel geloest (ersetzt/entfernt/Artikel geloescht).
     *
     * @return bool true, wenn es jetzt zur Entscheidung ansteht (verwaltet und nirgends sonst genutzt)
     */
    public static function release(?int $attachment_id, Repository $settings, ?int $except_product_id): bool
    {
        if ($attachment_id === null || !self::is_managed($attachment_id)) {
            return false;
        }

        if (self::is_used($attachment_id, $settings, $except_product_id)) {
            return false;
        }

        update_post_meta($attachment_id, self::META_PENDING, time());

        return true;
    }

    /**
     * Endgueltig aus der Mediathek loeschen - nur verwaltete, ungenutzte Bilder.
     */
    public static function delete(int $attachment_id, Repository $settings): bool
    {
        if (!self::is_managed($attachment_id) || self::is_used($attachment_id, $settings, null)) {
            return false;
        }

        return wp_delete_attachment($attachment_id, true) !== false;
    }

    /**
     * Entscheidung "behalten": das Bild bleibt, wird aber nicht mehr angemahnt.
     */
    public static function keep(int $attachment_id): void
    {
        delete_post_meta($attachment_id, self::META_PENDING);
    }

    /**
     * Wird das Bild irgendwo verwendet? Konservativ: bei Unsicherheit "ja".
     */
    public static function is_used(int $attachment_id, Repository $settings, ?int $except_product_id): bool
    {
        global $wpdb;

        if ($attachment_id <= 0) {
            return false;
        }

        if (absint($settings->get('design.placeholder_image_id', 0)) === $attachment_id) {
            return true;
        }

        $products_table = $wpdb->prefix . 'kontor_shop_products';
        $on_products    = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE image_id = %d AND id <> %d',
            $products_table,
            $attachment_id,
            (int) $except_product_id
        ));

        if ($on_products > 0) {
            return true;
        }

        // Galerie und Variantenbilder (anderer Artikel - beim eigenen entscheidet der Aufrufer).
        $prefix    = $wpdb->prefix . 'kontor_shop_';
        $elsewhere = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT (SELECT COUNT(*) FROM %i WHERE attachment_id = %d AND product_id <> %d)'
            . ' + (SELECT COUNT(*) FROM %i WHERE image_id = %d AND product_id <> %d)',
            $prefix . 'product_images',
            $attachment_id,
            (int) $except_product_id,
            $prefix . 'product_variants',
            $attachment_id,
            (int) $except_product_id
        ));

        if ($elsewhere > 0) {
            return true;
        }

        $as_thumbnail = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d",
            $attachment_id
        ));

        if ($as_thumbnail > 0) {
            return true;
        }

        $file    = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
        $clauses = [];
        $args    = [];

        foreach (self::content_patterns($attachment_id, $file) as $pattern) {
            $clauses[] = 'post_content LIKE %s';
            $args[]    = '%' . $wpdb->esc_like($pattern) . '%';
        }

        // $clauses besteht nur aus festen "post_content LIKE %s"-Bausteinen, die Werte gehen an prepare().
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- nur feste Bausteine mit Platzhaltern
        $in_content = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type NOT IN ('attachment', 'revision') "
            . "AND post_status NOT IN ('trash', 'auto-draft') AND (" . implode(' OR ', $clauses) . ')',
            ...$args
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

        return $in_content > 0;
    }

    /**
     * Suchmuster, an denen man die Verwendung eines Bildes in Beitragsinhalten
     * erkennt (Klassischer Editor: wp-image-ID, Bloecke: "id":ID, sowie der
     * Dateiname ohne Endung fuer direkt eingebettete URLs).
     *
     * @return array<int, string>
     */
    public static function content_patterns(int $attachment_id, string $attached_file): array
    {
        $patterns = [
            'wp-image-' . $attachment_id . '"',
            'wp-image-' . $attachment_id . ' ',
            '"id":' . $attachment_id . ',',
            '"id":' . $attachment_id . '}',
            '"id":' . $attachment_id . ']',
        ];

        $base = pathinfo($attached_file, PATHINFO_FILENAME);

        if ($base !== '') {
            $patterns[] = $base;
        }

        return $patterns;
    }

    /**
     * Aufraeumliste: verwaltete, aktuell keinem Artikel zugeordnete und
     * ungenutzte Bilder, aelter als die Schonfrist.
     *
     * @return array<int, int> Attachment-IDs
     */
    public static function pending_unused(Repository $settings): array
    {
        $ids = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 100,
            'fields'         => 'ids',
            'meta_key'       => self::META_PENDING,
            'orderby'        => 'meta_value_num',
            'order'          => 'ASC',
        ]);

        $result = [];

        foreach ($ids as $id) {
            $id      = (int) $id;
            $pending = (int) get_post_meta($id, self::META_PENDING, true);

            if ($pending > time() - self::GRACE_SECONDS) {
                continue;
            }

            if (self::is_used($id, $settings, null)) {
                // Inzwischen doch verwendet (z. B. anderswo eingebunden): nicht mehr anmahnen.
                delete_post_meta($id, self::META_PENDING);

                continue;
            }

            $result[] = $id;
        }

        return $result;
    }
}
