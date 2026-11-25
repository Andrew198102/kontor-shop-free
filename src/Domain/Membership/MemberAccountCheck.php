<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Membership;

/**
 * Mit welchem Mitgliedskonto wurde bestellt, und weichen die Angaben der
 * Bestellung vom Profil dieses Kontos ab? Wird beim Bestellen einmal
 * festgehalten (Spalte orders.member_snapshot) und spaeter nur angezeigt.
 *
 * Zweck: sehen, wenn jemand mit seinem Mitgliedskonto fuer eine andere
 * Person einkauft (Mitgliederpreis fuer Nicht-Mitglieder). Erlaubt bleibt
 * das - der Shop sperrt nichts, er zeigt es nur an, damit der Verein bei
 * Bedarf nachfragen kann.
 *
 * Gespeichert wird so wenig wie moeglich: Kennung, Mitgliedsnummer, Name und
 * E-Mail des Kontos sowie nur die abweichenden Felder (Profilwert und
 * Bestellwert). Kleinigkeiten (Gross-/Kleinschreibung, Leerzeichen,
 * "Str."/"Straße", Telefon mit +43 oder 0) zaehlen nicht als Abweichung.
 * Leere Felder (Profil liefert nichts, Selbstabholung ohne Adresse) werden
 * nicht verglichen.
 */
final class MemberAccountCheck
{
    /** Name oder E-Mail weichen ab: vermutlich eine andere Person. */
    public const PERSON_FIELDS = ['name', 'email'];

    /** Reihenfolge der Felder in der Anzeige */
    public const FIELDS = ['name', 'email', 'phone', 'street', 'postal_code', 'city', 'country'];

    /**
     * @param array<int, array{field: string, profile: string, order: string}> $deviations
     */
    public function __construct(
        public readonly ?string $member_id,
        public readonly ?string $member_number,
        public readonly string $name,
        public readonly string $email,
        public readonly array $deviations = [],
    ) {
    }

    /**
     * Vergleicht die Daten des angemeldeten Kontos mit den geprueften Kassenfeldern
     * (CheckoutValidator: first_name, last_name, email, phone, street, postal_code, city, country, self_pickup).
     *
     * @param array<string, mixed> $values
     */
    public static function capture(MemberInfo $info, array $values): self
    {
        $text = static fn (string $key): string => trim((string) ($values[$key] ?? ''));

        $profile_name = trim($info->first_name . ' ' . $info->last_name);
        $profile_name = $profile_name !== '' ? $profile_name : trim($info->name);

        $pairs = [
            'name'  => [$profile_name, trim($text('first_name') . ' ' . $text('last_name'))],
            'email' => [$info->email, $text('email')],
            'phone' => [$info->phone, $text('phone')],
        ];

        // Bei Selbstabholung gibt es keine Lieferadresse zum Vergleichen.
        if (empty($values['self_pickup'])) {
            $pairs += [
                'street'      => [$info->street, $text('street')],
                'postal_code' => [$info->postal_code, $text('postal_code')],
                'city'        => [$info->city, $text('city')],
                'country'     => [$info->country, $text('country')],
            ];
        }

        $deviations = [];

        foreach ($pairs as $field => [$profile, $order]) {
            $profile = trim($profile);

            if ($profile === '' || $order === '' || self::same($field, $profile, $order)) {
                continue;
            }

            $deviations[] = ['field' => $field, 'profile' => $profile, 'order' => $order];
        }

        return new self(
            $info->member_id !== null && $info->member_id !== '' ? $info->member_id : null,
            $info->member_number !== null && $info->member_number !== '' ? $info->member_number : null,
            $profile_name,
            trim($info->email),
            $deviations
        );
    }

    /** Weichen Name oder E-Mail ab - wurde also vermutlich fuer jemand anderen bestellt? */
    public function other_person(): bool
    {
        foreach ($this->deviations as $deviation) {
            if (in_array($deviation['field'], self::PERSON_FIELDS, true)) {
                return true;
            }
        }

        return false;
    }

    public function has_deviations(): bool
    {
        return $this->deviations !== [];
    }

