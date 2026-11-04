<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin;

use Kontor\Shop\Infrastructure\Checkout\ReservationCleanup;
use Kontor\Shop\Infrastructure\Logging\Logger;
use Kontor\Shop\Infrastructure\Notifications\MailerFactory;
use Kontor\Shop\Infrastructure\Notifications\MailHealth;
use Kontor\Shop\Infrastructure\Payment\BankAccounts;
use Kontor\Shop\Infrastructure\Repositories\WarehouseRepository;
use Kontor\Shop\Settings\Repository;

/**
 * Systemcheck/Betriebsbereitschaft: prueft, ob das Plugin allein (ohne
 * Gegenstelle oder Add-on) einsatzbereit ist - Datenbank, Mailversand, Cron, Seiten,
 * Grundkonfiguration. Liefert nur Hinweise, blockiert nie etwas.
 */
final class SystemCheck
{
    public const OK    = 'ok';
    public const WARN  = 'warn';
    public const ERROR = 'error';

    private const TABLES = [
        'shop_types', 'categories', 'warehouses', 'products', 'stock_movements', 'stock_reservations',
        'orders', 'order_items', 'order_status_log',
    ];

    public function __construct(private readonly Repository $settings)
    {
    }

    /**
     * @return array<int, array{label: string, status: string, message: string}>
     */
    public function run(): array
    {
        return array_merge($this->technical(), $this->mail(), $this->errors(), $this->configuration());
    }

