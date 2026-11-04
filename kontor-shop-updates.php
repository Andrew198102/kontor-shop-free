<?php

declare(strict_types=1);

/**
 * Automatische Updates der Free-Version aus github.com/Andrew198102/kontor-shop-free.
 *
 * Steckt in jedem Paket. Aktiv ist sie nur in der Free-Version im Ordner kontor-shop:
 * Pro (Ordner kontor-shop-premium) bekommt Updates von Freemius, ein Test-Build
 * (kontor-shop-test-build.php) und die Entwicklungs-Kopie (mit tests/) werden nie
 * ueberschrieben. Die Kopie fuer wordpress.org bekommt die Datei beim Hochladen
 * nicht (Release-Workflow) - dort kommen die Updates von wordpress.org (Regel 8).
 *
 * WordPress fragt etwa alle 12 Stunden nach Updates; dann steht hier die Version aus
 * kontor-shop.php im Repository (6 Stunden gemerkt, "Erneut pruefen" unter
 * Dashboard → Aktualisierungen umgeht das). Mit "Automatische Aktualisierungen
 * aktivieren" in der Plugin-Liste spielt WordPress neue Versionen selbst ein.
 * Paket ist das ZIP des Tags v<Version>; dessen Ordner heisst kontor-shop-free-<Version>
 * und wird beim Entpacken in kontor-shop umbenannt.
 */

if (!defined('ABSPATH') || !defined('KONTOR_SHOP_BASENAME')
    || dirname(KONTOR_SHOP_BASENAME) !== 'kontor-shop'
    || is_readable(KONTOR_SHOP_PATH . 'kontor-shop-test-build.php')
    || is_dir(KONTOR_SHOP_PATH . 'tests')
    || (function_exists('kontor_shop_fs') && kontor_shop_fs()->is_premium())) {
    return;
}

final class Kontor_Shop_Free_Updates
{
    public const REPOSITORY = 'Andrew198102/kontor-shop-free';

    private const CACHE_KEY = 'kontor_shop_free_update';
    private const CHECK_KEY = 'kontor_shop_free_update_check';
    private const CHECK_NOW = 'kontor_shop_update_check';
    private const CACHE_TTL = 6 * HOUR_IN_SECONDS;
    private const ERROR_TTL = HOUR_IN_SECONDS;

