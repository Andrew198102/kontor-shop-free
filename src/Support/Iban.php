<?php

declare(strict_types=1);

namespace Kontor\Shop\Support;

/**
 * Formale Pruefung von IBAN (Laenge je Land + Modulo-97-Pruefsumme) und BIC.
 * Prueft nur die Form, nicht ob das Konto existiert. Wird in den
 * Einstellungen als Warnung genutzt, nie als Sperre.
 */
final class Iban
{
    /** @var array<string, int> */
    private const LENGTHS = [
        'AT' => 20, 'BE' => 16, 'CH' => 21, 'CZ' => 24, 'DE' => 22, 'DK' => 18, 'ES' => 24, 'FI' => 18,
        'FR' => 27, 'GB' => 22, 'HR' => 21, 'HU' => 28, 'IE' => 22, 'IT' => 27, 'LI' => 21, 'LU' => 20,
        'NL' => 18, 'NO' => 15, 'PL' => 28, 'PT' => 25, 'SE' => 24, 'SI' => 19, 'SK' => 24,
    ];

    public static function normalize(string $iban): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $iban));
    }

    public static function is_valid(string $iban): bool
    {
        $iban = self::normalize($iban);

        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban) !== 1) {
            return false;
        }

        $country = substr($iban, 0, 2);

        if (isset(self::LENGTHS[$country]) && strlen($iban) !== self::LENGTHS[$country]) {
            return false;
        }

        // Erste 4 Zeichen ans Ende, Buchstaben zu Zahlen (A=10 ... Z=35), Rest durch 97 muss 1 sein.
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $remainder  = 0;

        foreach (str_split($rearranged) as $char) {
            $digits = ctype_digit($char) ? $char : (string) (ord($char) - 55);

            foreach (str_split($digits) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }

        return $remainder === 1;
    }

    public static function is_valid_bic(string $bic): bool
    {
        return preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/', strtoupper(trim($bic))) === 1;
    }

    /**
     * Gruppiert in Vierergruppen ("AT61 1904 3002 ...") fuer die Anzeige.
     */
    public static function format(string $iban): string
    {
        return trim(chunk_split(self::normalize($iban), 4, ' '));
    }
}
