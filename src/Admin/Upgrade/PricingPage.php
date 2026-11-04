<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin\Upgrade;

use Kontor\Shop\Admin\ProductsPage;
use Kontor\Shop\Admin\Settings\Section;
use Kontor\Shop\Core\Edition;
use Kontor\Shop\Core\Registerable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * "Pläne & Preise": ersetzt die englische Freemius-Kaufseite (Upgrade) durch
 * eine eigene auf Deutsch - Warum Pro, Free/Pro-Vergleich, Pakete mit
 * Jahres-/Einmalpreis (live aus Freemius, ProOffer), So geht's, Fragen.
 * "Jetzt kaufen" oeffnet den Freemius-Checkout im Admin; danach aktiviert
 * Freemius die Lizenz und installiert Pro selbst. src/freemius.php haengt
 * html() an den Freemius-Filter templates/pricing.php.
 *
 * Beim ersten Aktivieren fuehrt Freemius (first-path) auf die Uebersicht mit
 * kontor_shop_welcome=1; in Free leiten wir von dort hierher um und zeigen
 * oben "Willkommen" mit den ersten Schritten - mit Pro bleibt es bei der
 * Uebersicht. Mobile-first (assets/admin/pricing.css/.js), ohne JavaScript
 * voll lesbar (Jahrespreise, Kauf-Links fuer jaehrlich).
 */
final class PricingPage implements Registerable
{
    public const SLUG = 'kontor-shop-pricing';

    private const WELCOME = 'kontor_shop_welcome';

    public function register(): void
    {
        if (!is_admin()) {
            return;
        }

        add_action('admin_init', [$this, 'welcome_redirect']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public static function url(array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('admin.php'));
    }

    public function welcome_redirect(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Weiterleitung, keine Aenderung
        if (($_GET['page'] ?? '') !== 'kontor-shop' || !isset($_GET[self::WELCOME]) || Edition::is_pro() || wp_doing_ajax()) {
            return;
        }

        wp_safe_redirect(self::url([self::WELCOME => 1]));
        exit;
    }

    public function enqueue(string $hook_suffix): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige
        if (!str_ends_with($hook_suffix, self::SLUG) || isset($_GET['checkout'])) {
            return;
        }

        wp_enqueue_style('kontor-shop-pricing', KONTOR_SHOP_URL . 'assets/admin/pricing.css', [], KONTOR_SHOP_VERSION);
        wp_enqueue_script('kontor-shop-pricing', KONTOR_SHOP_URL . 'assets/admin/pricing.js', [], KONTOR_SHOP_VERSION, true);
    }

    /** @param array{url: string, annual: string, lifetime: string, packages: array<int, array<string, mixed>>, refund_days: int, blocks_after_expiry: ?bool, account_url: string} $offer */
    public static function html(array $offer): string
    {
        ob_start();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige
        $welcome = isset($_GET[self::WELCOME]) && !Edition::is_pro();

        echo '<div class="wrap ks-pp" data-cycle="annual"><h1 class="screen-reader-text">' . esc_html__('Plans & pricing', 'kontor-shop') . '</h1><hr class="wp-header-end" />';

        if ($welcome) {
            self::welcome();
        }

        self::hero($offer);
        self::why();
        self::compare();
        self::plans($offer);
        self::steps();
        self::bought($offer);
        self::faq($offer);
        echo '</div>';

        return (string) ob_get_clean();
    }

