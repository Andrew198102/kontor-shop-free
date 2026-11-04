<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Catalog;

/**
 * Lager-Entity (Abschnitt 5.3). Jeder Artikel gehoert genau einem Lager mit
 * genau einem zustaendigen Lagerverwalter (Grundsatzentscheidung #2).
 */
final class Warehouse
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $name,
        public readonly string $contact_name,
        public readonly ?string $address,
        public readonly ?int $manager_user_id,
        public readonly bool $active,
        public readonly ?string $bank_account_id = null,
        /** Erkennungsfarbe (z. B. im Versand-Dialog), null = automatisch */
        public readonly ?string $color = null,
    ) {
    }

    /**
     * Farbe zum Unterscheiden der Lager: eingestellt oder eine feste Farbe je Lager-ID.
     */
    public function display_color(): string
    {
        if ($this->color !== null && preg_match('/^#[0-9a-fA-F]{6}$/', $this->color) === 1) {
            return $this->color;
        }

        $palette = ['#2271b1', '#b35900', '#1a7f37', '#8a3ffc', '#c9356e', '#00838f', '#6d4c41', '#d63638'];

        return $palette[((int) $this->id) % count($palette)];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function from_row(array $row): self
    {
        return new self(
            isset($row['id']) ? (int) $row['id'] : null,
            (string) $row['name'],
            (string) ($row['contact_name'] ?? ''),
            isset($row['address']) && $row['address'] !== null ? (string) $row['address'] : null,
            isset($row['manager_user_id']) && $row['manager_user_id'] !== null ? (int) $row['manager_user_id'] : null,
            (bool) ($row['active'] ?? true),
            isset($row['bank_account_id']) && $row['bank_account_id'] !== null && $row['bank_account_id'] !== '' ? (string) $row['bank_account_id'] : null,
            isset($row['color']) && $row['color'] !== null && $row['color'] !== '' ? (string) $row['color'] : null
        );
    }
}
