<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Invoices;

/**
 * Ablage der PDF-Rechnungen unter uploads/kontor-shop/invoices/<Jahr>/.
 * Direkter Abruf ist gesperrt (.htaccess fuer Apache, index.php gegen
 * Verzeichnislisten, zufaelliger Dateiname fuer Server ohne .htaccess wie
 * nginx); ausgeliefert wird nur ueber den geprueften Download des Plugins.
 * Gespeicherte Rechnungen werden nie ueberschrieben (Aufbewahrung).
 */
final class InvoiceStorage
{
    private const DIR = 'kontor-shop/invoices';

    public static function base_dir(): string
    {
        $uploads = wp_upload_dir(null, false);

        return trailingslashit((string) $uploads['basedir']) . self::DIR;
    }

    /**
     * Speichert das PDF und liefert den Pfad relativ zum Ablage-Ordner ('' bei Fehler).
     */
    public static function save(string $number, string $pdf): string
    {
        $year = gmdate('Y');
        $dir  = self::base_dir() . '/' . $year;

        if (!self::ensure_dir(self::base_dir()) || !self::ensure_dir($dir)) {
            return '';
        }

        $name = sanitize_file_name($number) . '-' . wp_generate_password(12, false) . '.pdf';

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- eigene, gesperrte Ablage im Upload-Ordner
        return file_put_contents($dir . '/' . $name, $pdf) !== false ? $year . '/' . $name : '';
    }

    /** Absoluter Pfad zu einer gespeicherten Datei oder '' (ungueltig/fehlt). */
    public static function path(string $relative): string
    {
        if ($relative === '' || !preg_match('#^\d{4}/[A-Za-z0-9._-]+\.pdf$#', $relative)) {
            return '';
        }

        $path = self::base_dir() . '/' . $relative;

        return is_readable($path) ? $path : '';
    }

    /** Beim Deinstallieren mit "alle Daten entfernen". */
    public static function purge(): void
    {
        $base = self::base_dir();

        if (!is_dir($base)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($items as $item) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- nur die eigene Ablage beim Deinstallieren
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- nur die eigene Ablage beim Deinstallieren
        rmdir($base);
    }

    private static function ensure_dir(string $dir): bool
    {
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return false;
        }

        foreach (['.htaccess' => "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n", 'index.php' => "<?php\n// Silence is golden.\n"] as $file => $content) {
            if (!file_exists($dir . '/' . $file)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Schutzdateien der eigenen Ablage
                file_put_contents($dir . '/' . $file, $content);
            }
        }

        return true;
    }
}
