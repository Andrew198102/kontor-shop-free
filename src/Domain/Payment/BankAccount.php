<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Payment;

/**
 * Bankverbindung fuer Vorkasse. Mehrere Konten sind moeglich; jedes Lager
 * kann einem Konto zugeordnet werden (Infrastructure\Payment\BankAccounts),
 * damit die Zahlungen je Lager getrennt eingehen. Reines Datenobjekt.
 *
 * $source: '' = im Shop gepflegt; sonst Kennung einer externen Quelle (z. B. von einem Add-on abgeglichen, im Shop
 * nur lesbar; Name/Verbindung ueber den Filter kontor_shop/payment/external_source, Admin\Settings\PaymentSection).
 */
final class BankAccount
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $recipient,
        public readonly string $iban,
        public readonly string $bic,
        public readonly string $source = '',
        public readonly string $synced_at = '',
    ) {
    }

    /** Fremdkonto (externe Quelle): im Shop nur lesbar und auswählbar. */
    public function is_external(): bool
    {
        return $this->source !== '';
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function from_array(array $row): self
    {
        return new self(
            (string) ($row['id'] ?? ''),
            (string) ($row['label'] ?? ''),
            (string) ($row['recipient'] ?? ''),
            (string) ($row['iban'] ?? ''),
            (string) ($row['bic'] ?? ''),
            (string) ($row['source'] ?? ''),
            (string) ($row['synced_at'] ?? '')
        );
    }

    /**
     * @return array<string, string>
     */
    public function to_array(): array
    {
        return [
            'id'        => $this->id,
            'label'     => $this->label,
            'recipient' => $this->recipient,
            'iban'      => $this->iban,
            'bic'       => $this->bic,
        ] + array_filter([
            'source'    => $this->source,
            'synced_at' => $this->synced_at,
        ], static fn (string $v): bool => $v !== '');
    }

    /**
     * Anzeigename in Auswahllisten (Bezeichnung, sonst Empfaenger/IBAN).
     */
    public function display_name(): string
    {
        if ($this->label !== '') {
            return $this->label;
        }

        return $this->recipient !== '' ? $this->recipient : $this->iban;
    }
}
