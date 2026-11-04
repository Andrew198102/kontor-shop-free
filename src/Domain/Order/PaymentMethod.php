<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Order;

/**
 * Zahlungsarten (Abschnitt 6.3) - keine Kreditkarte/PayPal/Gateway.
 *
 *  - Vorkasse: erst bezahlen, dann Versand (Autostorno bei Nichtzahlung).
 *  - Auf Rechnung: sofort Versand, Zahlungsziel ab Versand, bei
 *    Nichtzahlung nur Erinnerungen - nie Storno (Ware ist schon raus).
 *  - Barzahlung bei Abholung (seit 2.1.1): nur mit Selbstabholung. Die
 *    Ware wird sofort zurueckgelegt (Versandauftrag wie bei Rechnung),
 *    bezahlt wird bei der Uebergabe - "Abholung erfassen" bucht die
 *    Barzahlung mit. Keine Frist, keine Erinnerung, kein Autostorno.
 *
 * Der gespeicherte Wert "invoice_on_delivery" bleibt aus Kompatibilitaet
 * (Datenbank, Kassen-Schnittstelle) bestehen.
 */
final class PaymentMethod
{
    public const PREPAYMENT = 'prepayment';
    public const INVOICE    = 'invoice_on_delivery';
    public const CASH       = 'cash_on_pickup';
    /** @deprecated alter Name fuer INVOICE */
    public const INVOICE_ON_DELIVERY = self::INVOICE;

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [self::PREPAYMENT, self::INVOICE, self::CASH];
    }

    public static function is_known(string $method): bool
    {
        return in_array($method, self::all(), true);
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::PREPAYMENT => __('Prepayment', 'kontor-shop'),
            self::INVOICE    => __('On invoice', 'kontor-shop'),
            self::CASH       => __('Cash on pickup', 'kontor-shop'),
        ];
    }

    public static function label(string $method): string
    {
        return self::labels()[$method] ?? $method;
    }
}
