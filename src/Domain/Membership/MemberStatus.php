<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Membership;

/**
 * Mitgliedsstatus der angemeldeten Person laut Provider - noch ohne
 * Shop-Regel. Ob daraus ein Mitgliedsvorteil folgt, entscheidet
 * MembershipPolicy anhand der Einstellungen (Beitrag bezahlt? Kulanzfrist?).
 *
 * Enthaelt bewusst keine Personendaten: nur dieser Status darf kurz
 * zwischengespeichert werden (Name/Adresse stehen in MemberInfo und werden
 * nie gespeichert). null bei payment_ok/last_payment_year heisst "liefert die
 * Quelle nicht".
 */
final class MemberStatus
{
    public function __construct(
        public readonly bool $is_member,
        public readonly ?bool $payment_ok = null,
        public readonly ?int $last_payment_year = null,
    ) {
    }

    /**
     * @return array{is_member: bool, payment_ok: bool|null, last_payment_year: int|null}
     */
    public function to_array(): array
    {
        return [
            'is_member'         => $this->is_member,
            'payment_ok'        => $this->payment_ok,
            'last_payment_year' => $this->last_payment_year,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function from_array(array $data): self
    {
        return new self(
            ($data['is_member'] ?? false) === true,
            isset($data['payment_ok']) && is_bool($data['payment_ok']) ? $data['payment_ok'] : null,
            isset($data['last_payment_year']) && is_numeric($data['last_payment_year']) && (int) $data['last_payment_year'] > 0
                ? (int) $data['last_payment_year']
                : null,
        );
    }
}
