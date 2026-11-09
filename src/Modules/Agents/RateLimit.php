<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Agents;

/**
 * Einfaches Mengenlimit je Absender und festem Zeitfenster. Grosszuegig
 * bemessen: Die Assistenten von ChatGPT und Claude rufen aus wenigen
 * Rechenzentren an, viele Kunden teilen sich also eine Absender-IP.
 *
 * Laeuft bei jeder MCP-Anfrage, deshalb so billig wie moeglich: Mit
 * Objekt-Cache (Redis/Memcached) zaehlt der Cache, ohne Datenbank. Sonst
 * ein Transient je Zeitfenster - angelegt einmal pro Fenster, danach ein
 * einziger Schreibzugriff pro Anfrage (nur der Zaehler, die Ablaufzeit
 * bleibt).
 */
final class RateLimit
{
    private const GROUP = 'kontor_shop_rl';

    public static function hit(string $bucket, string $who, int $limit, int $window_seconds): bool
    {
        $window_seconds = max(1, $window_seconds);
        $now            = time();
        $window         = intdiv($now, $window_seconds);
        $key            = 'kontor_shop_rl_' . md5($bucket . '|' . $who . '|' . $window);
        $remaining      = ($window + 1) * $window_seconds - $now;

        if (wp_using_ext_object_cache()) {
            wp_cache_add($key, 0, self::GROUP, $remaining + 1);
            $count = wp_cache_incr($key, 1, self::GROUP);

            // Cache nicht erreichbar: lieber durchlassen als den Zugang sperren.
            return $count === false || $count <= $limit;
        }

        $count = (int) get_transient($key) + 1;

        if ($count === 1) {
            set_transient($key, 1, $remaining + 1);
        } else {
            // Nur den Wert schreiben (so speichert WordPress Transients ohne Objekt-Cache); set_transient() wuerde jedes Mal auch die Ablaufzeit neu schreiben.
            update_option('_transient_' . $key, $count, false);
        }

        return $count <= $limit;
    }

    public static function client_ip(): string
    {
        return sanitize_text_field(wp_unslash((string) ($_SERVER['REMOTE_ADDR'] ?? '')));
    }
}
