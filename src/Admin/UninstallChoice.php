<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin;

use Kontor\Shop\Core\Registerable;
use Kontor\Shop\Core\Uninstaller;

/**
 * Auswahl "beim Löschen Daten behalten oder alles entfernen" direkt in der
 * Plugin-Liste. Ein inaktives Plugin kann beim Klick auf "Löschen" selbst
 * nichts mehr anzeigen - daher fragt der Dialog beim "Deaktivieren" (davor
 * laesst WordPress kein Loeschen zu). Die Wahl steht auch unter
 * Einstellungen → System und wird beim Deinstallieren ausgewertet (Core\Uninstaller)
 * (Core\Uninstaller).
 */
final class UninstallChoice implements Registerable
{
    public const AJAX_ACTION = 'kontor_shop_uninstall_mode';

    public function register(): void
    {
        if (!is_admin()) {
            return;
        }

        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'save']);
    }

    public function enqueue(string $hook): void
    {
        if ($hook !== 'plugins.php' || !current_user_can('activate_plugins')) {
            return;
        }

        wp_enqueue_style('kontor-shop-uninstall-choice', KONTOR_SHOP_URL . 'assets/admin/uninstall-choice.css', [], KONTOR_SHOP_VERSION);
        wp_enqueue_script('kontor-shop-uninstall-choice', KONTOR_SHOP_URL . 'assets/admin/uninstall-choice.js', [], KONTOR_SHOP_VERSION, true);
        wp_localize_script('kontor-shop-uninstall-choice', 'kontorShopUninstall', [
            'plugin'  => KONTOR_SHOP_BASENAME,
            'mode'    => Uninstaller::mode(),
            'forced'  => Uninstaller::forced_by_constant(),
            'ajax'    => admin_url('admin-ajax.php'),
            'action'  => self::AJAX_ACTION,
            'nonce'   => wp_create_nonce(self::AJAX_ACTION),
            'i18n'    => [
                'title'      => __('Deactivate Kontor Shop', 'kontor-shop'),
                'intro'      => __('What should happen if you delete the plugin afterward? Deactivating alone changes nothing about the data.', 'kontor-shop'),
                'keep'       => __('Remove only the plugin – keep data', 'kontor-shop'),
                'keepHint'   => __('Products, orders, warehouses, settings, pages, and images are kept. If you reinstall, you pick up right where you left off.', 'kontor-shop'),
                'purge'      => __('Remove the plugin and all data', 'kontor-shop'),
                'purgeHint'  => __('On deletion, all shop tables (products, orders, warehouses, logs), settings, the created shop pages, images uploaded only for the shop, roles, and capabilities are permanently removed. This cannot be undone – make a backup first.', 'kontor-shop'),
                'forced'     => __('KONTOR_SHOP_UNINSTALL_PURGE_ALL is set in wp-config.php: all data is always removed on deletion.', 'kontor-shop'),
                'confirm'    => __('Deactivate', 'kontor-shop'),
                'cancel'     => __('Cancel', 'kontor-shop'),
                'error'      => __('The selection could not be saved. Please set it under Kontor Shop → Settings → System.', 'kontor-shop'),
            ],
        ]);
    }

    public function save(): void
    {
        check_ajax_referer(self::AJAX_ACTION, 'nonce');

        if (!current_user_can('activate_plugins')) {
            wp_send_json_error(null, 403);
        }

        $mode = sanitize_key((string) ($_POST['mode'] ?? ''));
        Uninstaller::set_mode($mode);

        wp_send_json_success(['mode' => Uninstaller::mode()]);
    }
}
