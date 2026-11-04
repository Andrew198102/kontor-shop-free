<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Frontend;

use Kontor\Shop\Support\Color;

/**
 * Liest die Gestaltung des aktiven Themes ueber die WordPress-eigene
 * Global-Settings/Styles-API (theme.json, seit WP 5.9) - nie ueber
 * projektspezifische Theme-Variablennamen. Nur Themes mit eigener
 * theme.json (Block-Themes und manche klassische) liefern etwas; bei allen
 * anderen ist das Ergebnis leer und der Shop nutzt seine neutralen
 * Standardwerte bzw. die --kontor-shop-*-Variablen aus dem Theme-CSS
 * (docs/THEME_ANBINDUNG.md).
 *
 * Werte werden moeglichst als var(--wp--preset--…) zurueckgegeben, damit der
 * Shop einer Stil-Variante des Themes automatisch folgt; *_hex ist der
 * aufgeloeste Farbwert fuer Kontrastberechnung und Mails.
 */
final class ThemeStyles
{
    /** Palettenfarben, die als Hauptfarbe in Frage kommen (in dieser Reihenfolge). */
    private const PRIMARY_SLUGS = ['primary', 'brand', 'accent', 'accent-1', 'secondary', 'vivid-cyan-blue'];

    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    /**
     * @return array{primary?: string, primary_hex?: string, on_primary?: string, heading_font?: string, radius?: string, background_hex?: string, dark?: bool}
     */
    public static function detect(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        if (!function_exists('wp_get_global_settings') || !function_exists('wp_get_global_styles') || !self::theme_has_json()) {
            return self::$cache = [];
        }

        $palette = self::palette();
        $result  = [];

        [$css, $hex] = self::resolve_color(self::style(['elements', 'button', 'color', 'background']), $palette);

        if ($css === '') {
            foreach (self::PRIMARY_SLUGS as $slug) {
                if (isset($palette[$slug])) {
                    [$css, $hex] = ['var(--wp--preset--color--' . $slug . ')', $palette[$slug]];
                    break;
                }
            }
        }

        if ($css !== '') {
            $result['primary'] = $css;

            if ($hex !== '') {
                $result['primary_hex'] = $hex;
            }

            [$text_css, $text_hex] = self::resolve_color(self::style(['elements', 'button', 'color', 'text']), $palette);

            if ($text_css !== '' && ($hex === '' || $text_hex === '' || Color::contrast($hex, $text_hex) >= 3.0)) {
                $result['on_primary'] = $text_css;
            } elseif ($hex !== '') {
                $result['on_primary'] = Color::on_color($hex);
            }
        }

        $font = self::resolve_preset(self::style(['elements', 'heading', 'typography', 'fontFamily']), 'font-family');

        if ($font !== '') {
            $result['heading_font'] = $font;
        }

        $radius = self::style(['elements', 'button', 'border', 'radius']);

        if (is_string($radius) && preg_match('/^\d+(\.\d+)?(px|rem|em)$/', trim($radius))) {
            // Pillen-Buttons (9999px) als Kartenrundung waeren zu viel: dann nur leicht rund.
            $result['radius'] = (float) $radius > 40 ? '16px' : trim($radius);
        }

        [, $background] = self::resolve_color(self::style(['color', 'background']), $palette);

        if ($background !== '') {
            $result['background_hex'] = $background;
            $result['dark']           = Color::is_dark($background);
        }

        return self::$cache = $result;
    }

    /** Fuer Tests und nach einem Theme-Wechsel im selben Request. */
    public static function reset(): void
    {
        self::$cache = null;
    }

    private static function theme_has_json(): bool
    {
        if (function_exists('wp_theme_has_theme_json')) {
            return wp_theme_has_theme_json();
        }

        return function_exists('wp_is_block_theme') && wp_is_block_theme();
    }

    /**
     * @param array<int, string> $path
     */
    private static function style(array $path): mixed
    {
        $value = wp_get_global_styles($path);

        return is_string($value) || is_array($value) ? $value : '';
    }

    /**
     * Theme-Palette als slug => Hex (nur gueltige Hexwerte).
     *
     * @return array<string, string>
     */
    private static function palette(): array
    {
        $palette = wp_get_global_settings(['color', 'palette', 'theme']);
        $result  = [];

        foreach (is_array($palette) ? $palette : [] as $entry) {
            if (is_array($entry) && isset($entry['slug'], $entry['color']) && is_string($entry['slug']) && is_string($entry['color'])) {
                $hex = Color::sanitize_hex($entry['color']);

                if ($hex !== '') {
                    $result[sanitize_key($entry['slug'])] = $hex;
                }
            }
        }

        return $result;
    }

    /**
     * theme.json-Farbwert in [CSS-Wert, Hex] aufloesen. Erlaubt sind Hex,
     * "var:preset|color|slug" und "var(--wp--preset--color--slug)";
     * alles andere (Verlaeufe, freie Ausdruecke) wird ignoriert.
     *
     * @param array<string, string> $palette
     * @return array{0: string, 1: string}
     */
    private static function resolve_color(mixed $value, array $palette): array
    {
        if (!is_string($value) || $value === '') {
            return ['', ''];
        }

        $value = trim($value);

        if (Color::is_hex($value)) {
            return [$value, $value];
        }

        $slug = self::preset_slug($value, 'color');

        if ($slug === '') {
            return ['', ''];
        }

        return ['var(--wp--preset--color--' . $slug . ')', $palette[$slug] ?? ''];
    }

    /** Preset-Verweis (Schrift) als var(--wp--preset--…); sonst leer. */
    private static function resolve_preset(mixed $value, string $type): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }

        $slug = self::preset_slug(trim($value), $type);

        return $slug === '' ? '' : 'var(--wp--preset--' . $type . '--' . $slug . ')';
    }

    private static function preset_slug(string $value, string $type): string
    {
        $quoted = preg_quote($type, '/');

        if (preg_match('/^var:preset\|' . $quoted . '\|([a-z0-9-]+)$/i', $value, $match)
            || preg_match('/^var\(--wp--preset--' . $quoted . '--([a-z0-9-]+)\)$/i', $value, $match)) {
            return strtolower($match[1]);
        }

        return '';
    }
}
