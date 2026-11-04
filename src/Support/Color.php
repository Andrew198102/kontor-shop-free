<?php

declare(strict_types=1);

namespace Kontor\Shop\Support;

/**
 * Validierung/Sanitizing von Hex-Farbwerten (Design-Einstellungen,
 * Kategorie-Akzentfarben). Reine Logik, keine WordPress-Abhaengigkeit.
 */
final class Color
{
    public static function is_hex(string $value): bool
    {
        return (bool) preg_match('/^#([a-fA-F0-9]{3}|[a-fA-F0-9]{6})$/', $value);
    }

    public static function sanitize_hex(string $value, string $fallback = ''): string
    {
        $value = trim($value);

        return self::is_hex($value) ? $value : $fallback;
    }
}
