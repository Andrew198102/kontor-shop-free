<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Notifications;

use Kontor\Shop\Admin\DashboardPage;
use Kontor\Shop\Admin\Menu;
use Kontor\Shop\Admin\SetupChecklist;
use Kontor\Shop\Admin\Ui\Flash;
use Kontor\Shop\Core\Edition;
use Kontor\Shop\Core\Registerable;
use Kontor\Shop\Settings\Repository;

/**
 * Freiwilliger Hinweis "Vereinsshop mit Kontor Shop" (nur Free, nur nach
 * Zustimmung - WordPress.org-Richtlinie 10): eine kleine Zeile unter den
 * Shop-Mails (kontor_shop/notifications/footer) und unter der
 * Bestellbestaetigung (kontor_shop/checkout/confirmation_footer), sonst
 * nirgends auf der Website.
 *
 * Einstellung general.powered_by: null = noch nicht gefragt, true/false =
 * beantwortet. Gefragt wird einmal (Karte auf der Shop-Uebersicht und
 * Hinweis auf WP-Dashboard/Plugin-Liste), sobald die Pflichtschritte der
 * Einrichtung erledigt sind; danach Schalter unter Einstellungen →
 * E-Mail-Versand. In Pro gibt es weder Frage noch Zeile.
 */
final class PoweredBy implements Registerable
{
    public const URL = 'https://veyra-assistant.com/kontor-shop/';

    public const KEY = 'general.powered_by';

    private const ACTION = 'kontor_shop_powered_by';

    public function __construct(private readonly Repository $settings)
    {
    }

    public function register(): void
    {
        add_filter('kontor_shop/notifications/footer', [$this, 'mail_footer']);
        add_filter('kontor_shop/checkout/confirmation_footer', [$this, 'confirmation_footer']);
        add_action('admin_post_' . self::ACTION, [$this, 'handle_answer']);
    }

    /** null = noch nicht gefragt, sonst die Antwort. */
    public static function answer(Repository $settings): ?bool
    {
        $value = $settings->get(self::KEY, null);

        return $value === null ? null : (bool) $value;
    }

    /** Zeile zeigen: nur Free und nur nach "Ja". */
    public static function active(Repository $settings): bool
    {
        return !Edition::is_pro() && self::answer($settings) === true;
    }

    /** Fragen: Free, noch nicht beantwortet, Einrichtung fertig, Recht auf Einstellungen. */
    public static function should_ask(Repository $settings): bool
    {
        return !Edition::is_pro()
            && self::answer($settings) === null
            && current_user_can(Menu::SETTINGS_CAPABILITY)
            && (new SetupChecklist($settings))->open_required() === [];
    }

    public static function text(): string
    {
        return __('Club shop with Kontor Shop', 'kontor-shop');
    }

    /**
     * @param array{html?: string, text?: string} $footer
     * @return array{html: string, text: string}
     */
    public function mail_footer(mixed $footer): array
    {
        $footer = ['html' => (string) ($footer['html'] ?? ''), 'text' => (string) ($footer['text'] ?? '')];

        if (!self::active($this->settings)) {
            return $footer;
        }

        $link = '<a href="' . esc_url(self::URL) . '" style="color:#6b7280;">' . esc_html(self::text()) . '</a>';

        return [
            'html' => $footer['html'] !== '' ? $footer['html'] . '<br />' . $link : $link,
            'text' => ($footer['text'] !== '' ? $footer['text'] . "\n" : '') . self::text() . ': ' . self::URL,
        ];
    }

    public function confirmation_footer(mixed $html): string
    {
        $html = (string) $html;

        if (!self::active($this->settings)) {
            return $html;
        }

        return $html . '<a href="' . esc_url(self::URL) . '" target="_blank" rel="noopener">' . esc_html(self::text()) . '</a>';
    }

