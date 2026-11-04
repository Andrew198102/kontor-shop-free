<?php

declare(strict_types=1);

namespace Kontor\Shop\Support;

/**
 * CSV-Zeilen fuer den Bestell-Export (Excel-tauglich: Semikolon-Trenner).
 * Zellen, die mit =, +, -, @, Tab oder CR beginnen, bekommen ein
 * vorangestelltes Hochkomma - sonst wuerde Excel/LibreOffice
 * Kundeneingaben (z. B. Name "=HYPERLINK(...)") als Formel ausfuehren
 * (CSV-/Formula-Injection).
 */
final class Csv
{
    public const DELIMITER = ';';

    public static function cell(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $value = "'" . $value;
        }

        if (preg_match('/[";\r\n]/', $value) === 1) {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }

    /**
     * @param array<int, string> $cells
     */
    public static function row(array $cells): string
    {
        return implode(self::DELIMITER, array_map([self::class, 'cell'], $cells)) . "\r\n";
    }

    /**
     * Zahl im deutschen Format ohne Tausendertrenner ("1234,50").
     */
    public static function number(float $value): string
    {
        return number_format($value, 2, ',', '');
    }
}