    private static function welcome(): void
    {
        echo '<section class="ks-pp-welcome"><span class="ks-pp-welcome__icon">' . self::icon('<path d="M20 6 9 17l-5-5"/>', 22) . '</span>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- festes SVG
            . '<div class="ks-pp-welcome__text"><h2>' . esc_html__('Welcome to Kontor Shop – your shop is ready.', 'kontor-shop') . '</h2>'
            . '<p>' . esc_html__('The Shop, Cart and Checkout pages are created. Two steps and you can sell:', 'kontor-shop') . '</p></div>'
            . '<div class="ks-pp-welcome__actions">'
            . '<a class="ks-pp-btn ks-pp-btn--navy" href="' . esc_url(Section::url('payment')) . '">' . esc_html__('1. Enter bank details', 'kontor-shop') . '</a>'
            . '<a class="ks-pp-btn ks-pp-btn--line" href="' . esc_url(ProductsPage::url(['do' => 'new'])) . '">' . esc_html__('2. Add first product', 'kontor-shop') . '</a>'
            . '</div></section>';
    }

    /** @param array<string, mixed> $offer */
    private static function hero(array $offer): void
    {
        echo '<section class="ks-pp-hero"><div class="ks-pp-hero__text">';
        echo '<span class="ks-pp-eyebrow">' . self::icon('<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9Z"/>', 14) . esc_html__('Kontor Shop Pro', 'kontor-shop') . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- festes SVG
        echo '<h2 class="ks-pp-hero__title">' . esc_html__('Get the most out of your club shop.', 'kontor-shop') . '</h2>';
        echo '<p class="ks-pp-hero__lead">' . esc_html__('Pro takes the routine off your hands – reminders, packing, stock – and brings more sales with member prices, discount campaigns and back-in-stock notifications. And you can run the whole shop by chat with ChatGPT, Claude or Gemini.', 'kontor-shop') . '</p>';
        echo '<div class="ks-pp-hero__actions"><a class="ks-pp-btn ks-pp-btn--gold" href="#ks-pp-plans">' . esc_html__('See plans', 'kontor-shop') . '</a>'
            . '<a class="ks-pp-btn ks-pp-btn--ghost" href="#ks-pp-why">' . esc_html__('Why Pro?', 'kontor-shop') . '</a></div>';

        $facts = [];

        if ($offer['annual'] !== '') {
            /* translators: %s: price, e.g. "129 €" */
            $facts[] = sprintf(__('from %s a year plus VAT', 'kontor-shop'), $offer['annual']);
        }

        if ($offer['refund_days'] > 0) {
            /* translators: %d: number of days */
            $facts[] = sprintf(_n('%d-day money-back guarantee', '%d-day money-back guarantee', $offer['refund_days'], 'kontor-shop'), $offer['refund_days']);
        }

        $facts[] = __('your data stays', 'kontor-shop');
        echo '<p class="ks-pp-hero__facts">' . esc_html(implode(' · ', $facts)) . '</p></div>';

        $feed = [
            ['<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>', __('Product “Club scarf” created by chat', 'kontor-shop'), __('via ChatGPT · just now', 'kontor-shop')],
            ['<path d="M12 2H2v10l9.29 9.29a1 1 0 0 0 1.41 0l8.59-8.59a1 1 0 0 0 0-1.41Z"/><path d="M7 7h.01"/>', __('Member price applied: −10 %', 'kontor-shop'), __('Order 1042', 'kontor-shop')],
            ['<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>', __('Back in stock – 3 customers notified', 'kontor-shop'), __('Hoodie navy, size L', 'kontor-shop')],
            ['<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>', __('Payment reminder sent automatically', 'kontor-shop'), __('Order 1038', 'kontor-shop')],
        ];

        echo '<div class="ks-pp-feed" aria-hidden="true">';

        foreach ($feed as $i => [$icon, $title, $meta]) {
            echo '<div class="ks-pp-toast" style="--i:' . (int) $i . '"><span class="ks-pp-toast__icon">' . self::icon($icon, 18) . '</span>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- festes SVG
                . '<span class="ks-pp-toast__text"><strong>' . esc_html($title) . '</strong><small>' . esc_html($meta) . '</small></span>'
                . '<span class="ks-pp-toast__ok">' . self::icon('<path d="M20 6 9 17l-5-5"/>', 14) . '</span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- festes SVG
        }

        echo '</div></section>';
    }

