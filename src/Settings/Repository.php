<?php

declare(strict_types=1);

namespace Kontor\Shop\Settings;

use Kontor\Shop\Infrastructure\Audit\AuditLog;
use Kontor\Shop\Support\Arr;

/**
 * Lese-/Schreibzugriff auf die Plugin-Einstellungen.
 *
 * Ebenen (spaetere gewinnt):
 *   1. eingebaute Defaults (Schema::defaults())
 *   2. optionale Projekt-Konfigurationsdatei kontor-shop.config.php
 *   3. in wp_options gespeicherte Werte (Admin-UI, spaetere Phase)
 *
 * Zugriff per Punktnotation: $repository->get('general.shop_name').
 */
final class Repository
{
    public const OPTION_KEY = 'kontor_shop_settings';

    public const CONFIG_FILENAME = 'kontor-shop.config.php';

    /** @var array<string, mixed>|null */
    private ?array $resolved = null;

    /**
     * Vollstaendiger, aufgeloester Einstellungsbaum.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->resolved === null) {
            $stored = get_option(self::OPTION_KEY, []);
            $stored = is_array($stored) ? $stored : [];

            $base = Arr::deep_merge(Schema::defaults(), self::config_file_values());
            $tree = Arr::deep_merge($base, $stored);

            /** @var array<string, mixed> $tree */
            $this->resolved = apply_filters('kontor_shop/settings', $tree);
        }

        return $this->resolved;
    }

    /**
     * @param string $path Punktnotation, z. B. "general.shop_name"
     */
    public function get(string $path, mixed $default = null): mixed
    {
        $value = Arr::get($this->all(), $path, $default);

        return apply_filters('kontor_shop/setting', $value, $path, $default);
    }

    /**
     * Speichert einen (vollstaendigen oder teilweisen) Einstellungsbaum.
     * Geaenderte Werte landen im Aenderungsprotokoll (Geheimnisse nur als
     * "geaendert", siehe Domain\Audit\ChangeSet).
     *
     * @param array<string, mixed> $values
     */
    public function save(array $values): void
    {
        $stored = get_option(self::OPTION_KEY, []);
        $stored = is_array($stored) ? $stored : [];
        $before = $this->all();

        $merged = Arr::deep_merge($stored, $values);

        update_option(self::OPTION_KEY, $merged, true);
        $this->resolved = null;

        // Aufgeloeste Staende vergleichen: ein Wert, der nur dem Default entspricht, ist keine Aenderung.
        AuditLog::record_diff('settings.update', 'settings', null, null, $before, $this->all());
    }

    public function flush_cache(): void
    {
        $this->resolved = null;
    }

    /**
     * Baut den Default-Baum, gemischt mit bereits gespeicherten Werten
     * (fuer Lifecycle::install_or_upgrade).
     *
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    public static function merge_defaults(array $existing): array
    {
        return Arr::deep_merge(Schema::defaults(), $existing);
    }

    /**
     * Sucht eine Projekt-Konfigurationsdatei und liefert deren Array zurueck.
     * Reihenfolge: Child-Theme, Parent-Theme, mu-plugins.
     *
     * @return array<string, mixed>
     */
    private static function config_file_values(): array
    {
        $candidates = [];

        if (function_exists('get_stylesheet_directory')) {
            $candidates[] = trailingslashit(get_stylesheet_directory()) . self::CONFIG_FILENAME;
            $candidates[] = trailingslashit(get_template_directory()) . self::CONFIG_FILENAME;
        }

        if (defined('WPMU_PLUGIN_DIR')) {
            $candidates[] = trailingslashit(WPMU_PLUGIN_DIR) . self::CONFIG_FILENAME;
        }

        $candidates = apply_filters('kontor_shop/settings/config_paths', $candidates);

        foreach ($candidates as $path) {
            if (is_string($path) && is_readable($path)) {
                $data = include $path;

                if (is_array($data)) {
                    return $data;
                }
            }
        }

        return [];
    }
}
