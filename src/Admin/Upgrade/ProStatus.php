<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin\Upgrade;

use Kontor\Shop\Admin\Settings\Section;
use Kontor\Shop\Core\Edition;
use Kontor\Shop\Core\Registerable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stand nach dem Kauf, gross und in Schritten oben auf jeder Kontor-Seite:
 * "Bestätige deine E-Mail" (Freemius wartet auf den Link aus der Mail) bzw.
 * "Pro jetzt installieren" (Lizenz aktiv, noch Free). Solange auf die Mail
 * gewartet wird, fragt die Seite alle paar Sekunden nach (AJAX) und laedt
 * neu, sobald sich der Stand aendert. Den Stand liefert src/freemius.php
 * (ProOffer::status()); Pläne & Preise zeigt dieselbe Karte.
 */
final class ProStatus implements Registerable
{
    public const AJAX = 'kontor_shop_pro_status';

    /** Option: Pro wurde gerade aktiviert (src/freemius.php) - Willkommen zeigen, bis geschlossen. */
    public const WELCOME = 'kontor_shop_pro_welcome';

    private const WELCOME_DONE = 'kontor_shop_pro_welcome_done';

    /** Webmail je Domain - nur als Abkuerzung ins Postfach. */
    private const WEBMAIL = [
        'gmail.com'      => ['Gmail', 'https://mail.google.com/mail/u/0/#search/freemius'],
        'googlemail.com' => ['Gmail', 'https://mail.google.com/mail/u/0/#search/freemius'],
        'outlook.com'    => ['Outlook', 'https://outlook.live.com/mail/0/'],
        'hotmail.com'    => ['Outlook', 'https://outlook.live.com/mail/0/'],
        'live.com'       => ['Outlook', 'https://outlook.live.com/mail/0/'],
        'gmx.at'         => ['GMX', 'https://www.gmx.at/'],
        'gmx.net'        => ['GMX', 'https://www.gmx.net/'],
        'gmx.de'         => ['GMX', 'https://www.gmx.net/'],
        'web.de'         => ['WEB.DE', 'https://web.de/'],
        'icloud.com'     => ['iCloud Mail', 'https://www.icloud.com/mail'],
        'me.com'         => ['iCloud Mail', 'https://www.icloud.com/mail'],
        'yahoo.com'      => ['Yahoo Mail', 'https://mail.yahoo.com/'],
    ];

    public function register(): void
    {
        if (!is_admin()) {
            return;
        }

        add_action('admin_init', [$this, 'close_welcome']);
        add_action('admin_notices', [$this, 'notice']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('wp_ajax_' . self::AJAX, [$this, 'ajax']);
    }

    /** "welcome", "install", "pending" oder "" */
    public static function state(): string
    {
        if (Edition::is_pro() && get_option(self::WELCOME)) {
            // Nach zwei Wochen nicht mehr - auch wenn niemand auf "Los geht's" geklickt hat.
            return (int) get_option(self::WELCOME) > time() - 14 * DAY_IN_SECONDS ? 'welcome' : '';
        }

        $status = ProOffer::status();

        return $status['install_url'] !== '' ? 'install' : ($status['pending_email'] !== '' ? 'pending' : '');
    }

    public function close_welcome(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce direkt danach geprueft
        if (empty($_GET[self::WELCOME_DONE]) || !current_user_can('install_plugins')) {
            return;
        }

        check_admin_referer(self::WELCOME_DONE);
        delete_option(self::WELCOME);
        wp_safe_redirect(remove_query_arg([self::WELCOME_DONE, '_wpnonce']));
        exit;
    }

    private static function on_kontor_screen(): bool
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        return is_object($screen) && str_contains((string) $screen->id, 'kontor-shop') && current_user_can('install_plugins');
    }

    public function notice(): void
    {
        $screen = get_current_screen();

        // Pläne & Preise zeigt die Karte selbst (innerhalb der Seite).
        if (!self::on_kontor_screen() || str_ends_with((string) $screen->id, PricingPage::SLUG)) {
            return;
        }

        echo self::html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- in html() escaped
    }

