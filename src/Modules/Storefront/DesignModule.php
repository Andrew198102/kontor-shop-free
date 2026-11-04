<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Storefront;

use Kontor\Shop\Core\Capabilities;
use Kontor\Shop\Core\Registerable;
use Kontor\Shop\Infrastructure\Frontend\ThemeStyles;
use Kontor\Shop\Settings\Repository;
use Kontor\Shop\Support\Color;

/**
 * Gibt die Design-Einstellungen (Einstellungen -> Design) als
 * --kontor-shop-*-Variablen aus (DesignCss), haengt das eigene CSS des
 * Webmasters an shop.css an und setzt den Karten-Effekt als Body-Klasse.
 *
 * Live-Vorschau: Der Design-Reiter laedt die Shop-Seite mit
 * ?kontor_shop_design_preview=1 in einem Rahmen. Fuer Benutzer mit
 * Einstellungsrecht blendet das die Admin-Leiste aus und haengt ein kleines
 * Skript an, das per postMessage (nur gleiche Herkunft) die beiden
 * <style>-Bloecke und die Effekt-Klasse austauscht - ohne zu speichern. Das
 * CSS dafuer rechnet ajax_preview() mit denselben Funktionen wie das Frontend.
 */
final class DesignModule implements Registerable
{
    public const PREVIEW_PARAM = 'kontor_shop_design_preview';

    public const AJAX_ACTION = 'kontor_shop_design_preview';

    public function __construct(private readonly Repository $settings)
    {
    }

    private const CARD_EFFECTS = ['spotlight', 'lift', 'none'];

