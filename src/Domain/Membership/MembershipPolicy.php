<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Membership;

/**
 * Entscheidet, ob ein MemberStatus fuer Mitgliedsvorteile reicht
 * (Mitgliedspreis, Gratisversand, Zahlung nach Erhalt). Reine Logik, ohne WP.
 *
 * Regeln (Einstellung membership.price_rule):
 * - active: jedes aktive Mitglied.
 * - paid:   nur mit eingetragenem Beitrag fuer das laufende Jahr. Optional
 *           reicht bis zu einem Stichtag (membership.grace_until, "MM-DD")
 *           auch der Beitrag des Vorjahres - deckt den Jahreswechsel ab, bis
 *           der Kassier die neuen Beitraege eingetragen hat.
 */
final class MembershipPolicy
{
    public const RULE_ACTIVE = 'active';
    public const RULE_PAID   = 'paid';

    public function __construct(
        private readonly string $rule,
        private readonly string $grace_until = '',
    ) {
    }

    /**
     * @return array<int, string>
     */
    public static function rules(): array
    {
        return [self::RULE_ACTIVE, self::RULE_PAID];
    }

    public function qualifies(?MemberStatus $status, \DateTimeImmutable $today): bool
    {
        if ($status === null || !$status->is_member) {
            return false;
        }

        if ($this->rule !== self::RULE_PAID) {
            return true;
        }

        if ($status->payment_ok === true) {
            return true;
        }

        return $this->in_grace($status, $today);
    }

    private function in_grace(MemberStatus $status, \DateTimeImmutable $today): bool
    {
        $grace = self::normalize_grace($this->grace_until);

        if ($grace === '' || $status->last_payment_year === null) {
            return false;
        }

        $year = (int) $today->format('Y');

        return $status->last_payment_year === $year - 1
            && $today->format('m-d') <= $grace;
    }

    /**
     * "31.03.", "31.3" oder "03-31" -> "03-31"; leer oder ungueltig -> "".
     * Gueltig ist jeder Tag eines Schaltjahres (29.02. erlaubt).
     */
    public static function normalize_grace(string $input): string
    {
        $input = trim($input);

        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.?$/', $input, $m) === 1) {
            [$day, $month] = [(int) $m[1], (int) $m[2]];
        } elseif (preg_match('/^(\d{2})-(\d{2})$/', $input, $m) === 1) {
            [$month, $day] = [(int) $m[1], (int) $m[2]];
        } else {
            return '';
        }

        if (!checkdate($month, $day, 2024)) {
            return '';
        }

        return sprintf('%02d-%02d', $month, $day);
    }

    /**
     * "03-31" -> "31.03." fuer die Anzeige; leer bleibt leer.
     */
    public static function format_grace(string $stored): string
    {
        $grace = self::normalize_grace($stored);

        return $grace === '' ? '' : substr($grace, 3, 2) . '.' . substr($grace, 0, 2) . '.';
    }
}
