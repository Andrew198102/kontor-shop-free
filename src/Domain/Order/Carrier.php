<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Order;

/**
 * Versanddienstleister mit Link zur Sendungsverfolgung. Der Link landet in
 * der Versandmail, damit der Kunde sein Paket mit einem Klick verfolgen kann.
 */
final class Carrier
{
    public const OTHER = 'other';

    /**
     * @return array<string, string> Schluessel => Anzeigename
     */
    public static function all(): array
    {
        return [
            'post_at' => 'Österreichische Post',
            'dpd'     => 'DPD',
            'gls'     => 'GLS',
            'dhl'     => 'DHL',
            'ups'     => 'UPS',
            'hermes'  => 'Hermes',
            self::OTHER => 'Sonstiger',
        ];
    }

    public static function label(?string $key): string
    {
        return $key !== null ? (self::all()[$key] ?? '') : '';
    }

    public static function sanitize(string $key): ?string
    {
        return array_key_exists($key, self::all()) ? $key : null;
    }

    /**
     * Link zur Sendungsverfolgung oder '' (unbekannter Dienst / keine Nummer).
     */
    public static function tracking_url(?string $carrier, ?string $tracking_number): string
    {
        $number = trim((string) $tracking_number);

        if ($number === '' || $carrier === null) {
            return '';
        }

        $templates = [
            'post_at' => 'https://www.post.at/s/sendungsdetails?snr=%s',
            'dpd'     => 'https://tracking.dpd.de/status/de_AT/parcel/%s',
            'gls'     => 'https://gls-group.com/AT/de/paketverfolgung?match=%s',
            'dhl'     => 'https://www.dhl.de/de/privatkunden/pakete-empfangen/verfolgen.html?piececode=%s',
            'ups'     => 'https://www.ups.com/track?tracknum=%s',
            'hermes'  => 'https://www.myhermes.de/empfangen/sendungsverfolgung/sendungsinformation/#%s',
        ];

        return isset($templates[$carrier]) ? sprintf($templates[$carrier], rawurlencode($number)) : '';
    }
}
