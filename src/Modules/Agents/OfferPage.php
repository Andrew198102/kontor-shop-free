<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Agents;

use Kontor\Shop\Admin\Menu;
use Kontor\Shop\Admin\Settings\Section;
use Kontor\Shop\Admin\Upgrade\ProOffer;
use Kontor\Shop\Core\Capabilities;
use Kontor\Shop\Core\Edition;
use Kontor\Shop\Settings\Repository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Kontor Shop > KI-Assistent in Free: erklaert, was der Assistent mit Pro
 * kann (Beispiel-Chat, drei Nutzen), zeigt ehrlich, was Free schon kann (den
 * Shop-Zugang fuer Kunden, mit Knopf zum Einschalten), und vergleicht Free
 * mit Pro samt Preis aus Freemius (ProOffer). Keine gesperrten
 * Bedienelemente - nur Erklaerung und der Weg zur Kaufseite. Mit Pro
 * ersetzt Pro\Agents\AgentsPage die Seite unter demselben Slug.
 *
 * Nur fuer Personen, die die Einstellungen pflegen (Kassa und Lager sehen
 * keine Werbung). Mobile-first (assets/admin/agents-offer.css).
 */
final class OfferPage
{
    public const SLUG = 'kontor-shop-ai';

    public function __construct(private readonly Repository $settings)
    {
    }