    private static function why(): void
    {
        $cards = [
            ['<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>', __('Saves time', 'kontor-shop'), __('Less routine, more club.', 'kontor-shop'), [
                __('Run the shop by chat – ChatGPT, Claude, Gemini', 'kontor-shop'),
                __('Pack on the phone, straight from the order mail', 'kontor-shop'),
                __('Payment reminders and cancelling unpaid orders – automatic', 'kontor-shop'),
            ]],
            ['<path d="m22 7-8.5 8.5-5-5L2 17"/><path d="M16 7h6v6"/>', __('More sales', 'kontor-shop'), __('Happier members, fuller cart.', 'kontor-shop'), [
                __('Member prices – members pay less automatically', 'kontor-shop'),
                __('Discount codes and campaigns', 'kontor-shop'),
                __('Back-in-stock notifications bring customers back', 'kontor-shop'),
            ]],
            ['<path d="m12 2 10 5-10 5L2 7Z"/><path d="m2 17 10 5 10-5"/><path d="m2 12 10 5 10-5"/>', __('Grows with you', 'kontor-shop'), __('Ready for the whole association.', 'kontor-shop'), [
                __('Several warehouses with their own managers', 'kontor-shop'),
                __('Cashier interface for club events', 'kontor-shop'),
                __('Key figures, alarm mail and CSV export', 'kontor-shop'),
            ]],
            ['<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/><path d="m9 12 2 2 4-4"/>', __('Professional', 'kontor-shop'), __('Safe – and entirely in your name.', 'kontor-shop'), [
                __('Tamper-proof change log', 'kontor-shop'),
                __('Product data for Google, category pages', 'kontor-shop'),
                __('No “powered by” – plus updates and e-mail support', 'kontor-shop'),
            ]],
        ];

        echo '<section class="ks-pp-section" id="ks-pp-why"><header class="ks-pp-head"><h2>' . esc_html__('Why Pro?', 'kontor-shop') . '</h2>'
            . '<p>' . esc_html__('Four reasons clubs switch – and never go back.', 'kontor-shop') . '</p></header><div class="ks-pp-why">';

        foreach ($cards as [$icon, $title, $claim, $points]) {
            echo '<article class="ks-pp-card ks-pp-reveal"><span class="ks-pp-card__icon">' . self::icon($icon, 22) . '</span>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- festes SVG
                . '<h3>' . esc_html($title) . '</h3><p class="ks-pp-card__claim">' . esc_html($claim) . '</p><ul class="ks-pp-checks">';

            foreach ($points as $point) {
                echo '<li>' . self::icon('<path d="M20 6 9 17l-5-5"/>', 16) . '<span>' . esc_html($point) . '</span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- festes SVG
            }

            echo '</ul></article>';
        }

        echo '</div></section>';
    }

    private static function compare(): void
    {
        $free = [
            __('Club shop with variants, stock, bank transfer and pickup', 'kontor-shop'),
            __('Customers’ AI assistants find your products', 'kontor-shop'),
        ];
        $pro  = [
            __('Run the shop by chat', 'kontor-shop'),
            __('Member prices', 'kontor-shop'),
            __('Several warehouses with warehouse managers', 'kontor-shop'),
            __('Discount codes and campaigns', 'kontor-shop'),
            __('Packing on the phone, partial shipments', 'kontor-shop'),
            __('Back-in-stock notifications', 'kontor-shop'),
            __('Automatic payment reminders', 'kontor-shop'),
            __('Key figures, alarm mail, CSV export', 'kontor-shop'),
            __('Tamper-proof change log', 'kontor-shop'),
            __('Cashier interface', 'kontor-shop'),
            __('Search-engine extras', 'kontor-shop'),
            __('Without “powered by”', 'kontor-shop'),
            __('E-mail support', 'kontor-shop'),
        ];
        $check = self::icon('<path d="M20 6 9 17l-5-5"/>', 14);

        echo '<section class="ks-pp-section"><header class="ks-pp-head"><h2>' . esc_html__('Free and Pro compared', 'kontor-shop') . '</h2></header><div class="ks-pp-compare">';

        echo '<div class="ks-pp-compare__free ks-pp-reveal"><span class="ks-pp-compare__label">' . esc_html__('Free', 'kontor-shop') . '</span>'
            . '<h3>' . esc_html__('Everything to get started', 'kontor-shop') . '</h3><ul>';

        foreach ($free as $item) {
            echo '<li>' . $check . '<span>' . esc_html($item) . '</span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- festes SVG
        }

        echo '</ul></div><div class="ks-pp-compare__pro ks-pp-reveal"><span class="ks-pp-compare__label">' . esc_html__('Pro', 'kontor-shop') . '</span>'
            . '<h3>' . esc_html__('Everything in Free – plus:', 'kontor-shop') . '</h3><ul>';

        foreach ($pro as $item) {
            echo '<li>' . $check . '<span>' . esc_html($item) . '</span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- festes SVG
        }

        echo '</ul></div></div></section>';
    }

