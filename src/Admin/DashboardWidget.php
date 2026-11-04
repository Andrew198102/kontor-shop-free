<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin;

use Kontor\Shop\Admin\Ui\Css;
use Kontor\Shop\Core\Capabilities;
use Kontor\Shop\Core\Registerable;
use Kontor\Shop\Modules\Notifications\PoweredBy;
use Kontor\Shop\Settings\Repository;

/**
 * Macht den Shop auf dem WP-Dashboard (Startseite des Backends) sichtbar
 * (docs/PLAN_dashboard.md): Betriebsampel, die Zahlen "Heute zu tun" je
 * Rolle (kontor_shop/dashboard/widget_numbers), Karten von Erweiterungen
 * (kontor_shop/dashboard/cards, Bereich 'widget', z. B. Pro: die drei
 * wichtigsten "Das haengt"-Punkte) und die Wege zur Shop-Uebersicht bzw. zum
 * Packen - aus derselben Quelle wie die Uebersicht (ShopSnapshot; "Packen"
 * nur mit Packansicht). Zusaetzlich ein Hinweis-Balken fuer Shop-Admins auf
 * Dashboard und Plugin-Liste, solange Pflichtschritte offen sind - danach
 * (nur Free, einmal) die Frage nach "Vereinsshop mit Kontor Shop" (PoweredBy).
 */
final class DashboardWidget implements Registerable
{
    private const ID = 'kontor_shop_overview';

    public function __construct(private readonly Repository $settings)
    {
    }

    public function register(): void
    {
        add_action('wp_dashboard_setup', [$this, 'add_widget']);
        add_action('admin_notices', [$this, 'setup_notice']);
    }

    public function add_widget(): void
    {
        if (!Capabilities::can_manage() && !Capabilities::is_cashier() && !Capabilities::is_warehouse_manager()) {
            return;
        }

        wp_add_dashboard_widget(self::ID, __('Kontor Shop', 'kontor-shop'), [$this, 'render']);

        // Nach oben, damit es nicht hinter den Standard-Widgets verschwindet.
        global $wp_meta_boxes;

        $normal = $wp_meta_boxes['dashboard']['normal']['core'] ?? [];

        if (isset($normal[self::ID])) {
            $wp_meta_boxes['dashboard']['normal']['core'] = [self::ID => $normal[self::ID]] + array_diff_key($normal, [self::ID => true]);
        }
    }

    public function setup_notice(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if ($screen === null || !in_array($screen->id, ['dashboard', 'plugins'], true) || !Capabilities::can_manage()) {
            return;
        }

        $open = (new SetupChecklist($this->settings))->open_required();

        if ($open === []) {
            // Einrichtung fertig: nur Free, einmal die Frage nach "Vereinsshop mit Kontor Shop".
            PoweredBy::render_question($this->settings, true);

            return;
        }

        echo '<div class="notice notice-warning"><p><strong>' . esc_html__('Kontor Shop is not ready for orders yet.', 'kontor-shop') . '</strong> '
            . esc_html(sprintf(
                /* translators: %d: number of open setup steps */
                _n('%d setup step is still missing.', '%d setup steps are still missing.', count($open), 'kontor-shop'),
                count($open)
            ))
            . ' <a href="' . esc_url(DashboardPage::url()) . '">' . esc_html__('Set up now', 'kontor-shop') . '</a></p></div>';
    }

    public function render(): void
    {
        $snap   = new ShopSnapshot($this->settings);
        $checks = $snap->checks();
        $tiles  = [];

        foreach ($snap->today() as $tile) {
            $tiles[$tile['key']] = $tile;
        }

        $number  = static fn (string $key, int $n, string $label, string $url, bool $alert = false): array => ['key' => $key, 'n' => $n, 'label' => $label, 'url' => $url, 'alert' => $alert];
        $numbers = [];

        if ($snap->can_pay) {
            $numbers[] = $number('pay', $tiles['pay']['count'] ?? 0, __('Payment open', 'kontor-shop'), $tiles['pay']['url'] ?? OrdersPage::url());
        }

        if ($snap->can_ship) {
            $numbers[] = $number('ship', $tiles['ship']['count'] ?? 0, $tiles['ship']['label'] ?? __('To ship', 'kontor-shop'), $tiles['ship']['url'] ?? OrdersPage::url());
        }

        if (!$snap->can_ship && $snap->can_pay) {
            $numbers[] = $number('refund', $tiles['refund']['count'] ?? 0, __('Refund', 'kontor-shop'), $tiles['refund']['url'] ?? OrdersPage::url());
        }

        if ($snap->can_stock) {
            // Wartende Kunden nur, wenn es Wartelisten gibt (Erweiterung); sonst "knapp/leer".
            $waiting   = $snap->can_manage ? $snap->waiting_customers() : null;
            $numbers[] = $waiting !== null
                ? $number('stock', $waiting, __('Customers waiting', 'kontor-shop'), StockPage::url(['view' => 'requests']))
                : $number('stock', $tiles['reorder']['count'] ?? 0, __('low/empty', 'kontor-shop'), $tiles['reorder']['url'] ?? StockPage::url());
        }

        /**
         * Filter kontor_shop/dashboard/widget_numbers: die Zahlen im
         * WP-Dashboard-Widget (je Rolle), z. B. Pro: "haengt". Hoechstens
         * vier bis fuenf, sonst wird es eng. Standard: Zahlung offen, zu
         * versenden (ohne Versandrecht: Rueckzahlung) und knapp/leer bzw.
         * wartende Kunden.
         *
         * @param array<int, array{key: string, n: int, label: string, url: string, alert: bool}> $numbers alert: rot, sobald n > 0
         * @param ShopSnapshot $snapshot
         */
        $numbers = array_values(array_filter(
            (array) apply_filters('kontor_shop/dashboard/widget_numbers', $numbers, $snap),
            static fn ($item): bool => is_array($item) && isset($item['n'], $item['label'], $item['url'])
        ));

        echo '<div class="ks-wrap ks-widget">';
        echo DashboardView::health($checks, $snap->can_manage, true); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- DashboardView liefert escaptes HTML
        echo '<div class="ks-widget__nums ' . esc_attr(Css::cols(count($numbers))) . '">';

        foreach ($numbers as $item) {
            echo '<a href="' . esc_url((string) $item['url']) . '" class="' . ((int) $item['n'] === 0 ? 'is-zero' : (!empty($item['alert']) ? 'is-alert' : '')) . '"><span class="ks-widget__n">' . (int) $item['n'] . '</span>'
                . '<span class="ks-widget__l">' . esc_html((string) $item['label']) . '</span></a>';
        }

        echo '</div>';

        foreach (DashboardPage::cards($snap)['widget'] as $render) {
            $render($snap);
        }

        echo '<p class="ks-widget__foot"><a class="button button-primary" href="' . esc_url(DashboardPage::url()) . '">' . esc_html__('Shop dashboard', 'kontor-shop') . '</a> ';

        if ($snap->can_ship) {
            echo '<a class="button" href="' . esc_url($tiles['ship']['url'] ?? OrdersPage::url()) . '">' . esc_html($tiles['ship']['label'] ?? __('To ship', 'kontor-shop')) . '</a>';
        } elseif ($snap->can_pay) {
            echo '<a class="button" href="' . esc_url($tiles['pay']['url'] ?? OrdersPage::url()) . '">' . esc_html__('Payments', 'kontor-shop') . '</a>';
        }

        echo '</p></div>';
    }
}
