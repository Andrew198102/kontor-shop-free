<?php

declare(strict_types=1);

/**
 * Automatische Updates der Free-Version aus github.com/Andrew198102/kontor-shop-free.
 *
 * Liegt NUR in der Free-Version, die der Release-Workflow in dieses oeffentliche
 * Repository schreibt (als kontor-shop-updates.php im Plugin-Ordner). Die Kopie fuer
 * wordpress.org bekommt die Datei nicht - dort kommen die Updates von wordpress.org
 * (Regel 8), Pro bekommt sie von Freemius.
 *
 * WordPress fragt etwa alle 12 Stunden nach Updates; dann steht hier die Version aus
 * kontor-shop.php im Repository (6 Stunden gemerkt, "Erneut pruefen" unter
 * Dashboard → Aktualisierungen umgeht das). Mit "Automatische Aktualisierungen
 * aktivieren" in der Plugin-Liste spielt WordPress neue Versionen selbst ein.
 * Paket ist das ZIP des Tags v<Version>; dessen Ordner heisst kontor-shop-free-<Version>
 * und wird beim Entpacken in kontor-shop umbenannt.
 */

if (!defined('ABSPATH') || !defined('KONTOR_SHOP_BASENAME')) {
    return;
}

final class Kontor_Shop_Free_Updates
{
    public const REPOSITORY = 'Andrew198102/kontor-shop-free';

    private const CACHE_KEY = 'kontor_shop_free_update';
    private const CACHE_TTL = 6 * HOUR_IN_SECONDS;
    private const ERROR_TTL = HOUR_IN_SECONDS;

    public static function register(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'offer']);
        add_filter('plugins_api', [self::class, 'details'], 10, 3);
        add_filter('upgrader_source_selection', [self::class, 'rename_folder'], 10, 4);
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

    /** Version aus kontor-shop.php im Repository (null = nicht erreichbar). */
    public static function remote_version(): ?string
    {
        $cached = get_transient(self::CACHE_KEY);

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- "Erneut pruefen" (update-core.php?force-check=1)
        if (is_string($cached) && empty($_GET['force-check'])) {
            return $cached === '' ? null : $cached;
        }

        $response = wp_remote_get('https://raw.githubusercontent.com/' . self::REPOSITORY . '/main/kontor-shop.php', ['timeout' => 10]);
        $version  = null;

        if (!is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) === 200
            && preg_match('/^\s*\*\s*Version:\s*([0-9][0-9A-Za-z.\-]*)/m', (string) wp_remote_retrieve_body($response), $match) === 1) {
            $version = $match[1];
        }

        set_transient(self::CACHE_KEY, $version ?? '', $version !== null ? self::CACHE_TTL : self::ERROR_TTL);

        return $version;
    }
}

Kontor_Shop_Free_Updates::register();
