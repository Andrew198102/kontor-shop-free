<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Seo;

/**
 * URL-Slug aus einem Freitext (deutsche Umlaute werden umgeschrieben). Reine
 * Logik ohne WordPress, damit die Adresse eines Artikels testbar bleibt.
 */
final class Slug
{
    private const MAP = [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
        'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue',
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'ý' => 'y', 'ÿ' => 'y', 'š' => 's', 'ž' => 'z', 'č' => 'c', 'ł' => 'l',
    ];

    public static function make(string $text, string $fallback = 'artikel', int $max_length = 80): string
    {
        $text = strtr(function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text), self::MAP);
        $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');

        if (strlen($text) > $max_length) {
            $text = trim(substr($text, 0, $max_length), '-');
        }

        return $text !== '' ? $text : $fallback;
    }

    /**
     * Haengt -2, -3 ... an, bis $exists den Slug nicht mehr kennt.
     *
     * @param callable(string): bool $exists
     */
    public static function unique(string $base, callable $exists): string
    {
        $slug = $base;
        $i    = 2;

        while ($exists($slug)) {
            $slug = $base . '-' . $i;
            $i++;
        }

        return $slug;
    }
}
