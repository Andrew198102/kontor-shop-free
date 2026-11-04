<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Order;

/**
 * Bestell-Entity (Abschnitt 5.3). Reines Datenobjekt - Persistenz macht
 * Infrastructure\Repositories\OrderRepository.
 */
final class Order
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $order_number,
        public readonly string $payment_reference,
        public readonly string $status,
        public readonly bool $is_member,
        public readonly string $customer_name,
        public readonly string $customer_email,
        public readonly ?string $delivery_address,
        public readonly bool $self_pickup,
        public readonly float $shipping_cost,
        public readonly float $items_total,
        public readonly float $total,
        public readonly string $payment_method,
        public readonly ?string $paid_at,
        public readonly ?int $paid_by_user_id,
        public readonly ?string $shipped_at,
        public readonly ?int $shipped_by_user_id,
        public readonly ?string $cancelled_at,
        public readonly ?string $created_at,
        public readonly ?string $legal_accepted_at = null,
        public readonly ?string $bank_account_id = null,
        public readonly ?string $checkout_token = null,
        /** @var array<int, array{tax_rate: float|null, line_total: float}> USt-Aufteilung der Versandkosten (brutto) */
        public readonly array $shipping_tax = [],
        public readonly ?string $customer_phone = null,
        public readonly ?string $billing_name = null,
        public readonly ?string $billing_address = null,
        public readonly ?string $billing_vat_id = null,
        /** Summe, die dem Kunden (nach Stornierungen bezahlter Ware) zurueckzuzahlen ist */
        public readonly float $refund_total = 0.0,
        /** davon bereits zurueckgezahlt */
        public readonly float $refunded_total = 0.0,
        /** Kanal der Zahlungsbuchung: null = Shop-Admin, sonst "api:<Zugang>" (Kassen-Schnittstelle) */
        public readonly ?string $paid_via = null,
        /** Name des Kassiers in der Gegenstelle (dort ist er kein WP-Benutzer) */
        public readonly ?string $paid_by_name = null,
        /** Wie bezahlt wurde (Domain\Order\PaidWith), null = nicht erfasst */
        public readonly ?string $paid_with = null,
        /** Letzte Aenderung der Bestellzeile (fuer den Abgleich der Kassen-Schnittstelle) */
        public readonly ?string $updated_at = null,
        /** Eingeloester Rabattcode (Snapshot), null = keiner */
        public readonly ?string $discount_code = null,
        /** Rabatt auf den Warenwert; items_total ist bereits um ihn reduziert */
        public readonly float $discount_total = 0.0,
    ) {
    }

    public function has_discount(): bool
    {
        return $this->discount_code !== null && $this->discount_total > 0;
    }

    /** Warenwert vor Rabatt (fuer die Anzeige "Warenwert - Rabatt"). */
    public function goods_total(): float
    {
        return round($this->items_total + $this->discount_total, 2);
    }

    /** Noch offene Rueckzahlung an den Kunden (0 = nichts offen). */
    public function refund_pending(): float
    {
        return max(0.0, round($this->refund_total - $this->refunded_total, 2));
    }

    public function is_invoice(): bool
    {
        return $this->payment_method === PaymentMethod::INVOICE;
    }

    /** Barzahlung bei der Abholung. */
    public function is_cash_on_pickup(): bool
    {
        return $this->payment_method === PaymentMethod::CASH;
    }

    /**
     * Ware geht vor der Zahlung raus bzw. wird zurueckgelegt: auf Rechnung
     * und Barzahlung bei Abholung.
     */
    public function pays_on_handover(): bool
    {
        return $this->is_invoice() || $this->is_cash_on_pickup();
    }

    /**
     * Gesamtsumme 0 € (z. B. Rabattcode deckt den Warenwert, Versand entfaellt
     * bei Abholung/Gratisversand): es gibt nichts zu bezahlen - die Bestellung
     * wird beim Bestellen als bezahlt gebucht und geht sofort ans Lager.
     */
    public function nothing_to_pay(): bool
    {
        return round($this->total, 2) <= 0.0;
    }

    /** Komplett versendet bzw. abgeholt. */
    public function is_dispatched(): bool
    {
        return in_array($this->status, [OrderStatus::SHIPPED, OrderStatus::PICKED_UP], true);
    }

    /**
     * Bezahlt? Bei Vorkasse ist alles ab "bezahlt" bezahlt; auf Rechnung geht
     * die Ware vorher raus - dort zaehlt nur der gebuchte Zahlungseingang.
     */
    public function is_paid(): bool
    {
        return $this->paid_at !== null
            || $this->status === OrderStatus::PAID
            || ($this->is_dispatched() && !$this->pays_on_handover());
    }

    /**
     * Darf (weiter) versendet/abgeholt werden? Vorkasse erst nach der
     * Zahlung, auf Rechnung sofort.
     */
    public function can_ship(): bool
    {
        return $this->status === OrderStatus::PAID || ($this->status === OrderStatus::OPEN && $this->pays_on_handover());
    }

    /**
     * Wartet auf Geld: offen, oder auf Rechnung schon raus und noch nicht bezahlt.
     */
    public function awaits_payment(): bool
    {
        return $this->status === OrderStatus::OPEN
            || ($this->pays_on_handover() && $this->is_dispatched() && $this->paid_at === null && $this->total > 0);
    }

    /** Kann ein Zahlungseingang gebucht werden? */
    public function can_mark_paid(): bool
    {
        return $this->awaits_payment();
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function from_row(array $row): self
    {
        return new self(
            isset($row['id']) ? (int) $row['id'] : null,
            (string) $row['order_number'],
            (string) $row['payment_reference'],
            (string) $row['status'],
            (bool) $row['is_member'],
            (string) $row['customer_name'],
            (string) $row['customer_email'],
            isset($row['delivery_address']) && $row['delivery_address'] !== null ? (string) $row['delivery_address'] : null,
            (bool) $row['self_pickup'],
            (float) $row['shipping_cost'],
            (float) $row['items_total'],
            (float) $row['total'],
            (string) $row['payment_method'],
            isset($row['paid_at']) && $row['paid_at'] !== null ? (string) $row['paid_at'] : null,
            isset($row['paid_by_user_id']) && $row['paid_by_user_id'] !== null ? (int) $row['paid_by_user_id'] : null,
            isset($row['shipped_at']) && $row['shipped_at'] !== null ? (string) $row['shipped_at'] : null,
            isset($row['shipped_by_user_id']) && $row['shipped_by_user_id'] !== null ? (int) $row['shipped_by_user_id'] : null,
            isset($row['cancelled_at']) && $row['cancelled_at'] !== null ? (string) $row['cancelled_at'] : null,
            isset($row['created_at']) && $row['created_at'] !== null ? (string) $row['created_at'] : null,
            isset($row['legal_accepted_at']) && $row['legal_accepted_at'] !== null ? (string) $row['legal_accepted_at'] : null,
            isset($row['bank_account_id']) && $row['bank_account_id'] !== null && $row['bank_account_id'] !== '' ? (string) $row['bank_account_id'] : null,
            isset($row['checkout_token']) && $row['checkout_token'] !== null && $row['checkout_token'] !== '' ? (string) $row['checkout_token'] : null,
            self::decode_shipping_tax($row['shipping_tax'] ?? null),
            self::nullable($row['customer_phone'] ?? null),
            self::nullable($row['billing_name'] ?? null),
            self::nullable($row['billing_address'] ?? null),
            self::nullable($row['billing_vat_id'] ?? null),
            (float) ($row['refund_total'] ?? 0),
            (float) ($row['refunded_total'] ?? 0),
            self::nullable($row['paid_via'] ?? null),
            self::nullable($row['paid_by_name'] ?? null),
            self::nullable($row['paid_with'] ?? null),
            self::nullable($row['updated_at'] ?? null),
            self::nullable($row['discount_code'] ?? null),
            (float) ($row['discount_total'] ?? 0)
        );
    }

    private static function nullable(mixed $value): ?string
    {
        return $value !== null && $value !== '' ? (string) $value : null;
    }

    /**
     * @return array<int, array{tax_rate: float|null, line_total: float}>
     */
    private static function decode_shipping_tax(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            return [];
        }

        $lines = [];

        foreach ($data as $line) {
            if (is_array($line) && isset($line['line_total'])) {
                $lines[] = [
                    'tax_rate'   => isset($line['tax_rate']) && $line['tax_rate'] !== null ? (float) $line['tax_rate'] : null,
                    'line_total' => (float) $line['line_total'],
                ];
            }
        }

        return $lines;
    }
}
