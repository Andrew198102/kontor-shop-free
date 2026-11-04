<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Catalog;

/**
 * Grundpreis (Preis je kg, l, m, m²) fuer Waren, die nach Gewicht, Volumen,
 * Laenge oder Flaeche angeboten werden (Preisauszeichnungsgesetz in AT,
 * Preisangabenverordnung in DE). Aus Inhalt + Einheit des Artikels bzw. der
 * Variante und dem Verkaufspreis. Reine Rechenlogik ohne WordPress.
 */
final class UnitPrice
{
    /**
     * Einheit => [Anzeige, Bezugseinheit, Faktor (so viele Einheiten ergeben eine Bezugseinheit)]
     */
    private const UNITS = [
        'g'  => ['g', 'kg', 1000.0],
        'kg' => ['kg', 'kg', 1.0],
        'ml' => ['ml', 'l', 1000.0],
        'cl' => ['cl', 'l', 100.0],
        'l'  => ['l', 'l', 1.0],
        'cm' => ['cm', 'm', 100.0],
        'm'  => ['m', 'm', 1.0],
        'm2' => ['m²', 'm²', 1.0],
    ];

    /**
     * @return array<string, string> Einheit => Anzeige (fuer Auswahllisten)
     */
    public static function units(): array
    {
        return array_map(static fn (array $unit): string => $unit[0], self::UNITS);
    }

    public static function is_unit(string $unit): bool
    {
        return isset(self::UNITS[$unit]);
    }

    /**
     * Preis je Bezugseinheit, oder null ohne (gueltigen) Inhalt. Entspricht
     * der Inhalt genau einer Bezugseinheit (1 kg, 1 l), ist der Grundpreis
     * der Verkaufspreis selbst und wird nicht extra ausgewiesen.
     *
     * @return array{price: float, reference: string}|null
     */
    public static function compute(float $price, ?float $amount, string $unit): ?array
    {
        if ($amount === null || $amount <= 0 || !isset(self::UNITS[$unit])) {
            return null;
        }

        [, $reference, $factor] = self::UNITS[$unit];
        $in_reference = $amount / $factor;

        if (abs($in_reference - 1.0) < 0.000001) {
            return null;
        }

        return ['price' => round($price / $in_reference, 2), 'reference' => $reference];
    }

    /**
     * Inhalt aus einem Variantenwert lesen ("0,5 l", "500 g", "1,5kg"),
     * umgerechnet in die Einheit des Artikels. So muss bei Varianten, die
     * schon nach ihrem Inhalt heissen, nichts doppelt eingetragen werden.
     * null, wenn kein Wert passt oder die Einheiten nicht zusammenpassen
     * (Gewicht vs. Volumen).
     *
     * @param array<int, string> $values
     */
    public static function amount_from_values(array $values, string $unit): ?float
    {
        if (!isset(self::UNITS[$unit])) {
            return null;
        }

        [, $reference, $factor] = self::UNITS[$unit];

        foreach ($values as $value) {
            if (preg_match('/^\s*(\d+(?:[.,]\d+)?)\s*(g|kg|ml|cl|l|cm|m|m2|m²)\s*$/iu', (string) $value, $m) !== 1) {
                continue;
            }

            $value_unit = strtolower(str_replace('²', '2', $m[2]));

            if (!isset(self::UNITS[$value_unit]) || self::UNITS[$value_unit][1] !== $reference) {
                continue;
            }

            $in_reference = (float) str_replace(',', '.', $m[1]) / self::UNITS[$value_unit][2];

            return $in_reference > 0 ? round($in_reference * $factor, 3) : null;
        }

        return null;
    }

    /** "12,90 € / kg" */
    public static function text(float $price, ?float $amount, string $unit): string
    {
        $unit_price = self::compute($price, $amount, $unit);

        return $unit_price === null ? '' : self::money($unit_price['price']) . ' € / ' . $unit_price['reference'];
    }

    /** Inhaltsangabe "500 g", "0,75 l" - leer ohne Inhalt. */
    public static function amount_label(?float $amount, string $unit): string
    {
        if ($amount === null || $amount <= 0 || !isset(self::UNITS[$unit])) {
            return '';
        }

        $number = rtrim(rtrim(number_format($amount, 3, ',', '.'), '0'), ',');

        return $number . ' ' . self::UNITS[$unit][0];
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, ',', '.');
    }
}