    /** @param array<string, mixed> $offer */
    private static function plans(array $offer): void
    {
        $packages = $offer['packages'];

        echo '<section class="ks-pp-section ks-pp-plans" id="ks-pp-plans"><header class="ks-pp-head"><h2>' . esc_html__('Choose your plan', 'kontor-shop') . '</h2>'
            . '<p>' . esc_html__('Everything in Pro is included in every plan – you only choose how many websites.', 'kontor-shop') . '</p></header>';

        if ($packages === []) {
            echo '<div class="ks-pp-card ks-pp-fallback"><p>' . esc_html__('The current plans and prices are on our website.', 'kontor-shop') . '</p>'
                . '<a class="ks-pp-btn ks-pp-btn--navy" href="' . esc_url($offer['url']) . '" target="_blank" rel="noopener">' . esc_html__('See plans & prices', 'kontor-shop') . '</a></div></section>';

            return;
        }

        $single   = $packages[0];
        $has_life = array_filter($packages, static fn (array $p): bool => $p['lifetime'] > 0) !== [];

        if ($has_life) {
            $years = $single['annual'] > 0 && $single['lifetime'] > 0 ? (int) round($single['lifetime'] / $single['annual']) : 0;

            echo '<div class="ks-pp-toggle" role="group" aria-label="' . esc_attr__('Payment', 'kontor-shop') . '">'
                . '<button type="button" class="ks-pp-toggle__btn" data-ks-pp-cycle="annual" aria-pressed="true">' . esc_html__('Yearly', 'kontor-shop') . '</button>'
                . '<button type="button" class="ks-pp-toggle__btn" data-ks-pp-cycle="lifetime" aria-pressed="false">' . esc_html_x('Once', 'payment', 'kontor-shop')
                . ($years > 1 ? ' <span class="ks-pp-toggle__hint">' . esc_html(sprintf(
                    /* translators: %d: number of years */
                    _n('price of %d year, for life', 'price of %d years, for life', $years, 'kontor-shop'),
                    $years
                )) . '</span>' : '') . '</button></div>';
        }

        echo '<div class="ks-pp-packages">';

        foreach ($packages as $index => $package) {
            $licenses = (int) $package['licenses'];
            $save     = $licenses > 1 && $single['annual'] > 0 && $package['annual'] > 0 ? (int) floor(100 - ($package['annual'] / $licenses) / $single['annual'] * 100) : 0;

            echo '<article class="ks-pp-package ks-pp-reveal' . ($index === 0 ? ' is-featured' : '') . '">';

            if ($index === 0) {
                echo '<span class="ks-pp-badge">' . esc_html__('Recommended', 'kontor-shop') . '</span>';
            } elseif ($save >= 5) {
                /* translators: %d: percent */
                echo '<span class="ks-pp-badge ks-pp-badge--soft">' . esc_html(sprintf(__('Save %d %% per website', 'kontor-shop'), $save)) . '</span>';
            }

            /* translators: %d: number of websites */
            echo '<h3>' . esc_html(sprintf(_n('%d website', '%d websites', $licenses, 'kontor-shop'), $licenses)) . '</h3>';
            echo '<p class="ks-pp-package__for">' . esc_html($licenses === 1 ? __('For one club.', 'kontor-shop') : __('For associations with regional groups or several clubs.', 'kontor-shop')) . '</p>';

            echo '<div class="ks-pp-price" data-ks-pp-show="annual"><span class="ks-pp-price__amount">' . esc_html(ProOffer::money($package['annual'], $package['currency'])) . '</span><span class="ks-pp-price__per">' . esc_html__('/ year', 'kontor-shop') . '</span>'
                /* translators: %s: monthly amount */
                . '<span class="ks-pp-price__note">' . esc_html(sprintf(__('plus VAT · that’s %s a month · cancel any time', 'kontor-shop'), ProOffer::money(round($package['annual'] / 12, 2), $package['currency']))) . '</span></div>';

            if ($package['lifetime'] > 0) {
                echo '<div class="ks-pp-price" data-ks-pp-show="lifetime"><span class="ks-pp-price__amount">' . esc_html(ProOffer::money($package['lifetime'], $package['currency'])) . '</span><span class="ks-pp-price__per">' . esc_html__('once', 'kontor-shop') . '</span>'
                    . '<span class="ks-pp-price__note">' . esc_html__('plus VAT · pay once – updates and support for life', 'kontor-shop') . '</span></div>';
            }

            echo '<a class="ks-pp-btn ' . ($index === 0 ? 'ks-pp-btn--gold' : 'ks-pp-btn--navy') . ' ks-pp-buy" href="' . esc_url((string) $package['buy_annual']) . '" data-annual="' . esc_url((string) $package['buy_annual']) . '" data-lifetime="' . esc_url((string) $package['buy_lifetime']) . '">'
                . esc_html__('Get Pro now', 'kontor-shop') . '</a>';
            echo '<p class="ks-pp-package__all">' . self::icon('<path d="M20 6 9 17l-5-5"/>', 14) . esc_html__('All Pro features included', 'kontor-shop') . '</p></article>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- festes SVG
        }

        echo '</div><ul class="ks-pp-trust">'
            . '<li>' . self::icon('<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>', 16) . esc_html__('Secure payment via Freemius', 'kontor-shop') . '</li>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- festes SVG
            . '<li>' . self::icon('<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>', 16) . esc_html__('Credit card and PayPal', 'kontor-shop') . '</li>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- festes SVG
            . '<li>' . self::icon('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h5"/>', 16) . esc_html__('Invoice with VAT for your club', 'kontor-shop') . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- festes SVG

        if ($offer['refund_days'] > 0) {
            /* translators: %d: number of days */
            echo '<li>' . self::icon('<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>', 16) . esc_html(sprintf(_n('%d-day money-back guarantee', '%d-day money-back guarantee', $offer['refund_days'], 'kontor-shop'), $offer['refund_days'])) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- festes SVG
        }

        echo '</ul></section>';
    }

