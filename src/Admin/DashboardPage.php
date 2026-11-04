<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin;

use Kontor\Shop\Admin\Ui\Css;
use Kontor\Shop\Admin\Ui\Flash;
use Kontor\Shop\Core\Capabilities;
use Kontor\Shop\Domain\Health\Check;
use Kontor\Shop\Domain\Health\ShopHealth;
use Kontor\Shop\Modules\Notifications\PoweredBy;
use Kontor\Shop\Settings\Repository;

/**
 * Startseite des Plugins (docs/PLAN_dashboard.md): Laeuft der Shop
 * (Betriebsampel)? Ist er fertig eingerichtet (Einrichtung, solange
 * Pflichtschritte offen sind)? Was steht heute an? Dazu Karten von
 * Erweiterungen (kontor_shop/dashboard/cards, z. B. Pro: "Das haengt",
 * Verlauf und Lager). Jede Rolle sieht nur, wozu sie berechtigt ist; alle
 * Zahlen kommen aus ShopSnapshot (dieselben wie im Dashboard-Widget).
 *
 * Der Seiten-Slug "kontor-shop" ist zugleich der Menue-Slug fuer Freemius
 * und muss in Free registriert bleiben.
 */
final class DashboardPage
{
    public const SLUG = 'kontor-shop';

    public function __construct(private readonly Repository $settings)
    {
    }

    public static function url(): string
    {
        return admin_url('admin.php?page=' . self::SLUG);
    }