    public function register(): void
    {
        add_action('wp_head', [$this, 'print_css_vars'], 5);
        add_action('wp_enqueue_scripts', [$this, 'add_custom_css'], 20);
        add_filter('body_class', [$this, 'add_body_class']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'ajax_preview']);
        // Erst auf "wp" ist der Benutzer sicher bekannt; die Admin-Leiste startet danach (template_redirect).
        add_action('wp', [$this, 'setup_preview']);
    }

    public function setup_preview(): void
    {
        if (self::is_preview_request()) {
            add_filter('show_admin_bar', '__return_false');
            add_action('wp_footer', [$this, 'print_preview_script'], 99);
        }
    }

    public static function sanitize_card_effect(string $value): string
    {
        return in_array($value, self::CARD_EFFECTS, true) ? $value : 'spotlight';
    }

    /**
     * Karten-Effekt (design.card_effect) als Klasse am <body>, damit
     * shop.css und catalog.js ihn ohne Inline-Styles/Markup-Aenderung lesen.
     * "lift" ist der CSS-Grundzustand und braucht keine eigene Klasse.
     *
     * @param array<int, string> $classes
     * @return array<int, string>
     */
    public function add_body_class(array $classes): array
    {
        $effect = self::sanitize_card_effect((string) $this->settings->get('design.card_effect', 'spotlight'));

        if ($effect !== 'lift') {
            $classes[] = 'kontor-shop-fx-' . $effect;
        }

        return $classes;
    }

    /**
     * @return array<string, mixed>
     */
    private function design(): array
    {
        $design = $this->settings->get('design', []);

        return is_array($design) ? $design : [];
    }

    public function print_css_vars(): void
    {
        $css = DesignCss::css($this->design(), ThemeStyles::detect());

        if ($css === '' && !self::is_preview_request()) {
            return;
        }

        echo '<style id="kontor-shop-vars">' . $css . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- DesignCss: nur sanitize_hex-Farben, Konstanten und theme.json-Presets, Block-Zeichen entfernt
    }

    /**
     * Eigenes CSS aus Einstellungen -> Design direkt nach shop.css (gleiche
     * Spezifitaet gewinnt so gegen das Plugin-CSS).
     */
    public function add_custom_css(): void
    {
        $css = DesignCss::sanitize_custom_css((string) $this->settings->get('design.custom_css', ''));

        if ($css === '' && self::is_preview_request()) {
            $css = '/* kontor-shop-custom */';
        }

        if ($css !== '') {
            wp_add_inline_style('kontor-shop-frontend', $css);
        }
    }

    public static function is_preview_request(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige; wirkt ausschliesslich fuer Benutzer mit Einstellungsrecht
        return !is_admin() && isset($_GET[self::PREVIEW_PARAM]) && function_exists('current_user_can') && current_user_can(Capabilities::SETTINGS);
    }

    public function print_preview_script(): void
    {
        $effects = wp_json_encode(array_map(static fn (string $effect): string => 'kontor-shop-fx-' . $effect, self::CARD_EFFECTS));
        ?>
<script id="kontor-shop-design-preview">
(function () {
    var effects = <?php echo $effects; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode aus Konstanten ?>;
    function styleEl(id) {
        var el = document.getElementById(id);
        if (!el) { el = document.createElement('style'); el.id = id; document.head.appendChild(el); }
        return el;
    }
    window.addEventListener('message', function (event) {
        if (event.origin !== window.location.origin || !event.data || event.data.type !== 'kontor-shop-design') { return; }
        styleEl('kontor-shop-vars').textContent = String(event.data.css || '');
        styleEl('kontor-shop-frontend-inline-css').textContent = String(event.data.customCss || '');
        effects.forEach(function (cls) { document.body.classList.remove(cls); });
        if (event.data.effect && event.data.effect !== 'lift') { document.body.classList.add('kontor-shop-fx-' + event.data.effect); }
    });
    document.addEventListener('click', function (event) {
        var link = event.target.closest ? event.target.closest('a[href]') : null;
        if (link && link.host === window.location.host && link.href.indexOf('<?php echo esc_js(self::PREVIEW_PARAM); ?>') === -1) {
            var url = new URL(link.href);
            url.searchParams.set('<?php echo esc_js(self::PREVIEW_PARAM); ?>', '1');
            link.href = url.toString();
        }
    }, true);
    if (window.parent !== window) { window.parent.postMessage({ type: 'kontor-shop-design-ready' }, window.location.origin); }
})();
</script>
        <?php
    }

    /**
     * Live-Vorschau im Design-Reiter: aus den (ungespeicherten) Formularwerten
     * dasselbe CSS rechnen wie das Frontend, plus kopierbaren Block und Kontrast.
     */
    public function ajax_preview(): void
    {
        check_ajax_referer(self::AJAX_ACTION, 'nonce');

        if (!current_user_can(Capabilities::SETTINGS)) {
            wp_send_json_error(['message' => __('You are not allowed to change the shop design.', 'kontor-shop')], 403);
        }

        $raw    = isset($_POST['design']) && is_array($_POST['design']) ? wp_unslash($_POST['design']) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- DesignCss::sanitize prueft jeden Wert gegen Positivlisten bzw. sanitize_hex
        $design = DesignCss::sanitize(is_array($raw) ? array_map(static fn ($value): string => is_scalar($value) ? sanitize_text_field((string) $value) : '', $raw) : []);
        $theme  = ThemeStyles::detect();
        $custom = DesignCss::sanitize_custom_css((string) wp_unslash($_POST['custom_css'] ?? '')); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_custom_css entfernt Tags

        $primary = $design['primary_color'] !== '' ? $design['primary_color'] : (string) ($theme['primary_hex'] ?? '');
        $text    = $primary !== '' ? Color::on_color($primary) : Color::LIGHT_TEXT;

        wp_send_json_success([
            'css'       => DesignCss::css($design, $theme),
            'customCss' => $custom,
            'snippet'   => DesignCss::snippet($design, $theme),
            'effect'    => self::sanitize_card_effect(sanitize_text_field(wp_unslash((string) ($_POST['card_effect'] ?? '')))),
            'primary'   => $primary,
            'onPrimary' => $text,
            'contrast'  => $primary !== '' ? round(Color::contrast($primary, $text), 1) : null,
        ]);
    }
}
