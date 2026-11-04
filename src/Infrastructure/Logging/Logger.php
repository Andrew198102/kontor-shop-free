<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Logging;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- eigene Tabellen des Plugins, kein WP-Objektcache-Bezug

use Kontor\Shop\Infrastructure\Audit\AuditLog;
use Kontor\Shop\Settings\Repository;

/**
 * Protokoll fuer unerwartete Fehler und wichtige Ereignisse. Schreibt in eine
 * eigene Tabelle (Einstellungen -> Protokoll im Menue "Kontor Shop") und bei
 * error/critical zusaetzlich ins PHP-Fehlerprotokoll des Servers.
 *
 * Grundsaetze:
 *  - Der Logger wirft nie selbst eine Exception - ein Logging-Problem darf
 *    einen Kauf nicht kaputt machen.
 *  - Keine personenbezogenen Daten im Protokoll (Namen, E-Mails, Adressen,
 *    Telefon, IBAN, Tokens werden aus dem Kontext entfernt); Bestellungen
 *    werden ueber ihre ID referenziert.
 *  - Unerwartete Fehler bekommen eine Referenznummer (KS-XXXXXX), die dem
 *    Kunden angezeigt wird und im Protokoll wiederzufinden ist.
 *  - critical loest zusaetzlich eine (gedrosselte) Warn-Mail an den
 *    Shop-Betreiber aus, damit ein verlorener Bestellversuch auffaellt.
 */
final class Logger
{
    /**
     * Praefix der Fehlernummern, die Kunden und Admins sehen. Bis 2.1.1 "KS-" -
     * zu leicht mit der Zahlungsreferenz (ebenfalls KS-…) zu verwechseln.
     */
    public const REFERENCE_PREFIX = 'ERR-';

    /** Fehlernummer neu (ERR-…) oder aus aelteren Versionen (KS-…). */
    public static function is_reference(string $value): bool
    {
        return preg_match('/^(ERR|KS)-[A-Z0-9]{4,16}$/', $value) === 1;
    }

    public const DEBUG    = 'debug';
    public const INFO     = 'info';
    public const WARNING  = 'warning';
    public const ERROR    = 'error';
    public const CRITICAL = 'critical';

    private const LEVELS = [self::DEBUG => 0, self::INFO => 1, self::WARNING => 2, self::ERROR => 3, self::CRITICAL => 4];

    private const ALERT_TRANSIENT = 'kontor_shop_log_alert';
    private const ALERT_INTERVAL  = 15 * MINUTE_IN_SECONDS;

    private const MAX_ROWS = 5000;

    /** Schluessel, deren Werte nie ins Protokoll gelangen (Teiltreffer im Namen genuegt). */
    private const SENSITIVE = ['email', 'mail', 'name', 'address', 'adresse', 'phone', 'telefon', 'iban', 'bic', 'token', 'password', 'passwort', 'nonce', 'secret', 'vat_id', 'city', 'street', 'postal'];

    private static ?Repository $settings = null;

    public static function boot(Repository $settings): void
    {
        self::$settings = $settings;
    }

