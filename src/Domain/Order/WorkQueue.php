<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Order;

/**
 * Was ist bei einer Bestellung zu tun? Ordnet sie den Arbeits-Ansichten der
 * Bestellliste zu (Zahlung offen, zu versenden, Abholung, Rueckzahlung …)
 * und bestimmt den naechsten Schritt je Rolle. Reine Logik ohne WordPress
 * (docs/PLAN_bestellliste.md); die Statusregeln selbst bleiben in
 * OrderStatus/Fulfilment.
 */
final class WorkQueue
{
    public const VIEW_TODO      = 'todo';
    public const VIEW_PAY       = 'pay';
    public const VIEW_OVERDUE   = 'overdue';
    public const VIEW_SHIP      = 'ship';
    public const VIEW_PICKUP    = 'pickup';
    public const VIEW_PARTIAL   = 'partial';
    public const VIEW_REFUND    = 'refund';
    public const VIEW_DONE      = 'done';
    public const VIEW_CANCELLED = 'cancelled';
    public const VIEW_ALL       = 'all';

    public const STEP_PAY    = 'pay';
    public const STEP_REFUND = 'refund';
    public const STEP_SHIP   = 'ship';
    public const STEP_PICKUP = 'pickup';

    private Fulfilment $fulfilment;

    /**
     * @param array<int, OrderItem> $items
     * @param array<int, int>|null $warehouse_ids Lager des Benutzers (null = alle); bestimmt, was "fuer mich" zu versenden ist
     * @param string $today Y-m-d (Ortszeit des Shops)
     */
    public function __construct(
        public readonly Order $order,
        public readonly array $items,
        private readonly PaymentTerms $terms,
        private readonly string $today,
        private readonly ?array $warehouse_ids = null,
    ) {
        $this->fulfilment = new Fulfilment($order, $items);
    }

    /**
     * @return array<int, string>
     */
    public static function views(): array
    {
        return [self::VIEW_TODO, self::VIEW_PAY, self::VIEW_OVERDUE, self::VIEW_SHIP, self::VIEW_PICKUP, self::VIEW_PARTIAL,
            self::VIEW_REFUND, self::VIEW_DONE, self::VIEW_CANCELLED, self::VIEW_ALL];
    }

    public function fulfilment(): Fulfilment
    {
        return $this->fulfilment;
    }

    public function awaits_payment(): bool
    {
        return $this->order->awaits_payment();
    }

    public function is_overdue(): bool
    {
        return $this->terms->is_overdue($this->order, $this->today);
    }

    /** Letzter Zahlungstag (Y-m-d) oder null. */
    public function due_date(): ?string
    {
        return $this->terms->due_date($this->order);
    }

    /**
     * Zahlung ist jetzt Thema: Vorkasse offen (davon haengt der Versand ab),
     * Rechnung erst, wenn das Zahlungsziel abgelaufen ist.
     */
    public function payment_needs_action(): bool
    {
        // Bar bei Abholung: bezahlt wird bei der Uebergabe, vorher ist nichts zu tun.
        return $this->awaits_payment() && !$this->order->is_cash_on_pickup() && (!$this->order->is_invoice() || $this->is_overdue());
    }

    /** Versandbereit (bezahlt bzw. auf Rechnung), und fuer die Lager des Benutzers ist noch etwas offen. */
    public function to_ship(): bool
    {
        return $this->order->can_ship() && !$this->order->self_pickup && $this->fulfilment->open_items_for($this->warehouse_ids) !== [];
    }

    public function to_pickup(): bool
    {
        return $this->order->can_ship() && $this->order->self_pickup && $this->fulfilment->open_items_for($this->warehouse_ids) !== [];
    }

    /** Ein Teil ist raus, ein Teil noch offen (typisch bei zwei Lagern). */
    public function is_partial(): bool
    {
        return $this->order->can_ship() && $this->fulfilment->is_partial();
    }

    /** Mein Teil ist raus, die Bestellung wartet noch auf ein anderes Lager. */
    public function waits_for_other_warehouse(): bool
    {
        return $this->warehouse_ids !== null && $this->order->can_ship()
            && $this->fulfilment->open_items() !== [] && $this->fulfilment->open_items_for($this->warehouse_ids) === [];
    }

