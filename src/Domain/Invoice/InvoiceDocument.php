<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Invoice;

use Kontor\Shop\Domain\Order\Order;
use Kontor\Shop\Domain\Order\OrderItem;
use Kontor\Shop\Domain\Order\PaymentMethod;
use Kontor\Shop\Domain\Tax\TaxCalculator;

/**
 * Inhalt einer Rechnung bzw. Gutschrift als Schnappschuss (wird so in der
 * Datenbank gespeichert; das PDF entsteht daraus). Reine Logik.
 *
 * Betraege sind brutto wie im Shop; die USt steht je Satz aufgeschluesselt
 * (aus der Summe je Satz gerundet, wie in der Kasse). Eine Gutschrift hat
 * negative Betraege und verweist auf die Rechnung.
 *
 * Schema: type, number, issued_at, service_date, refers_to_number,
 * seller{name,address,vat_id,extra}, buyer{name,address,vat_id,email},
 * order{number,date,payment_reference,payment_method}, lines[{qty,name,unit,total,rate}],
 * discount{label,amount}|null, shipping{amount}|null,
 * totals{gross,net,vat[{rate,net,vat,gross}]}, exempt_note, paid_at, due_date, note
 */
final class InvoiceDocument
{
    public const TYPE_INVOICE     = 'invoice';
    public const TYPE_CREDIT_NOTE = 'credit_note';

    /**
     * Rechnung ueber die (nicht stornierten) Positionen einer Bestellung.
     *
     * @param array<int, OrderItem>                                         $items
     * @param array{name: string, address: string, vat_id: string, extra: string} $seller
     * @return array<string, mixed>
     */
    public static function invoice(Order $order, array $items, array $seller, string $number, string $issued_at, string $exempt_note, ?string $due_date = null): array
    {
        $active = array_values(array_filter($items, static fn (OrderItem $item): bool => !$item->is_cancelled()));
        $lines  = [];
        $tax    = [];
        $discount = 0.0;

        foreach ($active as $item) {
            $lines[] = [
                'qty'   => $item->quantity,
                'name'  => $item->product_name,
                'unit'  => round($item->unit_price, 2),
                'total' => round($item->line_total, 2),
                'rate'  => $item->tax_rate,
            ];
            $discount += $item->discount;
            $tax[]     = ['tax_rate' => $item->tax_rate, 'line_total' => $item->paid_total()];
        }

        foreach ($order->shipping_tax as $part) {
            $tax[] = ['tax_rate' => $part['tax_rate'], 'line_total' => (float) $part['line_total']];
        }

        if ($order->shipping_cost > 0 && $order->shipping_tax === []) {
            $tax[] = ['tax_rate' => null, 'line_total' => $order->shipping_cost];
        }

        $gross = round(array_sum(array_column($tax, 'line_total')), 2);

        return self::finish([
            'type'             => self::TYPE_INVOICE,
            'number'           => $number,
            'issued_at'        => $issued_at,
            'service_date'     => substr((string) ($order->shipped_at ?? $order->paid_at ?? $issued_at), 0, 10),
            'refers_to_number' => '',
            'seller'           => $seller,
            'buyer'            => self::buyer($order),
            'order'            => self::order_info($order),
            'lines'            => $lines,
            'discount'         => $discount > 0 ? ['label' => (string) ($order->discount_code ?? ''), 'amount' => -round($discount, 2)] : null,
            'shipping'         => $order->shipping_cost > 0 ? ['amount' => round($order->shipping_cost, 2)] : null,
            'exempt_note'      => self::has_untaxed($tax) ? $exempt_note : '',
            'paid_at'          => $order->paid_at !== null ? substr($order->paid_at, 0, 10) : '',
            'due_date'         => $due_date ?? '',
            'note'             => '',
        ], $tax, $gross);
    }