    /**
     * @return array<int, string>
     */
    public static function levels(): array
    {
        return array_keys(self::LEVELS);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function log(string $level, string $channel, string $message, array $context = [], ?string $reference = null): void
    {
        try {
            if (!isset(self::LEVELS[$level]) || self::LEVELS[$level] < self::threshold()) {
                return;
            }

            $message = self::truncate($message, 500);
            $safe    = self::sanitize_context($context);

            if (self::LEVELS[$level] >= self::LEVELS[self::ERROR]) {
                error_log(sprintf('[Kontor Shop] %s/%s: %s %s', $level, $channel, $message, $reference !== null ? '(' . $reference . ')' : '')); // phpcs:ignore
            }

            global $wpdb;

            $wpdb->insert($wpdb->prefix . 'kontor_shop_log', [
                'created_at' => current_time('mysql', true),
                'level'      => $level,
                'channel'    => self::truncate($channel, 32),
                'reference'  => $reference,
                'message'    => $message,
                'context'    => $safe !== [] ? wp_json_encode($safe) : null,
                'user_id'    => get_current_user_id() ?: null,
                'url'        => self::truncate(esc_url_raw(wp_unslash((string) ($_SERVER['REQUEST_URI'] ?? ''))), 255),
            ]);

            if ($level === self::CRITICAL) {
                self::alert($message, (string) $reference);
            }
        } catch (\Throwable $e) {
            error_log('[Kontor Shop] Logger failed: ' . $e->getMessage()); // phpcs:ignore
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function debug(string $channel, string $message, array $context = []): void
    {
        self::log(self::DEBUG, $channel, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function info(string $channel, string $message, array $context = []): void
    {
        self::log(self::INFO, $channel, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function warning(string $channel, string $message, array $context = []): void
    {
        self::log(self::WARNING, $channel, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function error(string $channel, string $message, array $context = []): void
    {
        self::log(self::ERROR, $channel, $message, $context);
    }

    /**
     * Protokolliert eine Exception und liefert die Referenznummer fuer die
     * Kundenanzeige. Bei $critical (Bestellung verloren o. ae.) zusaetzlich
     * Warn-Mail an den Betreiber.
     *
     * @param array<string, mixed> $context
     */
    public static function exception(\Throwable $e, string $channel, string $message, array $context = [], bool $critical = false): string
    {
        $reference = self::new_reference();

        self::log($critical ? self::CRITICAL : self::ERROR, $channel, $message . ': ' . $e->getMessage(), array_merge($context, [
            'exception' => get_class($e),
            'file'      => basename($e->getFile()) . ':' . $e->getLine(),
            'trace'     => self::short_trace($e),
        ]), $reference);

        return $reference;
    }

    public static function new_reference(): string
    {
        try {
            return self::REFERENCE_PREFIX . strtoupper(bin2hex(random_bytes(3)));
        } catch (\Throwable $e) {
            return self::REFERENCE_PREFIX . strtoupper(substr(md5((string) microtime(true)), 0, 6));
        }
    }

    /**
     * Entfernt personenbezogene Werte und kuerzt alles auf eine vernuenftige Groesse.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function sanitize_context(array $context, int $depth = 0): array
    {
        $clean = [];

        foreach ($context as $key => $value) {
            $key_text = (string) $key;

            foreach (self::SENSITIVE as $needle) {
                if (stripos($key_text, $needle) !== false) {
                    $clean[$key_text] = '[entfernt]';

                    continue 2;
                }
            }

            if (is_array($value)) {
                $clean[$key_text] = $depth >= 2 ? '[…]' : self::sanitize_context($value, $depth + 1);
            } elseif (is_object($value)) {
                $clean[$key_text] = get_class($value);
            } elseif (is_string($value)) {
                $clean[$key_text] = self::truncate($value, 300);
            } elseif (is_scalar($value) || $value === null) {
                $clean[$key_text] = $value;
            } else {
                $clean[$key_text] = gettype($value);
            }
        }

        return $clean;
    }

    // ---------------------------------------------------------------- lesen / pflegen

    /**
     * @param array{level?: string, q?: string} $filters
     * @return array<int, array<string, mixed>>
     */
    public static function recent(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        global $wpdb;

        [$where, $args] = self::where($filters);

        $table = $wpdb->prefix . 'kontor_shop_log';
        $sql   = 'SELECT * FROM %i' . ($where !== '' ? ' WHERE ' . $where : '') . ' ORDER BY id DESC LIMIT %d OFFSET %d';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- WHERE aus where() (feste Fragmente), Werte via prepare()
        $rows = $wpdb->get_results($wpdb->prepare($sql, $table, ...array_merge($args, [$limit, $offset])), ARRAY_A);

        return $rows ?: [];
    }

    /**
     * Anzahl Eintraege ab einer Mindeststufe in den letzten $seconds Sekunden.
     */
    public static function count_since(string $min_level, int $seconds): int
    {
        global $wpdb;

        $levels = array_keys(array_filter(self::LEVELS, static fn (int $rank): bool => $rank >= (self::LEVELS[$min_level] ?? 0)));
        $table  = $wpdb->prefix . 'kontor_shop_log';

        return (int) $wpdb->get_var($wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IN-Liste per array_fill, Werte per Spread
            'SELECT COUNT(*) FROM %i WHERE created_at >= %s AND level IN (' . implode(',', array_fill(0, count($levels), '%s')) . ')',
            $table,
            gmdate('Y-m-d H:i:s', time() - $seconds),
            ...$levels
        ));
    }

    public static function clear(): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare('DELETE FROM %i', $wpdb->prefix . 'kontor_shop_log'));

        AuditLog::record('log.clear', 'log');
    }

    /**
     * Alte Eintraege entfernen (Aufbewahrungsdauer) und die Tabelle auf eine
     * feste Obergrenze begrenzen, damit ein Fehlersturm die Datenbank nicht fuellt.
     */
    public static function purge(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'kontor_shop_log';
        $days  = max(1, (int) (self::$settings?->get('logging.retention_days', 30) ?? 30));

        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE created_at < %s', $table, gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS)));

        $overflow = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table)) - self::MAX_ROWS;

        if ($overflow > 0) {
            $wpdb->query($wpdb->prepare('DELETE FROM %i ORDER BY id ASC LIMIT %d', $table, $overflow));
        }
    }

    public static function notice_html(): string
    {
        $count = self::count_since(self::ERROR, DAY_IN_SECONDS);

        if ($count === 0) {
            return '';
        }

        return '<div class="notice notice-error"><p><strong>' . esc_html__('Error log:', 'kontor-shop') . '</strong> '
            . esc_html(sprintf(
                /* translators: %d: number of errors */
                _n('%d error in the last 24 hours.', '%d errors in the last 24 hours.', $count, 'kontor-shop'),
                $count
            )) . ' <a href="' . esc_url(admin_url('admin.php?page=kontor-shop-log&level=error')) . '">'
            . esc_html__('View log', 'kontor-shop') . '</a></p></div>';
    }

    // ---------------------------------------------------------------- intern

    private static function threshold(): int
    {
        $level = (string) (self::$settings?->get('logging.level', self::WARNING) ?? self::WARNING);

        return self::LEVELS[$level] ?? self::LEVELS[self::WARNING];
    }

    private static function truncate(string $text, int $max): string
    {
        if (function_exists('mb_strlen') && mb_strlen($text) > $max) {
            return mb_substr($text, 0, $max - 1) . '…';
        }

        return strlen($text) > $max ? substr($text, 0, $max - 1) . '…' : $text;
    }

    private static function short_trace(\Throwable $e): string
    {
        $lines = [];

        foreach (array_slice($e->getTrace(), 0, 6) as $frame) {
            $lines[] = (isset($frame['class']) ? preg_replace('/^.*\\\\/', '', (string) $frame['class']) . '::' : '')
                . ($frame['function'] ?? '?') . (isset($frame['line']) ? ':' . $frame['line'] : '');
        }

        return implode(' < ', $lines);
    }

    /**
     * @param array{level?: string, q?: string} $filters
     * @return array{0: string, 1: array<int, mixed>}
     */
    private static function where(array $filters): array
    {
        global $wpdb;

        $clauses = [];
        $args    = [];

        $level = (string) ($filters['level'] ?? '');

        if (isset(self::LEVELS[$level])) {
            $levels    = array_keys(array_filter(self::LEVELS, static fn (int $rank): bool => $rank >= self::LEVELS[$level]));
            $clauses[] = 'level IN (' . implode(',', array_fill(0, count($levels), '%s')) . ')';
            $args      = array_merge($args, $levels);
        }

        $q = trim((string) ($filters['q'] ?? ''));

        if ($q !== '') {
            $like      = '%' . $wpdb->esc_like($q) . '%';
            $clauses[] = '(reference = %s OR message LIKE %s OR channel LIKE %s)';
            array_push($args, $q, $like, $like);
        }

        return [implode(' AND ', $clauses), $args];
    }

    /**
     * Gedrosselte Warn-Mail an den Betreiber bei kritischen Fehlern.
     */
    private static function alert(string $message, string $reference): void
    {
        if (get_transient(self::ALERT_TRANSIENT) !== false || !function_exists('wp_mail')) {
            return;
        }

        set_transient(self::ALERT_TRANSIENT, 1, self::ALERT_INTERVAL);

        $configured = (string) (self::$settings?->get('notifications.admin_notify_email', '') ?? '');
        $recipients = array_filter(array_map('trim', preg_split('/[,;\s]+/', $configured) ?: []), 'is_email');

        if ($recipients === []) {
            $recipients = array_filter([(string) get_option('admin_email')], 'is_email');
        }

        if ($recipients === []) {
            return;
        }

        wp_mail(
            array_values($recipients),
            sprintf('[%s] Kritischer Shop-Fehler %s', get_bloginfo('name'), $reference),
            "Im Webshop ist ein kritischer Fehler aufgetreten. Eine Bestellung ist moeglicherweise nicht zustande gekommen.\n\n"
                . "Referenz: {$reference}\nMeldung: {$message}\n\n"
                . 'Details: ' . admin_url('admin.php?page=kontor-shop-log&q=' . rawurlencode($reference)) . "\n\n"
                . 'Weitere Warnungen dieser Art werden fuer 15 Minuten unterdrueckt.'
        );
    }
}