    /**
     * Frage als Karte (Shop-Uebersicht) bzw. als Hinweis-Balken (WP-Dashboard, Plugin-Liste).
     */
    public static function render_question(Repository $settings, bool $notice = false): void
    {
        if (!self::should_ask($settings)) {
            return;
        }

        $text = __('Would you like to support Kontor Shop? A small line “Club shop with Kontor Shop” appears under your emails and the order confirmation. You can switch it off anytime in Settings → Email sending.', 'kontor-shop');
        $form = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '" />'
            . wp_nonce_field(self::ACTION, '_wpnonce', true, false)
            . '<p class="ks-promo__actions"><button type="submit" name="answer" value="yes" class="button button-primary">' . esc_html__('Yes, gladly', 'kontor-shop') . '</button> '
            . '<button type="submit" name="answer" value="no" class="button">' . esc_html__('No, thanks', 'kontor-shop') . '</button></p></form>';

        if ($notice) {
            echo '<div class="notice notice-info ks-promo"><p>' . esc_html($text) . '</p>' . $form . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $form ist aus esc_*/wp_nonce_field zusammengesetzt

            return;
        }

        echo '<section class="ks-card ks-dash-card ks-promo"><h2>' . esc_html__('Support Kontor Shop?', 'kontor-shop') . '</h2><div class="ks-dash-card__body"><p>'
            . esc_html($text) . '</p>' . $form . '</div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $form ist aus esc_*/wp_nonce_field zusammengesetzt
    }

    /**
     * "Ja, gerne" / "Nein, danke" speichern und zurueck zur Seite, von der die Antwort kam.
     */
    public function handle_answer(): void
    {
        if (!current_user_can(Menu::SETTINGS_CAPABILITY)) {
            wp_die(esc_html__('No permission.', 'kontor-shop'), '', ['response' => 403]);
        }

        check_admin_referer(self::ACTION);

        $yes  = sanitize_key(wp_unslash((string) ($_POST['answer'] ?? ''))) === 'yes';
        $back = wp_get_referer();

        $this->settings->save(['general' => ['powered_by' => $yes]]);

        Flash::redirect(
            is_string($back) && $back !== '' ? $back : DashboardPage::url(),
            $yes
                ? __('Thank you! The line appears under your emails and the order confirmation from now on.', 'kontor-shop')
                : sprintf(
                    /* translators: %s: settings section */
                    __('All right – no line. You can change this anytime under %s.', 'kontor-shop'),
                    __('Settings', 'kontor-shop') . ' → ' . __('Email sending', 'kontor-shop')
                )
        );
    }

    /**
     * Schalter unter Einstellungen → E-Mail-Versand (nur Free).
     */
    public static function render_setting(Repository $settings): void
    {
        if (Edition::is_pro()) {
            return;
        }

        echo '<h2>' . esc_html__('4. Support Kontor Shop', 'kontor-shop') . '</h2><table class="form-table"><tbody><tr><th>' . esc_html(self::text()) . '</th><td>'
            . '<label><input type="checkbox" name="powered_by" value="1" ' . checked(self::answer($settings) === true, true, false) . ' /> '
            . esc_html__('Show a small line “Club shop with Kontor Shop” (with a link) under the shop emails and on the order confirmation', 'kontor-shop') . '</label>'
            . '<p class="description">' . esc_html__('Voluntary – it helps other clubs find the shop. Nothing else on your website changes.', 'kontor-shop') . '</p></td></tr></tbody></table>';
    }

    /**
     * Wert aus dem Formular E-Mail-Versand: nur Free; nicht angehakt und noch
     * nie gefragt bleibt "nicht gefragt" (die Frage kommt dann noch).
     *
     * @return array<string, mixed> Werte unter general.*
     */
    public static function save_setting(Repository $settings): array
    {
        if (Edition::is_pro()) {
            return [];
        }

        $checked = !empty($_POST['powered_by']); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request

        if (!$checked && self::answer($settings) === null) {
            return [];
        }

        return ['powered_by' => $checked];
    }
}