    /** Abweichender Wert eines Felds in der Bestellung, null = keine Abweichung. */
    public function order_value(string $field): ?string
    {
        foreach ($this->deviations as $deviation) {
            if ($deviation['field'] === $field) {
                return $deviation['order'];
            }
        }

        return null;
    }

    /**
     * @return array{member_id: string|null, member_number: string|null, name: string, email: string, deviations: array<int, array{field: string, profile: string, order: string}>}
     */
    public function to_array(): array
    {
        return [
            'member_id'     => $this->member_id,
            'member_number' => $this->member_number,
            'name'          => $this->name,
            'email'         => $this->email,
            'deviations'    => $this->deviations,
        ];
    }

    public function to_json(): string
    {
        return (string) json_encode($this->to_array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Aus der gespeicherten Spalte; null bei leerem oder kaputtem Inhalt.
     */
    public static function from_json(mixed $json): ?self
    {
        if (!is_string($json) || $json === '') {
            return null;
        }

        $data = json_decode($json, true);

        if (!is_array($data)) {
            return null;
        }

        $deviations = [];

        foreach ((array) ($data['deviations'] ?? []) as $deviation) {
            if (is_array($deviation) && in_array($deviation['field'] ?? null, self::FIELDS, true)) {
                $deviations[] = [
                    'field'   => (string) $deviation['field'],
                    'profile' => (string) ($deviation['profile'] ?? ''),
                    'order'   => (string) ($deviation['order'] ?? ''),
                ];
            }
        }

        $string = static fn (mixed $value): ?string => is_scalar($value) && (string) $value !== '' ? (string) $value : null;

        return new self(
            $string($data['member_id'] ?? null),
            $string($data['member_number'] ?? null),
            (string) ($data['name'] ?? ''),
            (string) ($data['email'] ?? ''),
            $deviations
        );
    }

    /**
     * Gleich bis auf Kleinigkeiten?
     */
    public static function same(string $field, string $a, string $b): bool
    {
        return match ($field) {
            'name'        => self::name_tokens($a) === self::name_tokens($b),
            'email'       => mb_strtolower(trim($a)) === mb_strtolower(trim($b)),
            'phone'       => self::same_phone($a, $b),
            'street'      => self::street_key($a) === self::street_key($b),
            'postal_code' => strtoupper((string) preg_replace('/[\s-]+/', '', $a)) === strtoupper((string) preg_replace('/[\s-]+/', '', $b)),
            'country'     => strtoupper(trim($a)) === strtoupper(trim($b)),
            default       => self::plain($a) === self::plain($b),
        };
    }

    /** Kleinbuchstaben, Satzzeichen zu Leerzeichen, Mehrfach-Leerzeichen zusammengefasst. */
    private static function plain(string $value): string
    {
        $value = mb_strtolower($value);
        $value = (string) preg_replace('/[.,;:\'"()\/-]+/u', ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * Namensteile ohne Reihenfolge ("Maier Hubert" = "Hubert Maier").
     *
     * @return array<int, string>
     */
    private static function name_tokens(string $name): array
    {
        $tokens = array_values(array_filter(explode(' ', self::plain($name)), static fn (string $t): bool => $t !== ''));
        sort($tokens);

        return $tokens;
    }

    private static function street_key(string $street): string
    {
        $street = self::plain($street);
        $street = (string) preg_replace('/(stra(ß|ss)e|str)\b/u', 'str', $street);

        return (string) preg_replace('/\s+/u', '', $street);
    }

    /** Nur Ziffern; "+43 664 …", "0043 664 …" und "0664 …" gelten als gleich (Vergleich der hinteren Stellen). */
    private static function same_phone(string $a, string $b): bool
    {
        $a = (string) preg_replace('/\D+/', '', $a);
        $b = (string) preg_replace('/\D+/', '', $b);

        if ($a === $b) {
            return true;
        }

        if (strlen($a) < 6 || strlen($b) < 6) {
            return false;
        }

        $length = min(strlen($a), strlen($b), 9);

        return substr($a, -$length) === substr($b, -$length);
    }
}
