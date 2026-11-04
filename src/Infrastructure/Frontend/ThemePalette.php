<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Frontend;

/**
 * Bestenfalls-Vorschlag fuer eine Hauptfarbe als Hexwert (z. B. fuer Mails,
 * die keine CSS-Variablen kennen), gelesen aus der theme.json des aktiven
 * Themes ueber ThemeStyles. Leer, wenn das Theme keine Farbe verraet.
 */
final class ThemePalette
{
    public static function suggest_primary_color(): string
    {
        return ThemeStyles::detect()['primary_hex'] ?? '';
    }
}
