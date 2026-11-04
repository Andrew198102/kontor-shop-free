<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin;

use Kontor\Shop\Admin\Ui\Flash;
use Kontor\Shop\Admin\Ui\PageHeader;
use Kontor\Shop\Admin\Ui\Views;
use Kontor\Shop\Infrastructure\Logging\Logger;
use Kontor\Shop\Settings\Repository;

/**
 * Fehlerprotokoll (Menue "Protokoll"): unerwartete Fehler und Warnungen mit
 * Referenznummer (die der Kunde im Frontend sieht), Filter nach Stufe/Suche,
 * Stufe und Aufbewahrungsdauer einstellbar, Protokoll leeren.
 */
final class LogPage
{
    private const NONCE_ACTION = 'kontor_shop_log_action';

    public const SLUG = 'kontor-shop-log';

    public function __construct(private readonly Repository $settings)
    {
    }

    /**
     * Ansichten unter "Protokolle" (Errors & warnings, dazu ggf. Erweiterungen).
     *
     * @return array<string, array{label: string, url: string}>
     */
    public static function views(): array
    {
        $views = ['log' => ['label' => __('Errors & warnings', 'kontor-shop'), 'url' => admin_url('admin.php?page=' . self::SLUG)]];

        /**
         * Filter kontor_shop/log/views: weitere Ansichten unter "Protokolle"
         * (z. B. das Aenderungsprotokoll). Standard: nur "Errors & warnings";
         * mit nur einer Ansicht zeigt die Seite keine Ansichtsleiste.
         *
         * @param array<string, array{label: string, url: string}> $views Schluessel => Beschriftung/Link
         */
        $filtered = apply_filters('kontor_shop/log/views', $views);

        return is_array($filtered) && $filtered !== [] ? $filtered : $views;
    }

    /**
     * Aktion auf load-{hook} (vor jeder Ausgabe) mit Umleitung (Post/Redirect/Get).
     */
    public function handle_request(): void
    {
        if (!current_user_can(Menu::SETTINGS_CAPABILITY)) {
            wp_die(esc_html__('No permission.', 'kontor-shop'));
        }

        $notice = $this->handle_submit();

        if ($notice !== null) {
            Flash::redirect(admin_url('admin.php?page=kontor-shop-log'), $notice);
        }
    }