    /**
     * Alte Bestell-Links (vor der Uebersicht war "kontor-shop" die Bestellliste,
     * z. B. in bereits versendeten Betreiber-Mails) auf die neue Seite umleiten.
     */
    public function handle_request(): void
    {
        if (isset($_GET['order']) || isset($_GET['cancel_order']) || isset($_GET['status'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige-Parameter (Weiterleitung alter Links)
            $args = array_diff_key($_GET, ['page' => true]); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige-Parameter (Weiterleitung alter Links)

            wp_safe_redirect(add_query_arg(array_merge($args, ['page' => OrdersPage::SLUG]), admin_url('admin.php')));
            exit;
        }
    }

    public function render(): void
    {
        if (!Capabilities::can_manage() && !Capabilities::is_cashier() && !Capabilities::is_warehouse_manager()) {
            wp_die(esc_html__('No permission.', 'kontor-shop'));
        }

        $snap   = new ShopSnapshot($this->settings);
        $checks = $snap->checks();
        $cards  = self::cards($snap);

        echo '<div class="wrap ks-wrap ks-dashboard">';
        echo '<h1>' . esc_html__('Kontor Shop – Dashboard', 'kontor-shop') . '</h1><hr class="wp-header-end" />';
        Flash::render();

        // Andere Rollen sehen die Ampel nur, wenn etwas nicht stimmt (ohne Technik-Details).
        if ($snap->can_manage || ShopHealth::overall($checks) !== Check::OK) {
            echo DashboardView::health($checks, $snap->can_manage, true); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- DashboardView liefert escaptes HTML
        }

        if ($snap->can_manage) {
            $this->render_setup();
        }

        // Nur Free, einmal: darf unter Mails und Bestellbestaetigung "Vereinsshop mit Kontor Shop" stehen?
        PoweredBy::render_question($this->settings);

        echo '<div class="ks-dash-grid' . ($cards['side'] === [] ? ' ks-dash-grid--single' : '') . '"><div class="ks-dash-main">';

        foreach ($cards['top'] as $render) {
            $render($snap);
        }

        $today = $snap->today();

        echo '<section class="ks-card ks-dash-card"><h2>' . esc_html__('To do today', 'kontor-shop') . '</h2><div class="ks-today ' . esc_attr(Css::cols(count($today))) . '">';

        foreach ($today as $tile) {
            echo '<a class="ks-today__tile' . ($tile['count'] > 0 ? ' is-active' : ' is-zero') . '" href="' . esc_url($tile['url']) . '"><span class="ks-today__n">' . (int) $tile['count'] . '</span>'
                . '<span class="ks-today__l">' . esc_html($tile['label']) . '</span></a>';
        }

        echo '</div></section>';

        foreach ($cards['main'] as $render) {
            $render($snap);
        }

        echo '</div>';

        if ($cards['side'] !== []) {
            echo '<div class="ks-dash-side">';

            foreach ($cards['side'] as $render) {
                $render($snap);
            }

            echo '</div>';
        }

        echo '</div></div>';
    }

    /**
     * Karten von Erweiterungen, nach Bereich (auch fuer das Dashboard-Widget).
     *
     * @return array{top: array<int, callable>, main: array<int, callable>, side: array<int, callable>, widget: array<int, callable>}
     */
    public static function cards(ShopSnapshot $snap): array
    {
        /**
         * Filter kontor_shop/dashboard/cards: weitere Karten der Uebersicht
         * und des Dashboard-Widgets, z. B. Pro: "Das haengt" (oben),
         * "Letzte 30 Tage" (rechts), "Lager" (offene Pakete und knappe
         * Artikel je Lager). Die Karte gibt ihr HTML selbst aus (escapen!)
         * und zeigt nur, was die Rolle betrifft (ShopSnapshot: can_pay,
         * can_ship, can_stock, …). Standard: keine - die Uebersicht zeigt
         * dann Ampel, Einrichtung und "Heute zu tun" in einer Spalte.
         *
         * @param array<int, array{area: string, render: callable(ShopSnapshot): void}> $cards
         *        area: 'top' (vor "Heute zu tun"), 'main' (darunter), 'side'
         *        (rechte Spalte) oder 'widget' (WP-Dashboard-Widget, unter den Zahlen)
         * @param ShopSnapshot $snapshot
         */
        $cards = (array) apply_filters('kontor_shop/dashboard/cards', [], $snap);
        $out   = ['top' => [], 'main' => [], 'side' => [], 'widget' => []];

        foreach ($cards as $card) {
            if (is_array($card) && isset($card['render']) && is_callable($card['render'])) {
                $area = (string) ($card['area'] ?? 'main');

                $out[isset($out[$area]) ? $area : 'main'][] = $card['render'];
            }
        }

        return $out;
    }

    /**
     * Einrichtung - solange Pflichtschritte offen sind; danach nur eine Zeile "Shop bereit".
     */
    private function render_setup(): void
    {
        $checklist = new SetupChecklist($this->settings);

        if ($checklist->open_required() === []) {
            echo '<p class="ks-dash-ready"><span aria-hidden="true">✓</span> ' . esc_html__('Shop ready – customers can order.', 'kontor-shop') . '</p>';

            return;
        }

        $steps = $checklist->steps();
        $done  = count(array_filter($steps, static fn (array $s): bool => $s['done']));

        echo '<section class="ks-card ks-dash-card" id="einrichtung"><h2>' . esc_html__('Set up your shop', 'kontor-shop') . ' <span class="ks-dash-card__count">' . esc_html(sprintf(
            /* translators: 1: done, 2: total */
            __('%1$d of %2$d done', 'kontor-shop'),
            $done,
            count($steps)
        )) . '</span></h2><div class="ks-dash-card__body"><div class="ks-progress"><b class="' . esc_attr(Css::pct($done / max(1, count($steps)) * 100)) . '"></b></div><ul class="ks-checklist">';

        foreach ($steps as $step) {
            echo '<li><span class="ks-check-mark ' . ($step['done'] ? 'is-done' : 'is-todo') . '" aria-hidden="true">' . ($step['done'] ? '✓' : '○') . '</span><span>'
                . ($step['done'] ? esc_html($step['title']) : '<a href="' . esc_url($step['url']) . '"><strong>' . esc_html($step['title']) . '</strong></a>')
                . ($step['required'] && !$step['done'] ? ' <span class="ks-badge ks-badge--bad">' . esc_html__('Required', 'kontor-shop') . '</span>' : '')
                . (!$step['done'] ? '<br /><span class="ks-muted">' . esc_html($step['hint']) . '</span>' : '') . '</span></li>';
        }

        echo '</ul></div></section>';
    }
}