    private static function steps(): void
    {
        $steps = [
            [__('Choose a plan', 'kontor-shop'), __('One or more websites, yearly or once.', 'kontor-shop')],
            [__('Pay securely', 'kontor-shop'), __('Checkout by Freemius, right here in your dashboard – by credit card or PayPal.', 'kontor-shop')],
            [__('Pro installs itself', 'kontor-shop'), __('Licence activated, Pro installed – products, orders and settings stay as they are.', 'kontor-shop')],
        ];

        echo '<section class="ks-pp-section"><header class="ks-pp-head"><h2>' . esc_html__('How it works', 'kontor-shop') . '</h2></header><ol class="ks-pp-steps">';

        foreach ($steps as [$title, $text]) {
            echo '<li class="ks-pp-reveal"><strong>' . esc_html($title) . '</strong><span>' . esc_html($text) . '</span></li>';
        }

        echo '</ol></section>';
    }

    /** @param array<string, mixed> $offer */
    private static function bought(array $offer): void
    {
        if ($offer['account_url'] === '') {
            return;
        }

        echo '<section class="ks-pp-bought ks-pp-reveal"><div><strong>' . esc_html__('Already bought Pro?', 'kontor-shop') . '</strong>'
            . '<p>' . esc_html__('Download Pro in your Freemius account, upload it under Plugins → Add New and enter your licence key. Your data stays.', 'kontor-shop') . '</p></div>'
            . '<a class="ks-pp-btn ks-pp-btn--line" href="' . esc_url($offer['account_url']) . '" target="_blank" rel="noopener">' . esc_html__('Open my account', 'kontor-shop') . '</a></section>';
    }

