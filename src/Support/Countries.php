<?php

declare(strict_types=1);

namespace Kontor\Shop\Support;

/**
 * Laenderliste (ISO 3166-1 alpha-2) und Postleitzahlen-Formate fuer das
 * Adressformular. Die Formate pruefen nur die Form (nicht ob die PLZ
 * existiert); fuer Laender ohne bekanntes Muster gilt ein lockeres Muster.
 */
final class Countries
{
    /** @var array<string, string> */
    private const NAMES = [
        'AT' => 'Österreich', 'DE' => 'Deutschland', 'CH' => 'Schweiz', 'LI' => 'Liechtenstein',
        'IT' => 'Italien', 'FR' => 'Frankreich', 'NL' => 'Niederlande', 'BE' => 'Belgien', 'LU' => 'Luxemburg',
        'CZ' => 'Tschechien', 'SK' => 'Slowakei', 'HU' => 'Ungarn', 'SI' => 'Slowenien', 'HR' => 'Kroatien',
        'PL' => 'Polen', 'ES' => 'Spanien', 'PT' => 'Portugal', 'DK' => 'Dänemark', 'SE' => 'Schweden',
        'FI' => 'Finnland', 'IE' => 'Irland', 'GB' => 'Vereinigtes Königreich',
    ];

    /** @var array<string, string> Land => Regex (ohne Begrenzer) */
    private const POSTAL = [
        'AT' => '\d{4}', 'CH' => '\d{4}', 'LI' => '\d{4}', 'BE' => '\d{4}', 'LU' => '\d{4}', 'HU' => '\d{4}',
        'SI' => '\d{4}', 'DK' => '\d{4}',
        'DE' => '\d{5}', 'FR' => '\d{5}', 'IT' => '\d{5}', 'ES' => '\d{5}', 'FI' => '\d{5}', 'HR' => '\d{5}',
        'NL' => '\d{4} ?[A-Za-z]{2}', 'CZ' => '\d{3} ?\d{2}', 'SK' => '\d{3} ?\d{2}', 'SE' => '\d{3} ?\d{2}',
        'PL' => '\d{2}-\d{3}', 'PT' => '\d{4}-\d{3}',
    ];

    private const FALLBACK_POSTAL = '[A-Za-z0-9][A-Za-z0-9 -]{1,8}[A-Za-z0-9]';

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::NAMES;
    }

    public static function name(string $code): string
    {
        return self::NAMES[strtoupper($code)] ?? $code;
    }

    public static function is_known(string $code): bool
    {
        return isset(self::NAMES[strtoupper($code)]);
    }

    /**
     * Regex (ohne Begrenzer, auch als HTML-pattern verwendbar).
     */
    public static function postal_pattern(string $code): string
    {
        return self::POSTAL[strtoupper($code)] ?? self::FALLBACK_POSTAL;
    }

    public static function is_valid_postal_code(string $code, string $postal_code): bool
    {
        return preg_match('/^(?:' . self::postal_pattern($code) . ')$/', trim($postal_code)) === 1;
    }

    /**
     * Beispiel fuer Platzhalter/Hinweistext.
     */
    public static function postal_example(string $code): string
    {
        return match (strtoupper($code)) {
            'AT', 'CH', 'LI', 'BE', 'LU', 'HU', 'SI', 'DK' => '1010',
            'DE', 'FR', 'IT', 'ES', 'FI', 'HR'              => '10115',
            'NL'                                            => '1012 AB',
            'CZ', 'SK', 'SE'                                => '110 00',
            'PL'                                            => '00-950',
            'PT'                                            => '1000-001',
            default                                         => '',
        };
    }
}