    public static function register(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'offer']);
        add_filter('plugins_api', [self::class, 'details'], 10, 3);
        add_filter('upgrader_source_selection', [self::class, 'rename_folder'], 10, 4);
        add_filter('plugin_row_meta', [self::class, 'row_meta'], 10, 2);
        add_action('load-plugins.php', [self::class, 'check_now']);
    }

    /**
     * Update anbieten - oder "aktuell" melden, damit WordPress den Schalter
     * "Automatische Aktualisierungen" in der Plugin-Liste zeigt.
     */
    public static function offer(mixed $transient): mixed
    {
        if (!is_object($transient)) {
            return $transient;
        }

        $remote = self::remote_version();
        $item   = (object) [
            'id'           => 'github.com/' . self::REPOSITORY,
            'slug'         => 'kontor-shop',
            'plugin'       => KONTOR_SHOP_BASENAME,
            'new_version'  => $remote ?? KONTOR_SHOP_VERSION,
            'url'          => 'https://github.com/' . self::REPOSITORY,
            'package'      => $remote !== null ? self::package($remote) : '',
            'icons'        => ['1x' => KONTOR_SHOP_URL . 'assets/admin/plugin-icon.png'],
            'requires_php' => '8.1',
        ];

        if ($remote !== null && version_compare($remote, KONTOR_SHOP_VERSION, '>')) {
            $transient->response               = (array) ($transient->response ?? []);
            $transient->response[KONTOR_SHOP_BASENAME] = $item;
            unset($transient->no_update[KONTOR_SHOP_BASENAME]);
        } else {
            $transient->no_update              = (array) ($transient->no_update ?? []);
            $transient->no_update[KONTOR_SHOP_BASENAME] = $item;
            unset($transient->response[KONTOR_SHOP_BASENAME]);
        }

        return $transient;
    }

    /** "Details anzeigen" in der Plugin-Liste (statt "nicht gefunden" bei wordpress.org). */
    public static function details(mixed $result, string $action, object $args): mixed
    {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== 'kontor-shop') {
            return $result;
        }

        $version = self::remote_version() ?? KONTOR_SHOP_VERSION;

        return (object) [
            'name'          => 'Kontor Shop',
            'slug'          => 'kontor-shop',
            'version'       => $version,
            'author'        => '<a href="https://veyra-assistant.com/">Veyra</a>',
            'homepage'      => 'https://veyra-assistant.com/kontor-shop/',
            'requires'      => '6.4',
            'requires_php'  => '8.1',
            'download_link' => self::package($version),
            'sections'      => [
                'description' => esc_html__('Club shop for WordPress: products with variants, stock, bank-transfer checkout, pickup and a cashier workflow.', 'kontor-shop'),
                'changelog'   => '<p><a href="https://github.com/' . self::REPOSITORY . '/blob/main/readme.txt">github.com/' . self::REPOSITORY . '</a></p>',
            ],
        ];
    }

    /**
     * Das Tag-ZIP entpackt nach "kontor-shop-free-<Version>" - WordPress wuerde das
     * Plugin in diesen neuen Ordner legen und es dabei abschalten. Daher umbenennen.
     *
     * @param array<string, mixed> $hook_extra
     */
    public static function rename_folder(mixed $source, string $remote_source, mixed $upgrader, array $hook_extra = []): mixed
    {
        global $wp_filesystem;

        if (!is_string($source) || ($hook_extra['plugin'] ?? '') !== KONTOR_SHOP_BASENAME || !str_contains(basename($source), 'kontor-shop-free')) {
            return $source;
        }

        $target = trailingslashit($remote_source) . 'kontor-shop/';

        return $wp_filesystem && $wp_filesystem->move($source, $target, true) ? $target : $source;
    }

    public static function package(string $version): string
    {
        return 'https://codeload.github.com/' . self::REPOSITORY . '/zip/refs/tags/v' . rawurlencode($version);
    }

    /**
     * Neueste Version im Repository (null = nicht erreichbar): zuerst kontor-shop.php
     * ueber raw.githubusercontent.com, sonst die Tags ueber api.github.com (manche
     * Hoster lassen nur einen der beiden Wege zu). Ergebnis und Fehler stehen in der
     * Plugin-Liste (row_meta), damit man sieht, was der Server erlebt.
     */
    public static function remote_version(bool $fresh = false): ?string
    {
        $cached = get_transient(self::CACHE_KEY);

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- "Erneut pruefen" (update-core.php?force-check=1)
        if (!$fresh && is_string($cached) && empty($_GET['force-check'])) {
            return $cached === '' ? null : $cached;
        }

        $errors  = [];
        $version = null;
        $source  = '';

        $response = wp_remote_get('https://raw.githubusercontent.com/' . self::REPOSITORY . '/main/kontor-shop.php', ['timeout' => 10]);

        if (is_wp_error($response)) {
            $errors[] = 'raw.githubusercontent.com: ' . $response->get_error_message();
        } elseif ((int) wp_remote_retrieve_response_code($response) !== 200) {
            $errors[] = 'raw.githubusercontent.com: HTTP ' . (int) wp_remote_retrieve_response_code($response);
        } elseif (preg_match('/^\s*\*\s*Version:\s*([0-9][0-9A-Za-z.\-]*)/m', (string) wp_remote_retrieve_body($response), $match) === 1) {
            $version = $match[1];
            $source  = 'raw.githubusercontent.com';
        }

        if ($version === null) {
            $response = wp_remote_get('https://api.github.com/repos/' . self::REPOSITORY . '/tags?per_page=20', [
                'timeout' => 10,
                'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'Kontor-Shop/' . KONTOR_SHOP_VERSION],
            ]);

            if (is_wp_error($response)) {
                $errors[] = 'api.github.com: ' . $response->get_error_message();
            } elseif ((int) wp_remote_retrieve_response_code($response) !== 200) {
                $errors[] = 'api.github.com: HTTP ' . (int) wp_remote_retrieve_response_code($response);
            } else {
                foreach ((array) json_decode((string) wp_remote_retrieve_body($response), true) as $tag) {
                    $name = ltrim((string) ($tag['name'] ?? ''), 'vV');

                    if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $name) === 1 && ($version === null || version_compare($name, $version, '>'))) {
                        $version = $name;
                        $source  = 'api.github.com';
                    }
                }
            }
        }

        set_transient(self::CACHE_KEY, $version ?? '', $version !== null ? self::CACHE_TTL : self::ERROR_TTL);
        update_option(self::CHECK_KEY, ['time' => time(), 'version' => $version, 'source' => $source, 'errors' => $version === null ? $errors : []], false);

        return $version;
    }

    /** Unter "Kontor Shop" in der Plugin-Liste: woher die Updates kommen und was die letzte Pruefung ergab. */
    public static function row_meta(array $meta, string $file): array
    {
        if ($file !== KONTOR_SHOP_BASENAME || !current_user_can('update_plugins')) {
            return $meta;
        }

        $check = (array) get_option(self::CHECK_KEY, []);
        $text  = __('Automatic updates via GitHub', 'kontor-shop');

        if (!empty($check['time'])) {
            /* translators: %s: date and time */
            $text .= ' · ' . sprintf(__('last checked %s', 'kontor-shop'), wp_date((string) get_option('date_format') . ' H:i', (int) $check['time']));
            $text .= !empty($check['version'])
                /* translators: %s: version number */
                ? ' · ' . sprintf(__('newest version %s', 'kontor-shop'), $check['version'])
                /* translators: %s: error message */
                : ' · ' . sprintf(__('GitHub not reachable: %s', 'kontor-shop'), implode(' / ', (array) ($check['errors'] ?? [])));
        }

        $meta[] = esc_html($text) . ' · <a href="' . esc_url(wp_nonce_url(admin_url('plugins.php?' . self::CHECK_NOW . '=1'), self::CHECK_NOW)) . '">' . esc_html__('Check now', 'kontor-shop') . '</a>';

        return $meta;
    }

    /** "Jetzt prüfen": GitHub frisch fragen und WordPress' Update-Liste neu aufbauen. */
    public static function check_now(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce direkt danach geprueft
        if (empty($_GET[self::CHECK_NOW]) || !current_user_can('update_plugins')) {
            return;
        }

        check_admin_referer(self::CHECK_NOW);
        delete_transient(self::CACHE_KEY);
        self::remote_version(true);
        delete_site_transient('update_plugins');
        wp_update_plugins();
        wp_safe_redirect(admin_url('plugins.php'));
        exit;
    }
}

Kontor_Shop_Free_Updates::register();
