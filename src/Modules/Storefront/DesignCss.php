<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Storefront;

use Kontor\Shop\Support\Color;

/**
 * Baut aus den Design-Einstellungen (design.*) und der erkannten
 * Theme-Gestaltung (Infrastructure\Frontend\ThemeStyles::detect()) die
 * --kontor-shop-*-Variablen. Schreibt nie Selektoren auf Shop-Markup -
 * dieselben Variablen, die ein Theme laut docs/THEME_ANBINDUNG.md setzen
 * kann. Reine Logik (ausser sanitize_custom_css), damit Frontend,
 * Live-Vorschau und der kopierbare Block fuer Webmaster dasselbe ergeben.
 *
 * Rangfolge im Browser:
 *  1. Einstellungen (":root{…}", schlaegt Theme-CSS)
 *  2. Theme-CSS mit :where(:root) (wird nach unserem Block geladen)
 *  3. Erkennung aus theme.json (":where(:root){…}", vor dem Theme-CSS)
 *  4. Fallbacks in shop.css
 */
final class DesignCss
{
    public const STYLES = ['theme', 'clear', 'soft', 'square'];

    public const SCHEMES = ['theme', 'light', 'dark'];

    public const COLUMNS = ['auto', '2', '3', '4'];

    public const RATIOS = ['4/3', '1/1', '3/4'];

    public const HEADING_FONTS = ['theme', 'body'];

    public const MAX_RADIUS = 32;

    /** Grundstile: Ecken und Schatten (ohne Praefix --kontor-shop-). */
    private const STYLE_VARS = [
        'theme'  => [],
        'clear'  => ['radius' => '6px', 'card-radius' => '8px', 'shadow' => 'none', 'shadow-hover' => '0 6px 18px rgba(0, 0, 0, .08)'],
        'soft'   => ['radius' => '14px', 'card-radius' => '20px', 'shadow' => '0 8px 24px rgba(0, 0, 0, .06)', 'shadow-hover' => '0 18px 40px rgba(0, 0, 0, .12)'],
        'square' => ['radius' => '0px', 'card-radius' => '0px', 'shadow' => 'none', 'shadow-hover' => 'none'],
    ];

    /** Dunkles Farbschema: Flaechen, Text, Statusflaechen. */
    private const DARK_VARS = [
        'surface'      => '#1f2328',
        'soft'         => '#2a2f36',
        'border'       => '#3a414b',
        'text'         => '#eceef1',
        'muted'        => '#a6adb8',
        'shadow'       => '0 1px 2px rgba(0, 0, 0, .4)',
        'shadow-hover' => '0 14px 32px rgba(0, 0, 0, .5)',
        'success'      => '#4cc26f',
        'warning'      => '#e9a23b',
        'danger'       => '#ef6b62',
        'danger-soft'  => '#3a1f22',
        'danger-text'  => '#f4a9a9',
        'toast-bg'     => '#eceef1',
        'toast-text'   => '#1d2327',
    ];

    /** Mindestbreite einer Karte, wenn eine feste Spaltenzahl gewaehlt ist. */
    private const CARD_MIN_FOR_COLUMNS = ['2' => '240px', '3' => '200px', '4' => '170px'];

    /**
     * Gespeicherte bzw. gepostete Werte bereinigen (fehlende Schluessel = Standard).
     *
     * @param array<string, mixed> $input
     * @return array{primary_color: string, cart_icon_color: string, style: string, radius: string, scheme: string, columns: string, image_ratio: string, heading_font: string}
     */
    public static function sanitize(array $input): array
    {
        $pick = static fn (string $key, array $allowed): string => in_array((string) ($input[$key] ?? ''), $allowed, true) ? (string) $input[$key] : $allowed[0];

        $radius = $input['radius'] ?? '';
        $radius = is_numeric($radius) ? (string) max(0, min(self::MAX_RADIUS, (int) $radius)) : '';

        $ratio = (string) ($input['image_ratio'] ?? '4/3');

        return [
            'primary_color'   => Color::sanitize_hex((string) ($input['primary_color'] ?? '')),
            'cart_icon_color' => Color::sanitize_hex((string) ($input['cart_icon_color'] ?? '')),
            'style'           => $pick('style', self::STYLES),
            'radius'          => $radius,
            'scheme'          => $pick('scheme', self::SCHEMES),
            'columns'         => $pick('columns', self::COLUMNS),
            'image_ratio'     => in_array($ratio, self::RATIOS, true) ? $ratio : '4/3',
            'heading_font'    => $pick('heading_font', self::HEADING_FONTS),
        ];
    }

