<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin\Settings;

use Kontor\Shop\Admin\SystemCheck;
use Kontor\Shop\Core\Uninstaller;
use Kontor\Shop\Infrastructure\Notifications\MailHealth;

/**
 * Systempruefung, Deinstallation und Testmail. Erweiterungen (Pro: Alarm-Mail
 * bei Stoerungen) haengen eigene Formulare und Aktionen an.
 */
final class SystemSection extends Section
{
    public function key(): string
    {
        return 'system';
    }

    public function label(): string
    {
        return __('System', 'kontor-shop');
    }

    public function group(): string
    {
        return self::GROUP_TECH;
    }

    public function save(string $tab): ?string
    {
        return match ($tab) {
            'system' => $this->save_system(),
            default => null,
        };
    }

    public function render(): void
    {
        $this->render_system_tab();
    }

    private function save_system(): ?string
    {
        $check      = new SystemCheck($this->settings);
        $recipients = $check->notify_recipients();
        $action     = sanitize_key((string) ($_POST['system_action'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request

        if ($action === 'uninstall_mode') {
            Uninstaller::set_mode(sanitize_key((string) ($_POST['uninstall_mode'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request

            return __('Settings saved.', 'kontor-shop');
        }

        if ($action !== 'test_mail') {
            /**
             * Filter kontor_shop/settings/system/action: weitere Aktionen im Bereich
             * "System" (Feld system_action eigener Formulare aus
             * kontor_shop/settings/system/render). Nonce und Recht sind schon geprueft.
             * Standard null = nichts zu tun.
             *
             * @param string|null $notice Erfolgsmeldung (null = keine Aktion erkannt)
             * @param string      $action Wert von system_action
             */
            $notice = apply_filters('kontor_shop/settings/system/action', null, $action);

            return is_string($notice) && $notice !== '' ? $notice : null;
        }

        if ($recipients === []) {
            return null;
        }

        $shop_name = (string) $this->settings->get('general.shop_name', '') ?: (string) get_bloginfo('name');
        $result    = MailHealth::send_test($recipients[0], $shop_name);

        if (!$result['ok']) {
            $this->warnings[] = __('Test email failed: ', 'kontor-shop') . $result['message'];

            return null;
        }

        return $result['message'];
    }

    private function render_system_tab(): void
    {
        $check      = new SystemCheck($this->settings);
        $icons      = [
            SystemCheck::OK    => '<span class="ks-sys ks-sys--ok">&#10003;</span>',
            SystemCheck::WARN  => '<span class="ks-sys ks-sys--warn">&#9888;</span>',
            SystemCheck::ERROR => '<span class="ks-sys ks-sys--bad">&#10007;</span>',
        ];

        echo '<div class="ks-settings-section"><h2>' . esc_html__('System check', 'kontor-shop') . '</h2><p class="description">' . esc_html__(
            'Checks whether the shop is ready to run on its own (without a remote system or extension). The notices don’t block anything.',
            'kontor-shop'
        ) . '</p>';

        echo '<table class="widefat striped ks-settings-table ks-syscheck"><tbody>';

        foreach ($check->run() as $row) {
            echo '<tr><td class="ks-syscheck__icon">' . wp_kses_post($icons[$row['status']]) . '</td><td class="ks-syscheck__label"><strong>'
                . esc_html($row['label']) . '</strong></td><td>' . esc_html($row['message']) . '</td></tr>';
        }

        echo '</tbody></table></div>';

        $recipients = $check->notify_recipients();

        $this->form_open('system');
        echo '<h2>' . esc_html__('Test email', 'kontor-shop') . '</h2>';
        echo '<input type="hidden" name="system_action" value="test_mail" />';
        echo '<p>' . esc_html($recipients !== []
            ? sprintf(
                /* translators: %s: recipient email address */
                __('Sends a test email via wp_mail() to %s.', 'kontor-shop'),
                $recipients[0]
            )
            : __('Please enter a valid recipient address first (“Email sending” section).', 'kontor-shop')) . '</p>';
        echo '<p><button type="submit" class="button" ' . ($recipients === [] ? 'disabled' : '') . '>' . esc_html__('Send test email', 'kontor-shop') . '</button></p>';
        echo '</form>';

        /**
         * Aktion kontor_shop/settings/system/render: eigene Formulare im Bereich
         * "System" zwischen Testmail und Deinstallation. Formulare schicken
         * tab=system, die Settings-Nonce und ein eigenes system_action
         * (verarbeitet ueber kontor_shop/settings/system/action).
         *
         * @param \Kontor\Shop\Settings\Repository $settings
         */
        do_action('kontor_shop/settings/system/render', $this->settings);

        $this->render_uninstall_section();
    }

    /**
     * Was beim Loeschen des Plugins passiert (Core\Uninstaller). Dieselbe
     * Frage kommt auch beim Deaktivieren in der Plugin-Liste.
     */
    private function render_uninstall_section(): void
    {
        $mode   = Uninstaller::mode();
        $forced = Uninstaller::forced_by_constant();

        $this->form_open('system', 'ks-settings-section--danger');
        echo '<h2>' . esc_html__('Uninstall', 'kontor-shop') . '</h2>';
        echo '<input type="hidden" name="system_action" value="uninstall_mode" />';
        echo '<p class="description">' . esc_html__('What happens when the plugin is deleted in the plugin list? Deactivating alone never changes any data. The question also appears when deactivating.', 'kontor-shop') . '</p>';
        echo '<fieldset class="ks-fieldset">';
        echo '<p><label><input type="radio" name="uninstall_mode" value="' . esc_attr(Uninstaller::MODE_KEEP) . '"' . checked($mode, Uninstaller::MODE_KEEP, false) . disabled($forced, true, false) . ' /> <strong>'
            . esc_html__('Remove only the plugin – keep data', 'kontor-shop') . '</strong></label><br /><span class="description">'
            . esc_html__('Products, orders, warehouses, settings, pages, and images are kept; a reinstall picks up seamlessly. Only caches and the background job are removed.', 'kontor-shop') . '</span></p>';
        echo '<p><label><input type="radio" name="uninstall_mode" value="' . esc_attr(Uninstaller::MODE_PURGE) . '"' . checked($mode, Uninstaller::MODE_PURGE, false) . disabled($forced, true, false) . ' /> <strong>'
            . esc_html__('Remove the plugin and all data', 'kontor-shop') . '</strong></label><br /><span class="description">'
            . esc_html__('Removes every trace: all shop tables (products, orders, warehouses, logs), all settings and caches, API access, the shop pages that were created (as long as they still contain the shop shortcode), images uploaded only for the shop (if they aren’t used anywhere else), the “Shop manager” role, and all shop permissions of users. Can’t be undone – make a backup first.', 'kontor-shop') . '</span></p>';
        echo '</fieldset>';

        if ($forced) {
            echo '<p class="notice notice-warning inline ks-notice-pad">' . esc_html__('KONTOR_SHOP_UNINSTALL_PURGE_ALL is set in wp-config.php: all data is always removed on deletion.', 'kontor-shop') . '</p>';
        } else {
            echo '<p><button type="submit" class="button">' . esc_html__('Save', 'kontor-shop') . '</button></p>';
        }

        echo '</form>';
    }
}
