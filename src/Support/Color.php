<?php

declare(strict_types=1);

namespace Kontor\Shop\Support;

/**
 * Validierung/Sanitizing von Hex-Farbwerten (Design-Einstellungen,
 * Kategorie-Akzentfarben) und Kontrastberechnung nach WCAG 2.x fuer die
 * Schriftfarbe auf Buttons. Reine Logik, keine WordPress-Abhaengigkeit.
 */
final class Color
{
    /** Schrift auf hellen Farben (= Text-Standard des Shops). */
    public const DARK_TEXT = '#1d2327';

    public const LIGHT_TEXT = '#ffffff';

    public static function is_hex(string $value): bool
    {
        return (bool) preg_match('/^#([a-fA-F0-9]{3}|[a-fA-F0-9]{6})$/', $value);
    }

    public static function sanitize_hex(string $value, string $fallback = ''): string
    {
        $value = trim($value);

        return self::is_hex($value) ? $value : $fallback;
    }

    /**
     * Relative Leuchtdichte (0 = schwarz, 1 = weiss); null bei ungueltiger Farbe.
     */
    public static function luminance(string $hex): ?float
    {
        $hex = self::sanitize_hex($hex);

        if ($hex === '') {
            return null;
        }

        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $channels = [];

        foreach ([0, 2, 4] as $offset) {
            $value      = hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /**
     * Kontrastverhaeltnis zweier Farben (1 bis 21); 0.0 bei ungueltiger Farbe.
     */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        if ($la === null || $lb === null) {
            return 0.0;
        }

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * Besser lesbare Schriftfarbe auf $background: Weiss, solange Weiss
     * mindestens 4,5 : 1 erreicht (so bleiben die ueblichen Vereinsfarben
     * wie bisher weiss beschriftet), sonst die mit dem hoeheren Kontrast.
     * Ungueltige Farbe: Weiss (bisheriges Verhalten).
     */
    public static function on_color(string $background): string
    {
        if (self::luminance($background) === null) {
            return self::LIGHT_TEXT;
        }

        $light = self::contrast($background, self::LIGHT_TEXT);

        if ($light >= 4.5) {
            return self::LIGHT_TEXT;
        }

        return self::contrast($background, self::DARK_TEXT) > $light ? self::DARK_TEXT : self::LIGHT_TEXT;
    }

    /**
     * Inline-Stil einer Artikelkarte mit eigener Akzentfarbe (Kategorie,
     * Shoptyp): Farbe plus lesbare Schrift darauf. Leer bei ungueltiger Farbe.
     */
    public static function accent_style(string $accent): string
    {
        $accent = self::sanitize_hex($accent);

        return $accent === '' ? '' : '--ks-card-accent: ' . $accent . '; --ks-card-on-accent: ' . self::on_color($accent) . ';';
    }

    /** true, wenn die Farbe eher dunkel ist (z. B. Hintergrund eines dunklen Themes). */
    public static function is_dark(string $hex): bool
    {
        $luminance = self::luminance($hex);

        return $luminance !== null && $luminance < 0.18;
    }
}