    /**
     * Werte aus dem Theme (niedrigste Stufe, ":where(:root)").
     *
     * @param array<string, mixed> $design
     * @param array<string, mixed> $theme  ThemeStyles::detect()
     * @return array<string, string>
     */
    public static function theme_vars(array $design, array $theme): array
    {
        $design = self::sanitize($design);
        $vars   = [];

        if (isset($theme['primary']) && is_string($theme['primary'])) {
            $vars['primary'] = $theme['primary'];
        }

        if (isset($theme['on_primary']) && is_string($theme['on_primary'])) {
            $vars['on-primary'] = $theme['on_primary'];
        }

        if ($design['heading_font'] === 'theme' && isset($theme['heading_font']) && is_string($theme['heading_font'])) {
            $vars['heading-font'] = $theme['heading_font'];
        }

        if ($design['style'] === 'theme' && $design['radius'] === '' && isset($theme['radius']) && is_string($theme['radius'])) {
            $vars['radius'] = $theme['radius'];
        }

        if ($design['scheme'] === 'theme' && !empty($theme['dark'])) {
            $vars += self::DARK_VARS;
        }

        return $vars;
    }

    /**
     * Ausdrueckliche Einstellungen (hoechste Stufe, ":root").
     *
     * @param array<string, mixed> $design
     * @return array<string, string>
     */
    public static function setting_vars(array $design): array
    {
        $design = self::sanitize($design);
        $vars   = self::STYLE_VARS[$design['style']];

        if ($design['primary_color'] !== '') {
            $vars['primary']    = $design['primary_color'];
            $vars['on-primary'] = Color::on_color($design['primary_color']);
        }

        if ($design['cart_icon_color'] !== '') {
            $vars['cart-icon-color'] = $design['cart_icon_color'];
            $vars['cart-icon-text']  = Color::on_color($design['cart_icon_color']);
        }

        if ($design['radius'] !== '') {
            $vars['radius']      = $design['radius'] . 'px';
            $vars['card-radius'] = $design['radius'] . 'px';
        }

        if ($design['columns'] !== 'auto') {
            $vars['columns']  = $design['columns'];
            $vars['card-min'] = self::CARD_MIN_FOR_COLUMNS[$design['columns']];
        }

        if ($design['image_ratio'] !== '4/3') {
            $vars['image-ratio'] = str_replace('/', ' / ', $design['image_ratio']);
        }

        if ($design['heading_font'] === 'body') {
            $vars['heading-font'] = 'inherit';
        }

        // "Hell" braucht nichts: die dunklen Werte aus der Theme-Erkennung kommen nur bei "Wie Theme".
        if ($design['scheme'] === 'dark') {
            $vars = array_merge($vars, self::DARK_VARS);
        }

        return $vars;
    }

    /**
     * Fertiges CSS fuer den <style>-Block im Kopf der Seite (leer = nichts ausgeben).
     *
     * @param array<string, mixed> $design
     * @param array<string, mixed> $theme
     */
    public static function css(array $design, array $theme): string
    {
        return self::block(':where(:root)', self::theme_vars($design, $theme)) . self::block(':root', self::setting_vars($design));
    }

    /**
     * Kopierbarer Block fuer die style.css eines (Child-)Themes: alle
     * wirksamen Werte zusammengefasst, mit :where(:root) wie in der Anleitung.
     *
     * @param array<string, mixed> $design
     * @param array<string, mixed> $theme
     */
    public static function snippet(array $design, array $theme): string
    {
        $vars = array_merge(self::theme_vars($design, $theme), self::setting_vars($design));

        if ($vars === []) {
            return "/* Kontor Shop: keine eigenen Werte - der Shop nutzt seine Standardwerte. */\n:where(:root) {\n}\n";
        }

        $lines = [];

        foreach ($vars as $name => $value) {
            $lines[] = '  --kontor-shop-' . $name . ': ' . $value . ';';
        }

        return "/* Kontor Shop - Plugin-Anbindung (Einstellungen -> Design) */\n:where(:root) {\n" . implode("\n", $lines) . "\n}\n";
    }

    /**
     * Eigenes CSS bereinigen: keine Tags, kein Ausbruch aus dem <style>-Block.
     */
    public static function sanitize_custom_css(string $css): string
    {
        $css = function_exists('wp_strip_all_tags') ? wp_strip_all_tags($css) : strip_tags($css);
        $css = str_replace(['</', '<!--', '-->'], '', $css);

        return trim(substr($css, 0, 20000));
    }

    /**
     * @param array<string, string> $vars
     */
    private static function block(string $selector, array $vars): string
    {
        if ($vars === []) {
            return '';
        }

        $body = '';

        foreach ($vars as $name => $value) {
            // Werte stammen aus Konstanten, Color::sanitize_hex oder theme.json-Presets; trotzdem nichts durchlassen, was den Block verlaesst.
            $body .= '--kontor-shop-' . $name . ':' . str_replace(['{', '}', ';', '<', '>'], '', $value) . ';';
        }

        return $selector . '{' . $body . '}';
    }
}
