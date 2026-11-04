<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Seo;

/**
 * GTIN (EAN/UPC): nur Ziffern, Laenge 8/12/13/14, gueltige Pruefziffer.
 * Eine falsche GTIN im JSON-LD ist schlimmer als keine.
 */
final class Gtin
{
    public static function valid(string $gtin): bool
    {
        if (!preg_match('/^[0-9]{8}$|^[0-9]{12,14}$/', $gtin)) {
            return false;
        }

        $digits = array_map('intval', str_split($gtin));
        $check  = array_pop($digits);
        $sum    = 0;

        foreach (array_reverse($digits) as $i => $digit) {
            $sum += $digit * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10 === $check;
    }

    public static function normalize(string $input): string
    {
        return (string) preg_replace('/[\s-]+/', '', $input);
    }
}
