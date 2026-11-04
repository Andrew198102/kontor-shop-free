<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Seo;

/**
 * Klartexte fuer Titel, Beschreibung und llms-Zusammenfassung. Nur der
 * Normalpreis, nie ein Mitgliedspreis.
 */
final class SeoText
{
    public static function price_de(float $price): string
    {
        return number_format($price, 2, ',', '.') . ' EUR';
    }

    /** z. B. "Vereins-T-Shirt, 19,90 EUR (Normalpreis), lieferbar". */
    public static function summary(PublicProduct $product): string
    {
        return $product->name . ', ' . self::price_de($product->price) . ' (Normalpreis), '
            . ($product->available ? 'lieferbar' : 'derzeit nicht lieferbar');
    }

    public static function meta_description(PublicProduct $product, int $max = 155): string
    {
        $text = self::plain($product->description);

        if ($text === '') {
            return self::summary($product);
        }

        return self::truncate($text, $max);
    }

    public static function plain(string $text): string
    {
        $text = wp_strip_all_tags($text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    public static function truncate(string $text, int $max): string
    {
        $len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);

        if ($len <= $max) {
            return $text;
        }

        $cut = function_exists('mb_substr') ? mb_substr($text, 0, $max - 1) : substr($text, 0, $max - 1);
        $cut = (string) preg_replace('/\s+\S*$/u', '', $cut);

        return rtrim($cut, " ,.;:-") . '…';
    }
}
