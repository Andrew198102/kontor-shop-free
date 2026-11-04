<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Checkout;

use Kontor\Shop\Support\AddressFormatter;
use Kontor\Shop\Support\Countries;

/**
 * Prueft und normalisiert die Formulareingaben des Checkouts (Kontakt,
 * Lieferadresse, optionale abweichende Rechnungsadresse). Reine Logik ohne
 * WordPress-Abhaengigkeit. Liefert Fehlermeldungen je Feld (fuer die Anzeige
 * direkt am Feld) und die bereinigten Werte, aus denen auch die formatierten
 * Adresstexte fuer die Bestellung entstehen.
 */
final class CheckoutValidator
{
    public const PHONE_OFF      = 'off';
    public const PHONE_OPTIONAL = 'optional';
    public const PHONE_REQUIRED = 'required';

    /**
     * @param array<string, mixed> $input   rohe, bereits "unslashed" Formularwerte
     * @param array{allow_pickup?: bool, countries?: array<int, string>, phone?: string, billing_enabled?: bool} $options
     * @return array{errors: array<string, string>, values: array<string, mixed>}
     */
    public static function validate(array $input, array $options): array
    {
        $errors    = [];
        $countries = array_values(array_filter(
            array_map('strtoupper', $options['countries'] ?? ['AT']),
            [Countries::class, 'is_known']
        )) ?: ['AT'];
        $phone_mode = $options['phone'] ?? self::PHONE_OPTIONAL;

        $text = static fn (string $key): string => self::clean($input[$key] ?? '');

        $values = [
            'first_name'   => $text('first_name'),
            'last_name'    => $text('last_name'),
            'email'        => trim((string) ($input['customer_email'] ?? '')),
            'phone'        => $text('customer_phone'),
            'company'      => $text('company'),
            'street'       => $text('street'),
            'address2'     => $text('address2'),
            'postal_code'  => $text('postal_code'),
            'city'         => $text('city'),
            'country'      => strtoupper($text('country')),
            'self_pickup'  => !empty($options['allow_pickup']) && !empty($input['self_pickup']),
            'billing_different' => !empty($options['billing_enabled']) && !empty($input['billing_different']),
            'billing_name'      => $text('billing_name'),
            'billing_company'   => $text('billing_company'),
            'billing_street'    => $text('billing_street'),
            'billing_address2'  => $text('billing_address2'),
            'billing_postal_code' => $text('billing_postal_code'),
            'billing_city'      => $text('billing_city'),
            'billing_country'   => strtoupper($text('billing_country')),
            'billing_vat_id'    => strtoupper((string) preg_replace('/\s+/', '', (string) ($input['billing_vat_id'] ?? ''))),
        ];

        // ---- Kontakt
        foreach (['first_name' => __('Please enter your first name.', 'kontor-shop'), 'last_name' => __('Please enter your last name.', 'kontor-shop')] as $field => $message) {
            if ($values[$field] === '' || preg_match('/\p{L}/u', $values[$field]) !== 1) {
                $errors[$field] = $message;
            } elseif (self::length($values[$field]) > 80) {
                $errors[$field] = __('Please use 80 characters at most.', 'kontor-shop');
            }
        }

        if ($values['email'] === '') {
            $errors['customer_email'] = __('Please enter your email address.', 'kontor-shop');
        } elseif (filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false || self::length($values['email']) > 190) {
            $errors['customer_email'] = __('This email address doesn’t look right (e.g. name@example.com).', 'kontor-shop');
        }

        if ($phone_mode !== self::PHONE_OFF) {
            if ($values['phone'] === '') {
                if ($phone_mode === self::PHONE_REQUIRED) {
                    $errors['customer_phone'] = __('Please enter a phone number for questions about the delivery.', 'kontor-shop');
                }
            } elseif (!self::is_valid_phone($values['phone'])) {
                $errors['customer_phone'] = __('This phone number doesn’t look right (e.g. +43 660 1234567).', 'kontor-shop');
            }
        } else {
            $values['phone'] = '';
        }

        if (self::length($values['company']) > 100) {
            $errors['company'] = __('Please use 100 characters at most.', 'kontor-shop');
        }

        // ---- Lieferadresse (nur bei Zustellung)
        if (!$values['self_pickup']) {
            $errors += self::validate_address($values, '', $countries, __('Shipping country', 'kontor-shop'));
        }

        // ---- Abweichende Rechnungsadresse
        if ($values['billing_different']) {
            if ($values['billing_name'] === '' && $values['billing_company'] === '') {
                $errors['billing_name'] = __('Please enter a name or company for the invoice.', 'kontor-shop');
            }

            $errors += self::validate_address($values, 'billing_', array_keys(Countries::all()), __('Billing country', 'kontor-shop'));

            if ($values['billing_vat_id'] !== '' && preg_match('/^[A-Z]{2}[A-Z0-9]{2,12}$/', $values['billing_vat_id']) !== 1) {
                $errors['billing_vat_id'] = __('The VAT ID doesn’t look right (e.g. ATU12345678).', 'kontor-shop');
            }
        }

        // ---- Abgeleitete Werte
        $full_name = trim($values['first_name'] . ' ' . $values['last_name']);

        $values['customer_name'] = $full_name;
        $values['delivery_address'] = $values['self_pickup']
            ? null
            : AddressFormatter::format([
                'company'     => $values['company'],
                'name'        => $full_name,
                'street'      => $values['street'],
                'address2'    => $values['address2'],
                'postal_code' => $values['postal_code'],
                'city'        => $values['city'],
                'country'     => $values['country'],
            ]);

        $values['billing_display_name'] = $values['billing_different']
            ? ($values['billing_name'] !== '' ? $values['billing_name'] : $values['billing_company'])
            : null;
        $values['billing_address'] = $values['billing_different']
            ? AddressFormatter::format([
                'company'     => $values['billing_name'] !== '' ? $values['billing_company'] : '',
                'name'        => $values['billing_name'],
                'street'      => $values['billing_street'],
                'address2'    => $values['billing_address2'],
                'postal_code' => $values['billing_postal_code'],
                'city'        => $values['billing_city'],
                'country'     => $values['billing_country'],
            ])
            : null;

        return ['errors' => $errors, 'values' => $values];
    }