    public function register(): void
    {
        add_action('kontor_shop/admin/menu', [$this, 'add_menu_page'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function add_menu_page(Menu $menu, string $group): void
    {
        if ($group !== Menu::GROUP_SETUP || Edition::is_pro()) {
            return;
        }

        $menu->add_page(self::SLUG, __('AI assistant', 'kontor-shop'), Menu::SETTINGS_CAPABILITY, [$this, 'render']);
    }

    public function enqueue_assets(string $hook_suffix): void
    {
        if (Edition::is_pro() || !str_ends_with($hook_suffix, self::SLUG)) {
            return;
        }

        wp_enqueue_style('kontor-shop-agents-offer', KONTOR_SHOP_URL . 'assets/admin/agents-offer.css', ['kontor-shop-admin'], KONTOR_SHOP_VERSION);
    }

    public static function url(): string
    {
        return add_query_arg(['page' => self::SLUG], admin_url('admin.php'));
    }

    public function render(): void
    {
        if (!Capabilities::can_settings()) {
            wp_die(esc_html__('No permission.', 'kontor-shop'));
        }

        $offer = ProOffer::get();

        echo '<div class="wrap ks-wrap ks-aio"><h1 class="screen-reader-text">' . esc_html__('AI assistant', 'kontor-shop') . '</h1><hr class="wp-header-end" />';
        $this->render_hero();
        $this->render_benefits();
        $this->render_free();
        $this->render_plans($offer);
        echo '</div>';
    }

    private function render_hero(): void
    {
        echo '<section class="ks-aio-hero"><div class="ks-aio-hero__text">';
        echo '<span class="ks-aio-eyebrow">' . esc_html__('Kontor Shop Pro', 'kontor-shop') . '</span>';
        echo '<h2 class="ks-aio-hero__title">' . esc_html__('Your shop, run by chat.', 'kontor-shop') . '</h2>';
        echo '<p class="ks-aio-hero__lead">' . esc_html__('Just tell ChatGPT, Claude or Gemini what to do – create products with text and image, book stock, work through orders. On your computer or your phone, with your permissions.', 'kontor-shop') . '</p>';
        echo '<div class="ks-aio-hero__actions">'
            . '<a class="ks-aio-btn ks-aio-btn--gold" href="#ks-aio-plan">' . esc_html__('Unlock Pro', 'kontor-shop') . '</a>'
            . '<a class="ks-aio-btn ks-aio-btn--ghost" href="#ks-aio-free">' . esc_html__('What Free already does', 'kontor-shop') . '</a></div>';
        echo '<div class="ks-aio-works">' . esc_html__('Works with', 'kontor-shop');

        foreach (['chatgpt' => 'ChatGPT', 'claude' => 'Claude', 'gemini' => 'Gemini'] as $app => $name) {
            echo '<span class="ks-aio-chip">' . AppLogos::svg($app, 16) . esc_html($name) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- festes SVG aus AppLogos
        }

        echo '</div></div>';

        echo '<div class="ks-aio-chat" role="img" aria-label="' . esc_attr__('Example chat', 'kontor-shop') . '">';
        echo '<p class="ks-aio-msg ks-aio-msg--me">' . esc_html__('Create a club scarf for €19.90, sizes kids and adults, 10 of each. Write a nice description for it.', 'kontor-shop') . '</p>';
        echo '<div class="ks-aio-msg ks-aio-msg--ai"><span>' . esc_html__('Done – still hidden, so you can check it first:', 'kontor-shop') . '</span>'
            . '<span class="ks-aio-result"><strong>' . esc_html__('Club scarf “Club blue” · €19.90', 'kontor-shop') . '</strong>'
            . '<span>' . esc_html__('Kids 10 · Adults 10 · Category fan items', 'kontor-shop') . '</span>'
            . '<span class="ks-aio-result__ok">' . esc_html__('✓ Product created · ✓ Stock booked', 'kontor-shop') . '</span></span>'
            . '<span>' . esc_html__('Shall I make it visible?', 'kontor-shop') . '</span></div>';
        echo '<p class="ks-aio-msg ks-aio-msg--me">' . esc_html__('Yes, please.', 'kontor-shop') . '</p>';
        echo '</div></section>';
    }

    private function render_benefits(): void
    {
        $cards = [
            [
                'M12 20h9|M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z',
                __('Products in minutes', 'kontor-shop'),
                __('Create products with text, image, sizes and prices – the assistant writes the description too.', 'kontor-shop'),
            ],
            [
                'M21 8 12 3 3 8v8l9 5 9-5Z|M3 8l9 5 9-5|M12 13v8',
                __('Stock & orders', 'kontor-shop'),
                __('“What has to go out today?” – book incoming goods, record payments, register shipments with tracking number.', 'kontor-shop'),
            ],
            [
                'M12 3 5 6v5c0 4.5 3 8.5 7 10 4-1.5 7-5.5 7-10V6Z|m9 12 2 2 4-4',
                __('Safe for the whole team', 'kontor-shop'),
                __('Everyone with their own access and permissions. Everything logged, deleting only after asking back.', 'kontor-shop'),
            ],
        ];

        echo '<section class="ks-aio-benefits" aria-label="' . esc_attr__('What Pro does', 'kontor-shop') . '">';

        foreach ($cards as [$icon, $title, $text]) {
            echo '<div class="ks-aio-benefit"><span class="ks-aio-benefit__icon">' . self::icon($icon) . '</span>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- festes SVG
                . '<h3>' . esc_html($title) . '</h3><p>' . esc_html($text) . '</p></div>';
        }

        echo '</section>';
    }

    private function render_free(): void
    {
        $enabled = AgentsModule::shop_enabled($this->settings);

        echo '<section class="ks-aio-free" id="ks-aio-free"><div class="ks-aio-free__text">'
            . '<span class="ks-aio-free__label">' . esc_html__('Already included in Free', 'kontor-shop') . '</span>'
            . '<strong>' . esc_html__('Customers find your products through their AI assistant', 'kontor-shop') . '</strong>'
            . '<span>' . esc_html__('Search, prices and availability for ChatGPT, Claude & co. – public data only, no login needed.', 'kontor-shop') . '</span></div>';
        echo '<span class="ks-aio-pill ' . ($enabled ? 'is-on' : '') . '"><span class="ks-aio-dot" aria-hidden="true"></span>'
            . esc_html($enabled ? __('Shop access is on', 'kontor-shop') : __('Shop access is off', 'kontor-shop')) . '</span>';
        echo '<a class="ks-aio-btn ks-aio-btn--line" href="' . esc_url(Section::url('agents')) . '">'
            . esc_html($enabled ? __('Settings', 'kontor-shop') : __('Switch on now', 'kontor-shop')) . '</a>';
        echo '</section>';
    }

    /** @param array{url: string, annual: string, lifetime: string} $offer */
    private function render_plans(array $offer): void
    {
        $checkout = $offer['annual'] !== '' || $offer['lifetime'] !== '';

        echo '<section class="ks-aio-plans" id="ks-aio-plan" aria-label="' . esc_attr__('Free and Pro compared', 'kontor-shop') . '">';

        echo '<div class="ks-aio-plan"><h3>' . esc_html__('Free', 'kontor-shop') . '</h3>'
            . '<span class="ks-aio-price">' . esc_html__('€0', 'kontor-shop') . '</span><ul>'
            . '<li>' . esc_html__('Club shop with variants, stock, bank transfer & pickup', 'kontor-shop') . '</li>'
            . '<li>' . esc_html__('Customers’ AI assistants find your products', 'kontor-shop') . '</li>'
            . '<li>' . esc_html__('One warehouse', 'kontor-shop') . '</li>'
            . '</ul><span class="ks-aio-plan__foot">' . esc_html__('Your current plan', 'kontor-shop') . '</span></div>';

        echo '<div class="ks-aio-plan ks-aio-plan--pro"><span class="ks-aio-badge">' . esc_html__('Recommended', 'kontor-shop') . '</span><h3>' . esc_html__('Pro', 'kontor-shop') . '</h3>';

        if ($offer['annual'] !== '') {
            /* translators: %s: price, e.g. "129 €" */
            echo '<span class="ks-aio-price">' . esc_html(sprintf(__('from %s', 'kontor-shop'), $offer['annual'])) . ' <small>' . esc_html__('/ year', 'kontor-shop') . ' · ' . esc_html__('plus VAT', 'kontor-shop') . '</small></span>';
        }

        if ($offer['lifetime'] !== '') {
            /* translators: %s: price, e.g. "389 €" */
            echo '<span class="ks-aio-price__alt">' . esc_html(sprintf(__('or once from %s – lifetime licence', 'kontor-shop'), $offer['lifetime'])) . '</span>';
        }

        echo '<ul>'
            . '<li><strong>' . esc_html__('Run the shop by chat', 'kontor-shop') . '</strong> – ChatGPT, Claude, Gemini</li>'
            . '<li>' . esc_html__('Cart & checkout link for customers’ AI assistants', 'kontor-shop') . '</li>'
            . '<li>' . esc_html__('Member prices, several warehouses with warehouse managers', 'kontor-shop') . '</li>'
            . '<li>' . esc_html__('Discount codes, payment reminders, packing on the phone', 'kontor-shop') . '</li>'
            . '<li>' . esc_html__('Updates & support', 'kontor-shop') . '</li></ul>';
        echo '<a class="ks-aio-btn ks-aio-btn--brand" href="' . esc_url($offer['url']) . '"' . ($checkout ? '' : ' target="_blank" rel="noopener"') . '>'
            . esc_html__('See plans & prices', 'kontor-shop') . '</a>';
        echo '<span class="ks-aio-plan__foot">' . esc_html(
            $checkout
                ? __('Secure payment via Freemius · Pro download and licence straight after purchase', 'kontor-shop')
                : __('Opens the Kontor Shop website', 'kontor-shop')
        ) . '</span></div>';

        echo '</section>';
    }

    /** Strichsymbol (24er Raster); Pfade mit | getrennt. */
    private static function icon(string $paths): string
    {
        $svg = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">';

        foreach (explode('|', $paths) as $d) {
            $svg .= '<path d="' . esc_attr($d) . '"/>';
        }

        return $svg . '</svg>';
    }
}
