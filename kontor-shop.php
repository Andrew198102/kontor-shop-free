<?php
/**
 * Plugin Name:       Kontor Shop – Club Shop with Bank Transfer & Pickup
 * Plugin URI:        https://veyra-assistant.com/kontor-shop/
 * Description:       Club shop for WordPress: products with variants, stock, bank-transfer checkout, pickup and a cashier workflow.
 * Version:           2.1.1
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Veyra
 * Author URI:        https://veyra-assistant.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kontor-shop
 * Domain Path:       /languages
 *
 * Kontakt: wordpress@veyra-assistant.com
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (defined('KONTOR_SHOP_VERSION')) {
    // Zweite Kopie (z. B. eine alte Version in einem anderen Plugin-Ordner): nicht laden, aber im
    // Backend deutlich sagen, welche Kopie laeuft und welche ausgeschaltet bleibt.
    add_action('admin_notices', static function (): void {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        $this_copy = get_file_data(__FILE__, ['Version' => 'Version']);

        // Die laufende Kopie kennt diesen Text evtl. nicht (aeltere Version): eigene Uebersetzung dazuladen.
        load_textdomain('kontor-shop', __DIR__ . '/languages/kontor-shop-' . determine_locale() . '.mo');

        echo '<div class="notice notice-error"><p>' . esc_html(sprintf(
            /* translators: 1: running version, 2: plugin folder of the running copy, 3: version of this copy, 4: plugin folder of this copy */
            __('Kontor Shop is installed twice. Version %1$s from the folder “%2$s” is running; version %3$s from the folder “%4$s” stays switched off. Please deactivate and delete the copy you no longer need under Plugins.', 'kontor-shop'),
            KONTOR_SHOP_VERSION,
            defined('KONTOR_SHOP_BASENAME') ? dirname(KONTOR_SHOP_BASENAME) : '?',
            $this_copy['Version'],
            basename(__DIR__)
        )) . '</p></div>';
    });

    return;
}

define('KONTOR_SHOP_VERSION', '2.1.1');
define('KONTOR_SHOP_FILE', __FILE__);
define('KONTOR_SHOP_PATH', plugin_dir_path(__FILE__));
define('KONTOR_SHOP_URL', plugin_dir_url(__FILE__));
define('KONTOR_SHOP_BASENAME', plugin_basename(__FILE__));
define('KONTOR_SHOP_TEXT_DOMAIN', 'kontor-shop');
define('KONTOR_SHOP_MIN_PHP', '8.1');

require_once KONTOR_SHOP_PATH . 'src/Core/Autoloader.php';

\Kontor\Shop\Core\Autoloader::register();

// Lizenzen, Updates der Pro-Version und Upgrade-Seite (Freemius).
require_once KONTOR_SHOP_PATH . 'src/freemius.php';

if (function_exists('kontor_shop_load_pro__premium_only')) {
    kontor_shop_load_pro__premium_only();
}

// Automatische Updates der Free-Version aus github.com/Andrew198102/kontor-shop-free (Details in der Datei).
// Fehlt nur in der wordpress.org-Kopie - dort aktualisiert wordpress.org.
if (is_readable(KONTOR_SHOP_PATH . 'kontor-shop-updates.php')) {
    require_once KONTOR_SHOP_PATH . 'kontor-shop-updates.php';
}

register_activation_hook(KONTOR_SHOP_FILE, [\Kontor\Shop\Core\Lifecycle::class, 'activate']);
register_deactivation_hook(KONTOR_SHOP_FILE, [\Kontor\Shop\Core\Lifecycle::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    if (version_compare(PHP_VERSION, KONTOR_SHOP_MIN_PHP, '<')) {
        add_action('admin_notices', static function (): void {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html(sprintf(
                    /* translators: %s: required PHP version */
                    __('Kontor Shop requires at least PHP %s.', 'kontor-shop'),
                    KONTOR_SHOP_MIN_PHP
                ))
            );
        });

        return;
    }

    \Kontor\Shop\Core\Plugin::instance()->boot();
}, 5);
