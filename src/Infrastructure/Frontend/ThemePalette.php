<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Frontend;

/**
 * Bestenfalls-Vorschlag fuer eine Hauptfarbe, gelesen ueber die
 * WordPress-eigene Global-Settings-API (theme.json-Editor-Farbpalette,
 * seit WP 5.9) - niemals ein projektspezifischer Theme-Variablenname. Nur
 * ein Vorschlag fuer das leere Eingabefeld in den Einstellungen
 * (Admin\SettingsPage), keine Laufzeit-Abhaengigkeit: liefert das aktive
 * Theme keine eigene Palette (wie die meisten klassischen Themes), bleibt
 * das Feld leer und der Shop-Admin waehlt selbst eine Farbe.
 */
final class ThemePalette
{
    public static function suggest_primary_color(): string
    {
        if (!function_exists('wp_get_global_settings')) {
            return '';
        }

        $palette = wp_get_global_settings(['color', 'palette', 'theme']);

        if (is_array($palette) && isset($palette[0]['color']) && is_string($palette[0]['color'])) {
            return $palette[0]['color'];
        }

        return '';
    }
}
