<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Shipping;

use Kontor\Shop\Settings\Repository;
use Kontor\Shop\Support\Countries;

/**
 * Versandpreise nach Inland und Ausland (Einstellungen → Versand):
 *  - shipping.home_country: das Inland (leer = erstes Lieferland, sonst AT),
 *  - shipping.flat_rate: Versand ins Inland,
 *  - shipping.flat_rate_abroad: Versand in alle anderen Lieferlaender
 *    (null = wie Inland - so rechnen Shops von vor 2.9.14 unveraendert weiter).
 */
final class ShippingRates
{
    public static function home_country(Repository $settings): string
    {
        $home = strtoupper((string) $settings->get('shipping.home_country', ''));

        if (Countries::is_known($home)) {
            return $home;
        }

        foreach ((array) $settings->get('checkout.countries', []) as $code) {
            $code = strtoupper((string) $code);

            if (Countries::is_known($code)) {
                return $code;
            }
        }

        return 'AT';
    }

    /** Lieferung in dieses Land kostet den Auslandspreis? Leeres Land (noch unbekannt) = Inland. */
    public static function is_abroad(Repository $settings, string $country): bool
    {
        $country = strtoupper(trim($country));

        return $country !== '' && $country !== self::home_country($settings);
    }

    public static function domestic(Repository $settings): float
    {
        return round(max(0.0, (float) $settings->get('shipping.flat_rate', 0)), 2);
    }

    public static function abroad(Repository $settings): float
    {
        $rate = self::optional($settings->get('shipping.flat_rate_abroad', null));

        return $rate ?? self::domestic($settings);
    }

    /** Eigener Auslandspreis eingetragen (nicht nur "wie Inland")? */
    public static function has_own_abroad_rate(Repository $settings): bool
    {
        return self::optional($settings->get('shipping.flat_rate_abroad', null)) !== null;
    }

    /**
     * Angehakte Lieferlaender ausser dem Inland.
     *
     * @return array<int, string>
     */
    public static function abroad_countries(Repository $settings): array
    {
        $home = self::home_country($settings);

        return array_values(array_filter(
            array_unique(array_map(static fn ($code): string => strtoupper((string) $code), (array) $settings->get('checkout.countries', []))),
            static fn (string $code): bool => $code !== $home && Countries::is_known($code)
        ));
    }

    /** Liefert der Shop ins Inland (Inland unter den Lieferlaendern)? */
    public static function ships_home(Repository $settings): bool
    {
        $codes = array_map(static fn ($code): string => strtoupper((string) $code), (array) $settings->get('checkout.countries', ['AT']));

        return in_array(self::home_country($settings), $codes, true);
    }

    /**
     * Gespeicherter Betrag oder null, wenn leer (null, '' oder nicht numerisch).
     */
    public static function optional(mixed $value): ?float
    {
        if ($value === null || $value === '' || (!is_int($value) && !is_float($value) && !is_numeric($value))) {
            return null;
        }

        return round(max(0.0, (float) $value), 2);
    }

    /**
     * Eingabe aus einem Formularfeld: '' = null (leer gelassen), "4,90" = 4.9.
     */
    public static function parse_input(string $raw): ?float
    {
        $raw = trim(str_replace(',', '.', $raw));

        return $raw === '' || !is_numeric($raw) ? null : round(max(0.0, (float) $raw), 2);
    }
}