    public function render(): void
    {
        if (!current_user_can(Menu::SETTINGS_CAPABILITY)) {
            wp_die(esc_html__('No permission.', 'kontor-shop'));
        }

        $level = isset($_GET['level']) ? sanitize_key((string) $_GET['level']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige-Parameter
        $q     = isset($_GET['q']) ? sanitize_text_field(wp_unslash((string) $_GET['q'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige-Parameter
        $rows  = Logger::recent(['level' => $level, 'q' => $q], 200);

        echo '<div class="wrap ks-wrap">';
        PageHeader::render(__('Logs', 'kontor-shop'), [
            ['label' => __('Report a problem', 'kontor-shop'), 'url' => ReportPage::url()],
        ]);
        $views = self::views();

        if (count($views) > 1) {
            Views::render($views, 'log');
        }

        echo '<p class="ks-page-head__desc">' . esc_html__(
            'Unexpected errors and warnings end up here. For a technical error, customers see an error number (ERR-…) that you can enter in the search here. Personal data is not logged.',
            'kontor-shop'
        ) . '</p>';

        Flash::render();

        $this->render_filters($level, $q);
        $this->render_table($rows);
        $this->render_settings();

        echo '</div>';
    }

    private function handle_submit(): ?string
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';

        if ($method !== 'POST' || !isset($_POST['kontor_shop_log_nonce'])) {
            return null;
        }

        check_admin_referer(self::NONCE_ACTION, 'kontor_shop_log_nonce');

        if (!current_user_can(Menu::SETTINGS_CAPABILITY)) {
            wp_die(esc_html__('No permission.', 'kontor-shop'));
        }

        $action = sanitize_key((string) ($_POST['log_action'] ?? ''));

        if ($action === 'clear') {
            Logger::clear();

            return __('Log cleared.', 'kontor-shop');
        }

        if ($action === 'settings') {
            $level = sanitize_key((string) ($_POST['level'] ?? 'warning'));

            $this->settings->save(['logging' => [
                'level'          => in_array($level, Logger::levels(), true) ? $level : Logger::WARNING,
                'retention_days' => min(365, max(1, absint($_POST['retention_days'] ?? 30))),
            ]]);

            return __('Settings saved.', 'kontor-shop');
        }

        return null;
    }

    private function render_filters(string $level, string $q): void
    {
        echo '<form method="get" class="ks-filter-bar">';
        echo '<input type="hidden" name="page" value="kontor-shop-log" />';
        echo '<select name="level"><option value="">' . esc_html__('all levels', 'kontor-shop') . '</option>';

        foreach (Logger::levels() as $option) {
            echo '<option value="' . esc_attr($option) . '" ' . selected($level, $option, false) . '>'
                . esc_html(sprintf(
                    /* translators: %s: log level */
                    __('from %s', 'kontor-shop'),
                    $option
                )) . '</option>';
        }

        echo '</select>';
        echo '<input type="search" name="q" class="regular-text" placeholder="' . esc_attr__('Reference (KS-…), message or area', 'kontor-shop') . '" value="' . esc_attr($q) . '" />';
        echo '<button type="submit" class="button">' . esc_html__('Filter', 'kontor-shop') . '</button></form>';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function render_table(array $rows): void
    {
        echo '<div class="ks-table-scroll"><table class="widefat striped"><thead><tr>';
        foreach ([__('Time', 'kontor-shop'), __('Level', 'kontor-shop'), __('Area', 'kontor-shop'), __('Reference', 'kontor-shop'), __('Message', 'kontor-shop')] as $head) {
            echo '<th>' . esc_html($head) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $level = (string) $row['level'];

            echo '<tr><td class="ks-nowrap">' . esc_html(get_date_from_gmt((string) $row['created_at'], 'd.m.Y H:i:s')) . '</td>';
            echo '<td><span class="ks-level ks-level--' . esc_attr($level) . '">' . esc_html($level) . '</span></td>';
            echo '<td>' . esc_html((string) $row['channel']) . '</td>';
            echo '<td>' . ($row['reference'] ? '<code>' . esc_html((string) $row['reference']) . '</code> <a href="' . esc_url(ReportPage::url((string) $row['reference'])) . '">' . esc_html__('Report', 'kontor-shop') . '</a>' : '—') . '</td>';
            echo '<td>' . esc_html((string) $row['message']);

            if (!empty($row['context'])) {
                echo '<details><summary>' . esc_html__('Details', 'kontor-shop') . '</summary><pre class="ks-pre">'
                    . esc_html((string) $row['context']) . '</pre>'
                    . (!empty($row['url']) ? '<small>' . esc_html((string) $row['url']) . '</small>' : '') . '</details>';
            }

            echo '</td></tr>';
        }

        if ($rows === []) {
            echo '<tr><td colspan="5">' . esc_html__('No entries – all quiet.', 'kontor-shop') . '</td></tr>';
        }

        echo '</tbody></table></div>';
    }

    private function render_settings(): void
    {
        $action_url = admin_url('admin.php?page=kontor-shop-log');
        $level      = (string) $this->settings->get('logging.level', 'warning');

        echo '<h2 class="ks-section-gap">' . esc_html__('Settings', 'kontor-shop') . '</h2>';
        echo '<form method="post" action="' . esc_url($action_url) . '" class="ks-inline-form">';
        wp_nonce_field(self::NONCE_ACTION, 'kontor_shop_log_nonce');
        echo '<input type="hidden" name="log_action" value="settings" />';
        echo '<label>' . esc_html__('Log from level', 'kontor-shop') . ' <select name="level">';

        foreach (Logger::levels() as $option) {
            echo '<option value="' . esc_attr($option) . '" ' . selected($level, $option, false) . '>' . esc_html($option) . '</option>';
        }

        echo '</select></label>';
        echo '<label>' . esc_html__('Keep (days)', 'kontor-shop') . ' <input type="number" name="retention_days" class="small-text" min="1" max="365" value="'
            . esc_attr((string) $this->settings->get('logging.retention_days', 30)) . '" /></label>';
        echo '<button type="submit" class="button">' . esc_html__('Save', 'kontor-shop') . '</button></form>';
        echo '<p class="description">' . esc_html__(
            'Recommended: "warning". Only turn on "debug"/"info" for troubleshooting. The log is limited to 5,000 entries.',
            'kontor-shop'
        ) . '</p>';

        echo '<form method="post" action="' . esc_url($action_url) . '" data-ks-confirm="' . esc_attr__('Really clear the log?', 'kontor-shop') . '">';
        wp_nonce_field(self::NONCE_ACTION, 'kontor_shop_log_nonce');
        echo '<input type="hidden" name="log_action" value="clear" />';
        echo '<button type="submit" class="button">' . esc_html__('Clear log', 'kontor-shop') . '</button></form>';
    }
}
