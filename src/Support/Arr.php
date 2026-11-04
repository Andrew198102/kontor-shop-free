<?php

declare(strict_types=1);

namespace Kontor\Shop\Support;

/**
 * Kleine Array-Hilfsfunktionen fuer Punktnotation und rekursives Zusammenfuehren.
 * Wird von den Settings (Repository, Schema) verwendet.
 */
final class Arr
{
    /**
     * Wert per Punktnotation aus einem verschachtelten Array lesen.
     *
     * @param array<string, mixed> $array
     */
    public static function get(array $array, string $path, mixed $default = null): mixed
    {
        if ($path === '') {
            return $default;
        }

        $segments = explode('.', $path);
        $current  = $array;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Rekursives Zusammenfuehren. Assoziative Schluessel werden tief gemischt,
     * Listen (numerisch indiziert) und Skalare vom Override komplett ersetzt.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    public static function deep_merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (
                is_array($value)
                && isset($base[$key])
                && is_array($base[$key])
                && !self::is_list($value)
                && !self::is_list($base[$key])
            ) {
                $base[$key] = self::deep_merge($base[$key], $value);

                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * @param array<mixed> $array
     */
    public static function is_list(array $array): bool
    {
        if (function_exists('array_is_list')) {
            return array_is_list($array);
        }

        $i = 0;

        foreach ($array as $key => $_) {
            if ($key !== $i++) {
                return false;
            }
        }

        return true;
    }
}
