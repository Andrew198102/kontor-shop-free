<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Payments;

use Kontor\Shop\Domain\Order\PaymentDeadline;
use Kontor\Shop\Domain\Order\PaymentTerms;
use Kontor\Shop\Settings\Repository;

/**
 * Zahlungs-Einstellungen (Einstellungen → Zahlung) an einer Stelle gelesen:
 * welche Zahlungsarten angeboten werden und welche Fristen gelten.
 *
 * Altbestand: "payment.allow_invoice_on_delivery_for_members" (Erlagschein
 * nur fuer Mitglieder) gilt weiter als "Auf Rechnung" (mit Pro: nur fuer
 * Mitglieder), bis die Zahlungs-Einstellungen einmal gespeichert werden.
 */
final class PaymentOptions
{
    public function __construct(private readonly Repository $settings)
    {
    }

    public function prepayment_enabled(): bool
    {
        return (bool) $this->settings->get('payment.prepayment_enabled', true);
    }

    /** Barzahlung bei Abholung anbieten (wirkt nur, wenn Selbstabholung erlaubt ist). */
    public function cash_enabled(): bool
    {
        return (bool) $this->settings->get('payment.cash_enabled', false) && (bool) $this->settings->get('shipping.allow_self_pickup', true);
    }

    public function invoice_enabled(): bool
    {
        return (bool) $this->settings->get('payment.invoice_enabled', false) || $this->legacy_invoice();
    }

    /**
     * Werden unbezahlte Vorkasse-Bestellungen nach der Frist automatisch
     * storniert? Steuert nur Texte (Bestaetigung, Mails: "danach wird die
     * Bestellung automatisch storniert").
     */
    public static function auto_cancel_active(): bool
    {
        /**
         * Filter kontor_shop/payment/auto_cancel_active: ob ein Autostorno
         * laeuft (z. B. Pro mit eingeschaltetem "Autostorno"). Standard:
         * false - ohne Erweiterung storniert der Shop nie von selbst.
         *
         * @param bool $active
         */
        return (bool) apply_filters('kontor_shop/payment/auto_cancel_active', false);
    }

    public function terms(): PaymentTerms
    {
        return new PaymentTerms(
            PaymentDeadline::sanitize_days($this->settings->get('payment.payment_days', PaymentDeadline::DEFAULT_DAYS)),
            PaymentTerms::sanitize_invoice_days($this->settings->get('payment.invoice_days', PaymentTerms::DEFAULT_INVOICE_DAYS))
        );
    }

    /**
     * Hat danach jeder Kaeufer mindestens eine Zahlungsart? (Vorkasse an,
     * oder Rechnung fuer alle - $members_only: nur mit Pro.)
     */
    public static function covers_everyone(bool $prepayment, bool $invoice, bool $members_only = false): bool
    {
        return $prepayment || ($invoice && !$members_only);
    }

    /**
     * Altschalter "Erlagschein nur fuer Mitglieder" noch gesetzt (siehe Klassenkommentar).
     */
    public function legacy_invoice(): bool
    {
        return (bool) $this->settings->get('payment.allow_invoice_on_delivery_for_members', false);
    }
}