    /**
     * @return array<int, array{label: string, status: string, message: string}>
     */
    private function technical(): array
    {
        global $wpdb;

        $checks = [];

        $checks[] = $this->row(
            'PHP',
            version_compare(PHP_VERSION, KONTOR_SHOP_MIN_PHP, '>=') ? self::OK : self::ERROR,
            PHP_VERSION
        );

        $checks[] = $this->row(
            'WordPress',
            version_compare((string) get_bloginfo('version'), '6.4', '>=') ? self::OK : self::WARN,
            (string) get_bloginfo('version')
        );

        $missing = [];

        foreach (self::TABLES as $table) {
            $name = $wpdb->prefix . 'kontor_shop_' . $table;

            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name)) !== $name) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Systempruefung braucht den aktuellen Stand
                $missing[] = $table;
            }
        }

        $checks[] = $this->row(
            __('Database tables', 'kontor-shop'),
            $missing === [] ? self::OK : self::ERROR,
            /* translators: %s: comma-separated list of missing table names */
            $missing === [] ? __('all present', 'kontor-shop') : sprintf(__('missing: %s (deactivate and reactivate the plugin)', 'kontor-shop'), implode(', ', $missing))
        );

        $engine = (string) $wpdb->get_var($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Systempruefung braucht den aktuellen Stand
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $wpdb->prefix . 'kontor_shop_products'
        ));

        $checks[] = $this->row(
            __('Database engine', 'kontor-shop'),
            strcasecmp($engine, 'InnoDB') === 0 || $engine === '' ? self::OK : self::ERROR,
            strcasecmp($engine, 'InnoDB') === 0
                ? 'InnoDB'
                /* translators: %s: name of the database storage engine */
                : ($engine === '' ? __('cannot be determined', 'kontor-shop') : sprintf(__('%s - stock reservation requires InnoDB (row locks)', 'kontor-shop'), $engine))
        );

        $scheduled = wp_next_scheduled(ReservationCleanup::HOOK);
        $cron_off  = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;

        $checks[] = $this->row(
            __('Cron (reservation cleanup)', 'kontor-shop'),
            $scheduled !== false ? ($cron_off ? self::WARN : self::OK) : self::WARN,
            $scheduled === false
                ? __('not scheduled (deactivate and reactivate the plugin once)', 'kontor-shop')
                : ($cron_off ? __('WP-Cron is turned off - a server cron must call wp-cron.php', 'kontor-shop') : __('scheduled', 'kontor-shop'))
        );

        return $checks;
    }

    /**
     * @return array<int, array{label: string, status: string, message: string}>
     */
    private function mail(): array
    {
        $checks = [];

        $checks[] = $this->row(
            'wp_mail()',
            function_exists('wp_mail') ? self::OK : self::ERROR,
            function_exists('wp_mail') ? __('available', 'kontor-shop') : __('not available - no emails can be sent', 'kontor-shop')
        );

        $smtp = MailHealth::smtp_status((string) $this->settings->get('notifications.mailer', MailerFactory::WP_MAILER));

        $checks[] = $this->row(
            __('SMTP sending', 'kontor-shop'),
            $smtp['status'] === 'ok' ? self::OK : self::WARN,
            $smtp['message']
        );

        $recipients = $this->notify_recipients();

        $checks[] = $this->row(
            __('“New order” recipient', 'kontor-shop'),
            $recipients !== [] ? self::OK : self::WARN,
            $recipients !== [] ? implode(', ', $recipients) : __('no valid address - orders go unnoticed until someone checks the backend', 'kontor-shop')
        );

        $failure = MailHealth::last_failure();

        $checks[] = $this->row(
            __('Last email sent', 'kontor-shop'),
            $failure === null ? self::OK : self::ERROR,
            $failure === null
                ? __('no known error', 'kontor-shop')
                /* translators: 1: date and time of the error, 2: error message */
                : sprintf(__('Error on %1$s: %2$s', 'kontor-shop'), wp_date('d.m.Y H:i', $failure['time']), $failure['message'])
        );

        return $checks;
    }

    /**
     * @return array<int, array{label: string, status: string, message: string}>
     */
    private function errors(): array
    {
        $errors   = Logger::count_since(Logger::ERROR, DAY_IN_SECONDS);
        $critical = Logger::count_since(Logger::CRITICAL, DAY_IN_SECONDS);

        return [$this->row(
            __('Error log (24 h)', 'kontor-shop'),
            $critical > 0 ? self::ERROR : ($errors > 0 ? self::WARN : self::OK),
            $errors === 0
                ? __('no errors', 'kontor-shop')
                /* translators: 1: number of errors, 2: number of critical errors */
                : sprintf(__('%1$d errors, %2$d of them critical - see the “Logs” menu', 'kontor-shop'), $errors, $critical)
        )];
    }

    /**
     * @return array<int, array{label: string, status: string, message: string}>
     */
    private function configuration(): array
    {
        $checks = [];

        foreach ([
            'shop_page_id'     => __('Shop page', 'kontor-shop'),
            'cart_page_id'     => __('Cart page', 'kontor-shop'),
            'checkout_page_id' => __('Checkout page', 'kontor-shop'),
        ] as $key => $label) {
            $id      = absint($this->settings->get('general.' . $key, 0));
            $checks[] = $this->row(
                $label,
                $id > 0 && get_post_status($id) === 'publish' ? self::OK : self::WARN,
                $id > 0 && get_post_status($id) === 'publish' ? __('published', 'kontor-shop') : __('missing or not published', 'kontor-shop')
            );
        }

        /**
         * Filter kontor_shop/admin/system_checks: weitere Zeilen im
         * Systemcheck (Bereich Einrichtung, nach den Shop-Seiten), z. B. Pro:
         * "Shoptypen: keiner aktiv - der Katalog bleibt leer". Standard: [].
         *
         * @param array<int, array{label: string, status: string, message: string}> $rows status: SystemCheck::OK|WARN|ERROR
         * @param string                                                             $group 'configuration'
         */
        foreach ((array) apply_filters('kontor_shop/admin/system_checks', [], 'configuration') as $extra) {
            if (is_array($extra) && isset($extra['label'], $extra['status'], $extra['message']) && in_array($extra['status'], [self::OK, self::WARN, self::ERROR], true)) {
                $checks[] = $this->row((string) $extra['label'], (string) $extra['status'], (string) $extra['message']);
            }
        }

        $warehouses = (new WarehouseRepository())->all();
        $checks[]   = $this->row(
            __('Warehouse', 'kontor-shop'),
            $warehouses !== [] ? self::OK : self::WARN,
            $warehouses !== [] ? (string) count($warehouses) : __('none created - no products without a warehouse', 'kontor-shop')
        );

        $accounts = (new BankAccounts($this->settings))->all();
        $checks[] = $this->row(
            __('Bank account (prepayment)', 'kontor-shop'),
            $accounts !== [] ? self::OK : self::WARN,
            /* translators: %d: number of bank accounts */
            $accounts !== [] ? sprintf(__('%d on file', 'kontor-shop'), count($accounts)) : __('none on file - customers see no bank details for prepayment', 'kontor-shop')
        );

        $legal_missing = [];

        foreach (['terms_page_id' => __('Terms and conditions', 'kontor-shop'), 'withdrawal_page_id' => __('Right of withdrawal', 'kontor-shop'), 'privacy_page_id' => __('Privacy policy', 'kontor-shop'), 'imprint_page_id' => __('Legal notice', 'kontor-shop')] as $key => $label) {
            if (absint($this->settings->get('legal.' . $key, 0)) === 0) {
                $legal_missing[] = $label;
            }
        }

        $checks[] = $this->row(
            __('Legal texts linked', 'kontor-shop'),
            $legal_missing === [] ? self::OK : self::WARN,
            /* translators: %s: comma-separated list of missing legal texts */
            $legal_missing === [] ? __('complete', 'kontor-shop') : sprintf(__('not selected: %s (Settings → Legal)', 'kontor-shop'), implode(', ', $legal_missing))
        );

        $rates    = (array) $this->settings->get('tax.rates', []);
        $checks[] = $this->row(
            __('VAT', 'kontor-shop'),
            self::OK,
            $rates === [] ? __('VAT-exempt (no tax rates on file)', 'kontor-shop') : implode(' %, ', $rates) . ' %'
        );

        return $checks;
    }

    /**
     * @return array<int, string>
     */
    public function notify_recipients(): array
    {
        return (new \Kontor\Shop\Modules\Notifications\NotificationsModule($this->settings))->new_order_recipients();
    }

    /**
     * @return array{label: string, status: string, message: string}
     */
    private function row(string $label, string $status, string $message): array
    {
        return ['label' => $label, 'status' => $status, 'message' => $message];
    }
}
