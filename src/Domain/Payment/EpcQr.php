<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Payment;

use Kontor\Shop\Support\Iban;
use Kontor\Shop\Support\QrCode;

/**
 * Ueberweisungs-QR-Code nach EPC069-12 ("GiroCode", "Zahlen mit Code"):
 * Banking-Apps im Euroraum fuellen damit Empfaenger, IBAN, Betrag und
 * Zahlungsreferenz aus - der Kunde muss nichts abtippen, die Referenz
 * kommt richtig an. Reine Logik; Fehlerkorrektur M wie vom Standard verlangt.
 */
final class EpcQr
{
    private const MAX_AMOUNT = 999999999.99;

    /**
     * Inhalt des Codes oder null, wenn er nicht gueltig waere (keine/ungueltige
     * IBAN, kein Empfaenger, Betrag ausserhalb 0,01 bis 999.999.999,99 €).
     */
    public static function payload(BankAccount $account, float $amount, string $reference): ?string
    {
        $iban      = Iban::normalize($account->iban);
        $bic       = strtoupper(preg_replace('/\s+/', '', $account->bic) ?? '');
        $recipient = self::clean($account->recipient, 70);
        $amount    = round($amount, 2);

        if (!Iban::is_valid($iban) || $recipient === '' || $amount < 0.01 || $amount > self::MAX_AMOUNT) {
            return null;
        }

        return implode("\n", [
            'BCD',                                   // Kennung
            '002',                                   // Version (BIC optional)
            '1',                                     // Zeichensatz UTF-8
            'SCT',                                   // SEPA-Ueberweisung
            Iban::is_valid_bic($bic) ? $bic : '',
            $recipient,
            $iban,
            'EUR' . number_format($amount, 2, '.', ''),
            '',                                      // Zweck-Code
            '',                                      // strukturierte Referenz (RF) - nicht genutzt
            self::clean($reference, 140),            // Verwendungszweck = Zahlungsreferenz
        ]);
    }

    /**
     * Fertige SVG-Grafik oder '' (siehe payload()).
     */
    public static function svg(BankAccount $account, float $amount, string $reference, string $title = ''): string
    {
        $payload = self::payload($account, $amount, $reference);
        $matrix  = $payload !== null ? QrCode::matrix($payload, QrCode::ECC_M) : null;

        return $matrix !== null ? QrCode::svg($matrix, $title) : '';
    }

    private static function clean(string $text, int $max): string
    {
        $text = trim(preg_replace('/[\r\n\t]+/', ' ', $text) ?? '');

        return function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max);
    }
}