    /**
     * Gutschrift: ganze Rechnung (alles storniert, $lines = null) oder nur
     * einzelne Positionen/Stueck (Teilstorno, Retoure).
     *
     * @param array<string, mixed>                                $invoice Schnappschuss der Rechnung
     * @param array<int, array{item: OrderItem, quantity: int}>|null $lines
     * @return array<string, mixed>
     */
    public static function credit_note(array $invoice, ?array $lines, string $number, string $issued_at, string $note = ''): array
    {
        if ($lines === null) {
            $data = $invoice;
            $data['lines'] = array_map(static fn (array $line): array => ['total' => -$line['total']] + $line, (array) $invoice['lines']);
            $data['discount'] = is_array($invoice['discount'] ?? null) ? ['amount' => -$invoice['discount']['amount']] + $invoice['discount'] : null;
            $data['shipping'] = is_array($invoice['shipping'] ?? null) ? ['amount' => -$invoice['shipping']['amount']] : null;
            $tax = array_map(static fn (array $vat): array => ['tax_rate' => $vat['rate'], 'line_total' => -$vat['gross']], (array) $invoice['totals']['vat']);
            $untaxed = round((float) $invoice['totals']['gross'] - array_sum(array_column((array) $invoice['totals']['vat'], 'gross')), 2);

            if ($untaxed != 0.0) {
                $tax[] = ['tax_rate' => null, 'line_total' => -$untaxed];
            }

            $gross = -round((float) $invoice['totals']['gross'], 2);
        } else {
            $data  = $invoice;
            $tax   = [];
            $out   = [];

            foreach ($lines as $line) {
                $item  = $line['item'];
                $qty   = max(0, min($item->quantity, $line['quantity']));
                $share = $item->quantity > 0 ? $qty / $item->quantity : 0.0;
                $paid  = round($item->paid_total() * $share, 2);

                $out[] = ['qty' => $qty, 'name' => $item->product_name, 'unit' => round($item->unit_price, 2), 'total' => -$paid, 'rate' => $item->tax_rate];
                $tax[] = ['tax_rate' => $item->tax_rate, 'line_total' => -$paid];
            }

            $data['lines']    = $out;
            $data['discount'] = null; // Positionen stehen schon mit ihrem bezahlten Betrag (nach Rabatt) drin
            $data['shipping'] = null;
            $gross = round(array_sum(array_column($tax, 'line_total')), 2);
        }

        $data['type']             = self::TYPE_CREDIT_NOTE;
        $data['number']           = $number;
        $data['issued_at']        = $issued_at;
        $data['refers_to_number'] = (string) $invoice['number'];
        $data['paid_at']          = '';
        $data['due_date']         = '';
        $data['note']             = $note;

        return self::finish($data, $tax, $gross);
    }

    /**
     * @param array<string, mixed>                                          $data
     * @param array<int, array{tax_rate: float|null, line_total: float}> $tax
     * @return array<string, mixed>
     */
    private static function finish(array $data, array $tax, float $gross): array
    {
        $vat = [];

        // breakdown() rechnet mit positiven Betraegen; Vorzeichen getrennt behandeln.
        $sign = $gross < 0 ? -1 : 1;

        foreach (TaxCalculator::breakdown(array_map(static fn (array $line): array => ['tax_rate' => $line['tax_rate'], 'line_total' => abs($line['line_total'])], $tax)) as $group) {
            $vat[] = [
                'rate'  => $group['rate'],
                'gross' => $sign * $group['gross'],
                'vat'   => $sign * $group['vat'],
                'net'   => $sign * round($group['gross'] - $group['vat'], 2),
            ];
        }

        $vat_sum = round(array_sum(array_column($vat, 'vat')), 2);

        $data['totals'] = [
            'gross' => round($gross, 2),
            'vat'   => $vat,
            'net'   => round($gross - $vat_sum, 2),
        ];

        return $data;
    }

    /**
     * @param array<int, array{tax_rate: float|null, line_total: float}> $tax
     */
    private static function has_untaxed(array $tax): bool
    {
        foreach ($tax as $line) {
            if (($line['tax_rate'] === null || $line['tax_rate'] <= 0) && abs($line['line_total']) > 0.0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{name: string, address: string, vat_id: string, email: string}
     */
    private static function buyer(Order $order): array
    {
        $billing = trim((string) ($order->billing_address ?? ''));

        return [
            'name'    => trim((string) ($order->billing_name ?? '')) !== '' ? trim((string) $order->billing_name) : $order->customer_name,
            'address' => $billing !== '' ? $billing : ($order->self_pickup ? '' : self::strip_name((string) $order->delivery_address, $order->customer_name)),
            'vat_id'  => (string) ($order->billing_vat_id ?? ''),
            'email'   => $order->customer_email,
        ];
    }

    /**
     * Lieferadresse beginnt mit dem Namen - auf der Rechnung steht er schon darueber.
     */
    private static function strip_name(string $address, string $name): string
    {
        $lines = preg_split('/\R/', trim($address)) ?: [];

        if ($lines !== [] && trim($lines[0]) === trim($name)) {
            array_shift($lines);
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{number: string, date: string, payment_reference: string, payment_method: string}
     */
    private static function order_info(Order $order): array
    {
        return [
            'number'            => $order->order_number,
            'date'              => substr((string) $order->created_at, 0, 10),
            'payment_reference' => $order->payment_reference,
            'payment_method'    => PaymentMethod::label($order->payment_method),
        ];
    }
}
