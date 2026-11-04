<?php

declare(strict_types=1);

namespace Kontor\Shop\Support;

/**
 * Baut aus den validierten Adressfeldern den mehrzeiligen Adresstext, wie er
 * in Bestellung, Mails, Export und Versandetikett verwendet wird.
 */
final class AddressFormatter
{
    /**
     * @param array{name?: string, company?: string, street?: string, address2?: string, postal_code?: string, city?: string, country?: string} $parts
     */
    public static function format(array $parts): string
    {
        $lines = [];

        foreach (['company', 'name', 'street', 'address2'] as $key) {
            $value = trim((string) ($parts[$key] ?? ''));

            if ($value !== '') {
                $lines[] = $value;
            }
        }

        $city = trim(trim((string) ($parts['postal_code'] ?? '')) . ' ' . trim((string) ($parts['city'] ?? '')));

        if ($city !== '') {
            $lines[] = $city;
        }

        $country = trim((string) ($parts['country'] ?? ''));

        if ($country !== '') {
            $lines[] = Countries::name($country);
        }

        return implode("\n", $lines);
    }

    /**
     * "PLZ Ort" (+ " · Land" ausserhalb Oesterreichs) aus einer mehrzeiligen
     * Lieferadresse (format()) - fuer kompakte Listenzeilen.
     */
    public static function place(?string $address): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", (string) $address)), static fn (string $l): bool => $l !== ''));
        $city  = '';
        $index = -1;

        foreach ($lines as $i => $line) {
            if (preg_match('/^([A-Z]{1,2}-)?\d{4,5}\s+\S/u', $line) === 1) {
                $city  = $line;
                $index = $i;
            }
        }

        if ($city === '') {
            return '';
        }

        $country = $lines[$index + 1] ?? '';

        return $country !== '' && !in_array($country, ['Österreich', 'Austria', 'AT'], true) ? $city . ' · ' . $country : $city;
    }
}