    public function refund_pending(): bool
    {
        return $this->order->refund_pending() > 0;
    }

    public function is_done(): bool
    {
        return in_array($this->order->status, [OrderStatus::SHIPPED, OrderStatus::PICKED_UP], true);
    }

    public function needs_action(): bool
    {
        return $this->payment_needs_action() || $this->to_ship() || $this->to_pickup() || $this->refund_pending();
    }

    public function matches(string $view): bool
    {
        return match ($view) {
            self::VIEW_TODO      => $this->needs_action(),
            self::VIEW_PAY       => $this->awaits_payment(),
            self::VIEW_OVERDUE   => $this->is_overdue(),
            self::VIEW_SHIP      => $this->to_ship(),
            self::VIEW_PICKUP    => $this->to_pickup(),
            self::VIEW_PARTIAL   => $this->is_partial(),
            self::VIEW_REFUND    => $this->refund_pending(),
            self::VIEW_DONE      => $this->is_done(),
            self::VIEW_CANCELLED => $this->order->status === OrderStatus::CANCELLED,
            default              => true,
        };
    }

    /** Sortierung "Zu erledigen": je kleiner, desto dringender. */
    public function urgency(): int
    {
        return match (true) {
            $this->is_overdue()                         => 0,
            $this->refund_pending()                     => 1,
            $this->to_ship() || $this->to_pickup()      => 2,
            $this->payment_needs_action()               => 3,
            default                                     => 4,
        };
    }

    /**
     * Hauptknopf der Zeile fuer diese Rolle (null = nichts zu tun).
     */
    public function next_step(bool $can_pay, bool $can_ship): ?string
    {
        // Auf Rechnung geht der Versand vor: bezahlt wird erst nach Erhalt.
        if ($this->order->pays_on_handover() && $can_ship && ($this->to_ship() || $this->to_pickup())) {
            return $this->to_pickup() ? self::STEP_PICKUP : self::STEP_SHIP;
        }

        // Rechnung vor dem Versand: Hauptknopf erst, wenn sie raus ist (bezahlen geht trotzdem in den Details).
        if ($can_pay && $this->awaits_payment() && (!$this->order->pays_on_handover() || $this->is_done())) {
            return self::STEP_PAY;
        }

        if ($can_pay && $this->refund_pending()) {
            return self::STEP_REFUND;
        }

        if ($can_ship && $this->to_pickup()) {
            return self::STEP_PICKUP;
        }

        if ($can_ship && $this->to_ship()) {
            return self::STEP_SHIP;
        }

        return null;
    }

    /**
     * Erledigte Stufen 1-3 (0 = storniert). Vorkasse: Bestellt → Bezahlt →
     * Raus; auf Rechnung: Bestellt → Raus → Bezahlt (steps()).
     */
    public function progress(): int
    {
        if ($this->order->status === OrderStatus::CANCELLED) {
            return 0;
        }

        if ($this->order->pays_on_handover()) {
            return match (true) {
                $this->is_done() && !$this->awaits_payment() => 3,
                $this->is_done()                             => 2,
                default                                      => 1,
            };
        }

        return match (true) {
            $this->is_done()                           => 3,
            $this->order->status === OrderStatus::PAID => 2,
            default                                    => 1,
        };
    }

    /**
     * Reihenfolge der Stufen 2 und 3.
     *
     * @return array{0: string, 1: string} je 'pay' oder 'out'
     */
    public function steps(): array
    {
        return $this->order->pays_on_handover() ? ['out', 'pay'] : ['pay', 'out'];
    }

    /**
     * Versandstand je Lager: warehouse_id => true (raus) / false (offen).
     *
     * @return array<int, bool>
     */
    public function warehouse_state(): array
    {
        $state = [];

        foreach ($this->fulfilment->active_items() as $item) {
            $state[$item->warehouse_id] = ($state[$item->warehouse_id] ?? true) && $this->fulfilment->is_shipped($item);
        }

        ksort($state);

        return $state;
    }
}