    /** @param array<string, mixed> $offer */
    private static function faq(array $offer): void
    {
        $after = __('The licence renews automatically; you can cancel any time in your Freemius account.', 'kontor-shop');

        if ($offer['blocks_after_expiry'] === true) {
            $after .= ' ' . __('If it ends, the Pro features switch off – your shop keeps running as Free and all data stays.', 'kontor-shop');
        } elseif ($offer['blocks_after_expiry'] === false) {
            $after .= ' ' . __('If it ends, Pro keeps working – only updates and support stop.', 'kontor-shop');
        }

        $faq = [
            [__('Will my data stay when I switch to Pro?', 'kontor-shop'), __('Yes. Free and Pro use the same database – products, orders, customers and settings stay as they are. Going back to Free loses nothing either.', 'kontor-shop')],
            [__('What happens after a year?', 'kontor-shop'), $after],
        ];

        if ($offer['packages'] !== [] && $offer['packages'][0]['lifetime'] > 0) {
            $first = $offer['packages'][0];
            $faq[] = [
                __('Yearly or once – what is the difference?', 'kontor-shop'),
                sprintf(
                    /* translators: 1: yearly price, 2: one-time price */
                    __('Yearly: %1$s a year including updates and support. Once: %2$s – pay once, updates and support for life. Prices plus VAT; the checkout shows the amount for your country.', 'kontor-shop'),
                    ProOffer::money($first['annual'], $first['currency']),
                    ProOffer::money($first['lifetime'], $first['currency'])
                ),
            ];
        }

        $faq[] = [__('Do I get an invoice for the club?', 'kontor-shop'), __('Yes, with VAT shown. Freemius is the seller and issues the invoice – you enter the club name and VAT number at checkout.', 'kontor-shop')];
        $faq[] = [__('Can I add more websites later?', 'kontor-shop'), __('Yes, any time from your Freemius account.', 'kontor-shop')];

        if ($offer['refund_days'] > 0) {
            $faq[] = [
                __('Can I get my money back?', 'kontor-shop'),
                /* translators: %d: number of days */
                sprintf(_n('Yes – within %d day, no questions asked.', 'Yes – within %d days, no questions asked.', $offer['refund_days'], 'kontor-shop'), $offer['refund_days']),
            ];
        }

        echo '<section class="ks-pp-section"><header class="ks-pp-head"><h2>' . esc_html__('Questions', 'kontor-shop') . '</h2></header><div class="ks-pp-faq">';

        foreach ($faq as $i => [$question, $answer]) {
            echo '<details class="ks-pp-reveal"' . ($i === 0 ? ' open' : '') . '><summary>' . esc_html($question) . '</summary><p>' . esc_html($answer) . '</p></details>';
        }

        echo '</div></section>';
    }

    /** Strichsymbol im 24er Raster (fester Inhalt, nie Benutzereingaben). */
    private static function icon(string $inner, int $size): string
    {
        return '<svg class="ks-pp-icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $inner . '</svg>';
    }
}
