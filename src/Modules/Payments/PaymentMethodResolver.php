<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Payments;

use Kontor\Shop\Domain\Order\PaymentMethod;
use Kontor\Shop\Settings\Repository;

/**
 * Welche Zahlungsarten stehen dem aktuellen Kaeufer zur Auswahl (Abschnitt
 * 6.3): Vorkasse und "Auf Rechnung" lassen sich unter Einstellungen →
 * Zahlung einzeln ein- und ausschalten (mit Pro: Rechnung optional nur fuer
 * erkannte Mitglieder), dazu Barzahlung bei Abholung (nur mit Selbstabholung).
 * Bleibt fuer einen Kaeufer ausser Bar nichts uebrig (sollte die
 * Einstellungsseite verhindern), gilt zusaetzlich Vorkasse - so kann nie
 * niemand bestellen, auch nicht mit Versand.
 */
final class PaymentMethodResolver
{
    private PaymentOptions $options;

    public function __construct(Repository $settings)
    {
        $this->options = new PaymentOptions($settings);
    }

    /**
     * @return array<int, string>
     */
    public function available_methods(bool $is_member): array
    {
        $methods = [];

        if ($this->options->prepayment_enabled()) {
            $methods[] = PaymentMethod::PREPAYMENT;
        }

        if ($this->options->invoice_enabled()) {
            $methods[] = PaymentMethod::INVOICE;
        }

        // Nur mit Selbstabholung - die Kasse blendet es bei Versand aus (cash_needs_pickup()).
        if ($this->options->cash_enabled()) {
            $methods[] = PaymentMethod::CASH;
        }

        /**
         * Filter kontor_shop/payment/available_methods: Zahlungsarten fuer
         * diesen Kaeufer einschraenken, z. B. Pro: "Auf Rechnung" nur fuer
         * erkannte Mitglieder. Standard: wie unter Einstellungen → Zahlung
         * eingeschaltet, fuer alle gleich. Bleibt nichts uebrig, gilt Vorkasse.
         *
         * @param array<int, string> $methods   PaymentMethod::PREPAYMENT / ::INVOICE
         * @param bool               $is_member Kaeufer gilt als Mitglied (kontor_shop/membership/is_member)
         */
        $methods = array_values(array_filter(
            array_map('strval', (array) apply_filters('kontor_shop/payment/available_methods', $methods, $is_member)),
            static fn (string $method): bool => PaymentMethod::is_known($method)
        ));

        // Bar bei Abholung passt nicht fuer jede Bestellung (Versand) - es zaehlt nicht als "fuer alle".
        return array_diff($methods, [PaymentMethod::CASH]) !== [] ? $methods : array_merge([PaymentMethod::PREPAYMENT], $methods);
    }
}
