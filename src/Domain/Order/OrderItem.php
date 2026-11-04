<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Order;

/**
 * Bestellposition (Abschnitt 5.3) - haelt list_price (Normalpreis) und
 * unit_price (tatsaechlich verrechneter Preis) als Snapshot, unabhaengig
 * von spaeteren Preisaenderungen am Artikel (Abschnitt 6.2). Bei Varianten
 * enthaelt product_name die Variante gleich mit ("Pullover – M / Rot").
 *
 * Rabattcode: line_total bleibt unit_price x quantity (so steht es auf der
 * Bestellung), discount ist der Anteil des Rabatts, der auf diese Position
 * entfaellt (beim Bestellen: Filter kontor_shop/checkout/line_discounts). Bezahlt wird
 * paid_total(); Storno, Retoure und USt rechnen damit.
 */
final class OrderItem
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $order_id,
        public readonly int $product_id,
        public readonly int $warehouse_id,
        public readonly string $product_name,
        public readonly int $quantity,
        public readonly float $list_price,
        public readonly float $unit_price,
        public readonly float $line_total,
        public readonly ?float $tax_rate = null,
        /** Sendung, mit der die Position rausgegangen ist (null = noch offen) */
        public readonly ?int $shipment_id = null,
        /** Position storniert (Ware geht nicht raus) */
        public readonly ?string $cancelled_at = null,
        public readonly ?string $cancel_note = null,
        /** Variante (0 = Artikel ohne Varianten); die Bezeichnung steht auch im product_name-Snapshot */
        public readonly int $variant_id = 0,
        public readonly string $variant_label = '',
        /** Davon retourniert (Stueck, Domain\Order\ItemReturn) und wann zuletzt */
        public readonly int $returned_qty = 0,
        public readonly ?string $returned_at = null,
        /** Rabattanteil dieser Position (brutto, ganze Menge), 0 = kein Rabatt */
        public readonly float $discount = 0.0,
    ) {
    }

    /** Tatsaechlich berechneter Positionswert (nach Rabatt). */
    public function paid_total(): float
    {
        return round(max(0.0, $this->line_total - $this->discount), 2);
    }

    /**
     * Bezahlter Wert von $quantity Stueck, wenn davor schon $before Stueck
     * herausgerechnet wurden (Retoure in mehreren Schritten). Auf den Cent so
     * verteilt, dass alle Teile zusammen genau paid_total() ergeben.
     */
    public function paid_value_of(int $quantity, int $before = 0): float
    {
        return self::share_of($this->paid_total(), $this->quantity, $quantity, $before);
    }

    /** Rabattanteil von $quantity Stueck (Gegenstueck zu paid_value_of). */
    public function discount_of(int $quantity, int $before = 0): float
    {
        return self::share_of($this->discount, $this->quantity, $quantity, $before);
    }

    private static function share_of(float $total, int $of, int $quantity, int $before): float
    {
        if ($of <= 0 || $quantity <= 0) {
            return 0.0;
        }

        $upto  = min($of, $before + $quantity);
        $start = min($of, max(0, $before));

        return round(round($total * $upto / $of, 2) - round($total * $start / $of, 2), 2);
    }

    public function is_cancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function is_fully_returned(): bool
    {
        return $this->returned_qty > 0 && $this->returned_qty >= $this->quantity;
    }

    /** Anzeigename: der Snapshot-Name, Varianten-Bezeichnung nur, wenn sie dort noch fehlt. */
    public function display_name(): string
    {
        if ($this->variant_label === '' || str_contains($this->product_name, $this->variant_label)) {
            return $this->product_name;
        }

        return $this->product_name . ' – ' . $this->variant_label;
    }

    /** Bezahlter Positionswert ohne retournierte Stueck (fuer USt-Aufschluesselung). */
    public function effective_total(): float
    {
        return round($this->paid_total() - $this->paid_value_of($this->returned_qty), 2);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function from_row(array $row): self
    {
        return new self(
            isset($row['id']) ? (int) $row['id'] : null,
            (int) $row['order_id'],
            (int) $row['product_id'],
            (int) $row['warehouse_id'],
            (string) $row['product_name'],
            (int) $row['quantity'],
            (float) $row['list_price'],
            (float) $row['unit_price'],
            (float) $row['line_total'],
            isset($row['tax_rate']) && $row['tax_rate'] !== null && $row['tax_rate'] !== '' ? (float) $row['tax_rate'] : null,
            isset($row['shipment_id']) && $row['shipment_id'] !== null && $row['shipment_id'] !== '' ? (int) $row['shipment_id'] : null,
            isset($row['cancelled_at']) && $row['cancelled_at'] !== null && $row['cancelled_at'] !== '' ? (string) $row['cancelled_at'] : null,
            isset($row['cancel_note']) && $row['cancel_note'] !== null && $row['cancel_note'] !== '' ? (string) $row['cancel_note'] : null,
            (int) ($row['variant_id'] ?? 0),
            (string) ($row['variant_label'] ?? ''),
            (int) ($row['returned_qty'] ?? 0),
            isset($row['returned_at']) && $row['returned_at'] !== null && $row['returned_at'] !== '' ? (string) $row['returned_at'] : null,
            (float) ($row['discount'] ?? 0)
        );
    }
}
