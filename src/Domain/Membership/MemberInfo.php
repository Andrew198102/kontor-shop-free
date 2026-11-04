<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Membership;

/**
 * Von einem MembershipProvider gelieferte Daten der angemeldeten Person
 * (Abschnitt 5.1) - wird nicht persistiert, nur live abgefragt. Dient der
 * Anzeige ("Angemeldet als ...") und der Datenuebernahme in die Kasse
 * (Vorbefuellung, der Kunde kann alles aendern).
 *
 * Leere Strings heissen "liefert die Quelle nicht". $country ist ein
 * ISO-3166-Alpha-2-Code oder leer.
 */
final class MemberInfo
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $member_number = null,
        public readonly string $first_name = '',
        public readonly string $last_name = '',
        public readonly string $email = '',
        public readonly string $phone = '',
        public readonly string $street = '',
        public readonly string $postal_code = '',
        public readonly string $city = '',
        public readonly string $country = '',
    ) {
    }

    /**
     * Vorbelegung der Kassenfelder (Feldname => Wert), nur nicht-leere Werte.
     *
     * @return array<string, string>
     */
    public function checkout_prefill(): array
    {
        return array_filter([
            'first_name'     => $this->first_name,
            'last_name'      => $this->last_name,
            'customer_email' => $this->email,
            'customer_phone' => $this->phone,
            'street'         => $this->street,
            'postal_code'    => $this->postal_code,
            'city'           => $this->city,
            'country'        => $this->country,
        ], static fn (string $value): bool => $value !== '');
    }
}
