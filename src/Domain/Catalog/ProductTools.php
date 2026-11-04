<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Catalog;

/**
 * Kleine reine Helfer fuer Artikel-Sammelaktionen und -Kopien.
 */
final class ProductTools
{
    private const NAME_LIMIT = 190;

    /**
     * Preis um einen Prozentsatz aendern (negativ = senken), auf 2 Stellen
     * gerundet und nie unter 0 Euro.
     */
    public static function adjust_price(float $price, float $percent): float
    {
        return round(max(0.0, $price * (1 + $percent / 100)), 2);
    }

    /**
     * Name fuer die Kopie eines Artikels: "Name (Kopie)", bei bereits
     * vorhandenem Kopie-Zusatz "Name (Kopie 2)" usw.; bleibt in der
     * Spaltenlaenge (190 Zeichen).
     */
    public static function copy_name(string $name): string
    {
        $name = trim($name);

        if (preg_match('/^(.*) \(Kopie(?: (\d+))?\)$/su', $name, $m) === 1) {
            $base   = $m[1];
            $number = ($m[2] ?? '') === '' ? 2 : ((int) $m[2]) + 1;
            $suffix = ' (Kopie ' . $number . ')';
        } else {
            $base   = $name;
            $suffix = ' (Kopie)';
        }

        return mb_substr($base, 0, self::NAME_LIMIT - mb_strlen($suffix)) . $suffix;
    }
}
