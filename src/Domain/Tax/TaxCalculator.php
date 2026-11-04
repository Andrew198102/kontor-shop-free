<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Tax;

/**
 * Umsatzsteuer-Rechnung. Alle Preise im Shop sind Endkundenpreise (brutto);
 * die USt wird daraus nur herausgerechnet und ausgewiesen. Ein Artikel ohne
 * Steuersatz (null) ist umsatzsteuerfrei. Reine Rechenlogik.
 */
final class TaxCalculator
{
    public const SHIPPING_NONE         = 'none';
    public const SHIPPING_FIXED        = 'fixed';
    public const SHIPPING_PROPORTIONAL = 'proportional';

    /**
     * @return array<int, string>
     */
    public static function shipping_modes(): array
    {
        return [self::SHIPPING_PROPORTIONAL, self::SHIPPING_FIXED, self::SHIPPING_NONE];
    }

    /**
     * Ordnet die (brutto) Versandkosten Steuersaetzen zu:
     *  - proportional: Aufteilung im Verhaeltnis der Warenwerte je Steuersatz
     *    (Versand als Nebenleistung teilt das steuerliche Schicksal der Ware;
     *    bei gemischten Saetzen wird anteilig aufgeteilt); ein steuerfreier
     *    Warenanteil bekommt seinen Versandanteil ebenfalls steuerfrei,
     *  - fixed: ein fester Satz fuer den gesamten Versand,
     *  - none: Versand ohne USt.
     * Rundungsdifferenzen landen beim groessten Anteil, die Summe stimmt immer.
     *
     * @param array<int, array{tax_rate: float|null, line_total: float}> $item_lines
     * @return array<int, array{tax_rate: float|null, line_total: float}>
     */
    public static function allocate_shipping(float $shipping, array $item_lines, string $mode, ?float $fixed_rate): array
    {
        $shipping = round($shipping, 2);

        if ($shipping <= 0) {
            return [];
        }

        if ($mode === self::SHIPPING_FIXED && $fixed_rate !== null && $fixed_rate > 0) {
            return [['tax_rate' => $fixed_rate, 'line_total' => $shipping]];
        }

        if ($mode !== self::SHIPPING_PROPORTIONAL) {
            return [['tax_rate' => null, 'line_total' => $shipping]];
        }

        $groups = [];

        foreach ($item_lines as $line) {
            $rate = $line['tax_rate'] !== null && $line['tax_rate'] > 0 ? $line['tax_rate'] : null;
            $key  = $rate === null ? 'x' : number_format($rate, 2, '.', '');

            $groups[$key] ??= ['tax_rate' => $rate, 'gross' => 0.0];
            $groups[$key]['gross'] += $line['line_total'];
        }

        $total = array_sum(array_column($groups, 'gross'));

        if ($total <= 0) {
            return [['tax_rate' => null, 'line_total' => $shipping]];
        }

        $result    = [];
        $allocated = 0.0;
        $largest   = null;

        foreach ($groups as $key => $group) {
            $share = round($shipping * $group['gross'] / $total, 2);

            $result[$key] = ['tax_rate' => $group['tax_rate'], 'line_total' => $share];
            $allocated   += $share;

            if ($largest === null || $group['gross'] > $groups[$largest]['gross']) {
                $largest = $key;
            }
        }

        if ($largest !== null) {
            $result[$largest]['line_total'] = round($result[$largest]['line_total'] + ($shipping - $allocated), 2);
        }

        return array_values(array_filter($result, static fn (array $line): bool => $line['line_total'] > 0));
    }

    /**
     * In einem Bruttobetrag enthaltene USt.
     */
    public static function included_vat(float $gross, float $rate): float
    {
        if ($rate <= 0) {
            return 0.0;
        }

        return round($gross - $gross / (1 + $rate / 100), 2);
    }

    /**
     * Enthaltene USt je Steuersatz, absteigend nach Satz sortiert. Positionen
     * ohne Satz (steuerfrei) tauchen nicht auf.
     *
     * @param array<int, array{tax_rate: float|null, line_total: float}> $lines
     * @return array<string, array{rate: float, gross: float, vat: float}> Schluessel = Satz als Text ("20", "10", "12.5")
     */
    public static function breakdown(array $lines): array
    {
        $groups = [];

        foreach ($lines as $line) {
            $rate = $line['tax_rate'];

            if ($rate === null || $rate <= 0) {
                continue;
            }

            $key = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');

            $groups[$key] ??= ['rate' => $rate, 'gross' => 0.0, 'vat' => 0.0];
            $groups[$key]['gross'] += $line['line_total'];
        }

        foreach ($groups as $key => $group) {
            $groups[$key]['gross'] = round($group['gross'], 2);
            // USt je Steuersatz aus der Summe (nicht je Zeile) gerundet - so ist die Summe stimmig.
            $groups[$key]['vat'] = self::included_vat($groups[$key]['gross'], $group['rate']);
        }

        uasort($groups, static fn (array $a, array $b): int => $b['rate'] <=> $a['rate']);

        return $groups;
    }

    /**
     * Normalisiert die Eingabe der Steuersaetze (z. B. "20, 10; 13,5") zu
     * einer eindeutigen, absteigend sortierten Liste gueltiger Saetze (0 < s <= 100).
     *
     * @return array<int, float>
     */
    public static function parse_rates(string $input): array
    {
        $rates = [];

        // Trenner: Semikolon, Leerzeichen oder ", " (Komma + Leerzeichen); ein Komma
        // direkt zwischen Ziffern ist das Dezimalkomma ("13,5").
        foreach (preg_split('/;|,\s+|\s+/', $input, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            $value = (float) str_replace(',', '.', trim($part, ", \t"));

            if ($value > 0 && $value <= 100) {
                $rates[(string) round($value, 2)] = round($value, 2);
            }
        }

        $rates = array_values($rates);
        rsort($rates);

        return $rates;
    }
}