    public function enqueue(): void
    {
        if (!self::on_kontor_screen() || self::state() === '') {
            return;
        }

        wp_enqueue_style('kontor-shop-pro-status', KONTOR_SHOP_URL . 'assets/admin/pro-status.css', [], KONTOR_SHOP_VERSION);
        wp_enqueue_script('kontor-shop-pro-status', KONTOR_SHOP_URL . 'assets/admin/pro-status.js', [], KONTOR_SHOP_VERSION, true);
        wp_localize_script('kontor-shop-pro-status', 'kontorShopProStatus', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action'  => self::AJAX,
            'nonce'   => wp_create_nonce(self::AJAX),
            'state'   => self::state(),
            'install' => ProOffer::status()['install_ajax'],
            'done'    => admin_url('admin.php?page=kontor-shop'),
            'i18n'    => [
                'failedTitle' => __('The installation did not work', 'kontor-shop'),
                'unknown'     => __('Unknown error.', 'kontor-shop'),
                'running'     => __('Pro is already being installed in another tab – one moment …', 'kontor-shop'),
            ],
        ]);
    }

    public function ajax(): void
    {
        check_ajax_referer(self::AJAX, 'nonce');

        if (!current_user_can('install_plugins')) {
            wp_send_json_error(null, 403);
        }

        wp_send_json_success(['state' => self::state()]);
    }

    public static function html(): string
    {
        $status = ProOffer::status();

        if (self::state() === 'welcome') {
            return self::welcome($status['licence']);
        }

        if ($status['install_url'] !== '') {
            // Mit JavaScript startet die Installation sofort (pro-status.js); ohne bleibt der Weg ueber Freemius.
            return '<section class="ks-ps ks-ps--done" role="status" aria-live="polite" data-ks-ps-install><span class="ks-ps__icon">' . self::icon('<path d="M20 6 9 17l-5-5"/>') . '</span>'
                . '<div class="ks-ps__body"><span class="ks-ps__eyebrow">' . esc_html__('Licence active', 'kontor-shop') . '</span>'
                . '<h2 data-ks-ps-title>' . esc_html__('Pro is being installed …', 'kontor-shop') . '</h2>'
                . '<p data-ks-ps-text>' . esc_html__('Pro replaces the free version in the background – products, orders and settings stay as they are. This takes a few seconds.', 'kontor-shop') . '</p>'
                . '<p class="ks-ps__actions"><span class="ks-ps__waiting" data-ks-ps-progress><span class="ks-ps__spinner" aria-hidden="true"></span><span data-ks-ps-msg>' . esc_html__('Downloading and installing Pro …', 'kontor-shop') . '</span></span>'
                . '<button type="button" class="ks-ps__btn" data-ks-ps-retry hidden>' . esc_html__('Try again', 'kontor-shop') . '</button>'
                . '<a class="ks-ps__link" data-ks-ps-manual href="' . esc_url($status['install_url']) . '">' . esc_html__('Install via Freemius instead', 'kontor-shop') . '</a></p></div></section>';
        }

        if ($status['pending_email'] === '') {
            return '';
        }

        $email   = $status['pending_email'];
        $webmail = self::WEBMAIL[strtolower((string) substr(strrchr($email, '@') ?: '', 1))] ?? null;
        $steps   = [
            /* translators: %s: e-mail address */
            sprintf(__('Open your mailbox %s.', 'kontor-shop'), '<strong>' . esc_html($email) . '</strong>'),
            esc_html__('Open the e-mail from Freemius about “Kontor Shop” – if it is not there, look in the spam folder.', 'kontor-shop'),
            esc_html__('Click the confirmation button in it. Pro is then installed here – this page updates itself.', 'kontor-shop'),
        ];

        $html = '<section class="ks-ps ks-ps--wait" role="status" data-ks-ps><span class="ks-ps__icon">' . self::icon('<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>') . '</span>'
            . '<div class="ks-ps__body"><span class="ks-ps__eyebrow">' . esc_html__('Purchase complete', 'kontor-shop') . '</span>'
            . '<h2>' . esc_html__('One more step: confirm your e-mail', 'kontor-shop') . '</h2>'
            . '<ol class="ks-ps__steps">';

        foreach ($steps as $step) {
            $html .= '<li>' . $step . '</li>'; // Schritte oben escaped
        }

        $html .= '</ol><p class="ks-ps__actions">';

        if ($webmail !== null) {
            /* translators: %s: webmail name, e.g. Gmail */
            $html .= '<a class="ks-ps__btn" href="' . esc_url($webmail[1]) . '" target="_blank" rel="noopener">' . esc_html(sprintf(__('Open %s', 'kontor-shop'), $webmail[0])) . '</a>';
        }

        $html .= '<span class="ks-ps__waiting"><span class="ks-ps__spinner" aria-hidden="true"></span>' . esc_html__('Waiting for your confirmation …', 'kontor-shop') . '</span></p>'
            . '<p class="ks-ps__alt"><a href="' . esc_url(PricingPage::url() . '#ks-pp-bought') . '">' . esc_html__('No e-mail? Enter your licence key instead', 'kontor-shop') . '</a></p></div></section>';

        return $html;
    }

    /**
     * Willkommen bei Pro: Konfetti, die ersten lohnenden Schritte, Lizenz auf einen Blick.
     *
     * @param array<string, string> $licence
     */
    private static function welcome(array $licence): string
    {
        $tiles = [
            ['<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>', __('Connect the AI assistant', 'kontor-shop'), __('Run the shop by chat – with ChatGPT, Claude or Gemini.', 'kontor-shop'), admin_url('admin.php?page=kontor-shop-ai')],
            ['<path d="M12 2H2v10l9.29 9.29a1 1 0 0 0 1.41 0l8.59-8.59a1 1 0 0 0 0-1.41Z"/><path d="M7 7h.01"/>', __('Set up member prices', 'kontor-shop'), __('Members pay less automatically.', 'kontor-shop'), Section::url('membership')],
            ['<path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2M13 17v2M13 11v2"/>', __('Create a discount code', 'kontor-shop'), __('For the next campaign or the club party.', 'kontor-shop'), admin_url('admin.php?page=kontor-shop-coupons')],
            ['<path d="m12 2 10 5-10 5L2 7Z"/><path d="m2 17 10 5 10-5"/><path d="m2 12 10 5 10-5"/>', __('More warehouses and managers', 'kontor-shop'), __('For example one per regional group.', 'kontor-shop'), admin_url('admin.php?page=kontor-shop-warehouses')],
        ];

        $confetti = '';
        $colors   = ['#e0b25b', '#f3d79f', '#ffffff', '#8fb4e3', '#3fb07a', '#ebc47a'];

        for ($i = 0; $i < 36; $i++) {
            $confetti .= sprintf(
                '<i style="--x:%d%%;--d:%.2fs;--r:%ddeg;--c:%s;--s:%.2f"></i>',
                ($i * 37) % 100,
                ($i % 9) * 0.12,
                ($i * 53) % 360,
                $colors[$i % count($colors)],
                0.7 + (($i * 7) % 6) / 10
            );
        }

        $html = '<section class="ks-pw" role="status"><div class="ks-pw__confetti" aria-hidden="true">' . $confetti . '</div>'
            . '<div class="ks-pw__head"><span class="ks-pw__badge">' . self::icon('<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9Z"/>') . '</span>'
            . '<div><span class="ks-pw__eyebrow">' . esc_html__('Kontor Shop Pro is active', 'kontor-shop') . '</span>'
            . '<h2>' . esc_html__('Welcome to Pro – great choice!', 'kontor-shop') . '</h2>'
            . '<p>' . esc_html__('From now on your shop works for you: less routine, more sales, everything in your club’s name. These first steps pay off right away:', 'kontor-shop') . '</p></div></div>'
            . '<ul class="ks-pw__tiles">';

        foreach ($tiles as [$icon, $title, $text, $url]) {
            $html .= '<li><a href="' . esc_url($url) . '"><span class="ks-pw__tile-icon">' . self::icon($icon) . '</span><strong>' . esc_html($title) . '</strong><span>' . esc_html($text) . '</span></a></li>';
        }

        $html .= '</ul><div class="ks-pw__foot"><span class="ks-pw__licence">';

        if ($licence !== []) {
            $parts = [sprintf(
                /* translators: %s: plan name, e.g. Pro */
                __('Your licence: %s', 'kontor-shop'),
                $licence['plan'] ?? 'Pro'
            )];

            if (($licence['sites'] ?? '') !== '') {
                /* translators: %d: number of websites */
                $parts[] = sprintf(_n('%d website', '%d websites', (int) $licence['sites'], 'kontor-shop'), (int) $licence['sites']);
            }

            $parts[] = ($licence['expires'] ?? '') !== ''
                /* translators: %s: date */
                ? sprintf(__('valid until %s', 'kontor-shop'), date_i18n(_x('F j, Y', 'licence expiry date', 'kontor-shop'), (int) strtotime($licence['expires'])))
                : __('lifetime licence', 'kontor-shop');

            $html .= esc_html(implode(' · ', $parts));

            if (($licence['account_url'] ?? '') !== '') {
                $html .= ' · <a href="' . esc_url($licence['account_url']) . '">' . esc_html__('Licence & invoices', 'kontor-shop') . '</a>';
            }
        }

        $html .= '</span><a class="ks-pw__btn" href="' . esc_url(wp_nonce_url(add_query_arg(self::WELCOME_DONE, 1), self::WELCOME_DONE)) . '">' . esc_html__('Got it, let’s go', 'kontor-shop') . '</a></div></section>';

        return $html;
    }

    private static function icon(string $inner): string
    {
        return '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $inner . '</svg>';
    }
}
