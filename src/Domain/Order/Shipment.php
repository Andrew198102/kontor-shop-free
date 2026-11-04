<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Order;

/**
 * Eine Sendung bzw. Abholung: ein Teil (oder alles) einer Bestellung, der
 * aus einem Lager rausgegangen ist - mit Versanddienst und Trackingnummer.
 * Positionen verweisen per order_items.shipment_id darauf.
 */
final class Shipment
{
    public const TYPE_SHIPPED   = 'shipped';
    public const TYPE_PICKED_UP = 'picked_up';

    public function __construct(
        public readonly ?int $id,
        public readonly int $order_id,
        public readonly ?int $warehouse_id,
        public readonly string $type,
        public readonly ?string $carrier,
        public readonly ?string $tracking_number,
        public readonly ?string $note,
        public readonly ?int $user_id,
        public readonly ?string $created_at,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function from_row(array $row): self
    {
        $nullable = static fn ($value): ?string => $value === null || $value === '' ? null : (string) $value;

        return new self(
            isset($row['id']) ? (int) $row['id'] : null,
            (int) $row['order_id'],
            isset($row['warehouse_id']) && $row['warehouse_id'] !== null ? (int) $row['warehouse_id'] : null,
            (string) ($row['type'] ?? self::TYPE_SHIPPED),
            $nullable($row['carrier'] ?? null),
            $nullable($row['tracking_number'] ?? null),
            $nullable($row['note'] ?? null),
            isset($row['user_id']) && $row['user_id'] !== null ? (int) $row['user_id'] : null,
            $nullable($row['created_at'] ?? null)
        );
    }

    public function tracking_url(): string
    {
        return Carrier::tracking_url($this->carrier, $this->tracking_number);
    }
}
