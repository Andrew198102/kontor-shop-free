<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Order;

/**
 * Versandstand einer Bestellung auf Positionsebene: welche Positionen sind
 * schon raus, welche noch offen, ist die Bestellung (teil-)versendet? Reine
 * Logik ohne WordPress. Bestellungen, die vor den Einzelsendungen komplett
 * als versendet/abgeholt markiert wurden, gelten als vollstaendig raus.
 */
final class Fulfilment
{
    /**
     * @param array<int, OrderItem> $items
     */
    public function __construct(private readonly Order $order, private readonly array $items)
    {
    }

    public function is_shipped(OrderItem $item): bool
    {
        return $item->shipment_id !== null
            || in_array($this->order->status, [OrderStatus::SHIPPED, OrderStatus::PICKED_UP], true);
    }

    /**
     * Nicht stornierte Positionen.
     *
     * @return array<int, OrderItem>
     */
    public function active_items(): array
    {
        return array_values(array_filter($this->items, static fn (OrderItem $item): bool => !$item->is_cancelled()));
    }

    /**
     * Offen = weder versendet noch storniert.
     *
     * @return array<int, OrderItem>
     */
    public function open_items(): array
    {
        return array_values(array_filter($this->active_items(), fn (OrderItem $item): bool => !$this->is_shipped($item)));
    }

    /**
     * Stornierbar = offen (was schon beim Kunden ist, laeuft ueber Retoure).
     *
     * @return array<int, OrderItem>
     */
    public function cancellable_items(): array
    {
        return $this->open_items();
    }

    /** Ist schon (mindestens) ein Artikel beim Kunden? */
    public function has_shipped_items(): bool
    {
        foreach ($this->active_items() as $item) {
            if ($this->is_shipped($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ganze Bestellung stornieren? Nur, solange noch nichts versendet/
     * abgeholt ist - danach geht nur noch Retoure (offene Reste lassen sich
     * weiterhin einzeln stornieren).
     */
    public function can_cancel_order(): bool
    {
        return OrderStatus::can_transition($this->order->status, OrderStatus::CANCELLED) && !$this->has_shipped_items();
    }

    public function is_complete(): bool
    {
        return $this->active_items() !== [] && $this->open_items() === [];
    }

    /** Alle Positionen storniert - die Bestellung ist damit insgesamt storniert. */
    public function is_fully_cancelled(): bool
    {
        return $this->items !== [] && $this->active_items() === [];
    }

    public function is_partial(): bool
    {
        $open = count($this->open_items());

        return $open > 0 && $open < count($this->active_items());
    }

    /**
     * Offene Positionen je Lager (warehouse_id => Positionen).
     *
     * @return array<int, array<int, OrderItem>>
     */
    public function open_by_warehouse(): array
    {
        $groups = [];

        foreach ($this->open_items() as $item) {
            $groups[$item->warehouse_id][] = $item;
        }

        ksort($groups);

        return $groups;
    }

    /**
     * Offene Positionen, die dieser Benutzer versenden darf (null = alle Lager).
     *
     * @param array<int, int>|null $warehouse_ids
     * @return array<int, OrderItem>
     */
    public function open_items_for(?array $warehouse_ids): array
    {
        return array_values(array_filter(
            $this->open_items(),
            static fn (OrderItem $item): bool => $warehouse_ids === null || in_array($item->warehouse_id, $warehouse_ids, true)
        ));
    }
}