    /**
     * @param array<string, mixed> $values
     * @param array<int, string>   $allowed_countries
     * @return array<string, string>
     */
    private static function validate_address(array $values, string $prefix, array $allowed_countries, string $country_label): array
    {
        $errors  = [];
        $street  = (string) $values[$prefix . 'street'];
        $country = (string) $values[$prefix . 'country'];

        if ($street === '') {
            $errors[$prefix . 'street'] = __('Please enter street and house number.', 'kontor-shop');
        } elseif (preg_match('/\p{L}/u', $street) !== 1 || preg_match('/\d/', $street) !== 1) {
            $errors[$prefix . 'street'] = __('Please enter street and house number (e.g. Main Street 5).', 'kontor-shop');
        } elseif (self::length($street) > 120) {
            $errors[$prefix . 'street'] = __('Please use 120 characters at most.', 'kontor-shop');
        }

        if (self::length((string) $values[$prefix . 'address2']) > 120) {
            $errors[$prefix . 'address2'] = __('Please use 120 characters at most.', 'kontor-shop');
        }

        if ($country === '' || !in_array($country, $allowed_countries, true)) {
            $errors[$prefix . 'country'] = sprintf(
                /* translators: %s: field label */
                __('Please choose a valid country (%s).', 'kontor-shop'),
                $country_label
            );
        }

        $postal = (string) $values[$prefix . 'postal_code'];

        if ($postal === '') {
            $errors[$prefix . 'postal_code'] = __('Please enter the postal code.', 'kontor-shop');
        } elseif (!Countries::is_valid_postal_code($country !== '' ? $country : 'AT', $postal)) {
            $example = Countries::postal_example($country !== '' ? $country : 'AT');

            $errors[$prefix . 'postal_code'] = $example !== ''
                ? sprintf(
                    /* translators: %s: example postal code */
                    __('This postal code doesn’t match the selected country (e.g. %s).', 'kontor-shop'),
                    $example
                )
                : __('This postal code doesn’t look right.', 'kontor-shop');
        }

        $city = (string) $values[$prefix . 'city'];

        if ($city === '' || preg_match('/\p{L}/u', $city) !== 1) {
            $errors[$prefix . 'city'] = __('Please enter the city.', 'kontor-shop');
        } elseif (self::length($city) > 80) {
            $errors[$prefix . 'city'] = __('Please use 80 characters at most.', 'kontor-shop');
        }

        return $errors;
    }

    /**
     * Telefonnummer: Ziffern, Leerzeichen, + - / ( ) - insgesamt 6 bis 15 Ziffern.
     */
    public static function is_valid_phone(string $phone): bool
    {
        if (preg_match('/^\+?[\d\s\-\/().]+$/', $phone) !== 1) {
            return false;
        }

        $digits = strlen((string) preg_replace('/\D/', '', $phone));

        return $digits >= 6 && $digits <= 15;
    }

    /**
     * Steuerzeichen entfernen, Leerraum zusammenfassen, trimmen.
     */
    public static function clean(mixed $value): string
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function length(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    }
}
