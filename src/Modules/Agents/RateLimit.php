<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Agents;

/**
 * Einfaches Mengenlimit je Absender und Zeitfenster (Transient). Grosszuegig
 * bemessen: Die Assistenten von ChatGPT und Claude rufen aus wenigen
 * Rechenzentren an, viele Kunden teilen sich also eine Absender-IP.
 */
final class RateLimit
{
    public static function hit(string $bucket, string $who, int $limit, int $window_seconds): bool
    {
        $key  = 'kontor_shop_rl_' . md5($bucket . '|' . $who);
        $now  = time();
        $data = get_transient($key);

        if (!is_array($data) || (int) ($data['until'] ?? 0) <= $now) {
            $data = ['count' => 0, 'until' => $now + $window_seconds];
        }

        $data['count'] = (int) $data['count'] + 1;

        set_transient($key, $data, max(1, (int) $data['until'] - $now));

        return $data['count'] <= $limit;
    }

    public static function client_ip(): string
    {
        return sanitize_text_field(wp_unslash((string) ($_SERVER['REMOTE_ADDR'] ?? '')));
    }
}
