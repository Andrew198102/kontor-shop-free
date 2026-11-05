<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Invoices;

/**
 * Rechnungen und Gutschriften (Tabelle invoices). Die Nummer ist je Serie
 * fortlaufend und lueckenlos: seq = hoechste Nummer der Serie + 1, ein
 * eindeutiger Index (series, seq) verhindert Doppelvergabe; bei einer
 * Kollision (zwei gleichzeitige Rechnungen) wird neu gezaehlt. Rechnungen
 * werden nie geaendert oder geloescht (Aufbewahrungspflicht) - Korrekturen
 * laufen ueber Gutschriften.
 */
final class InvoiceRepository
{
    private function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kontor_shop_invoices';
    }

    /**
     * Legt die Rechnung an und vergibt die naechste Nummer der Serie.
     *
     * @param callable(int): string                $number_for seq -> Rechnungsnummer
     * @param callable(string): array<string, mixed> $data_for   Nummer -> Schnappschuss
     * @return array{id: int, number: string, data: array<string, mixed>}|null
     */
    public function create(int $order_id, string $type, string $series, callable $number_for, callable $data_for, ?int $refers_to, ?int $user_id): ?array
    {
        global $wpdb;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $seq    = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(seq), 0) FROM %i WHERE series = %s', $this->table(), $series)) + 1;
            $number = $number_for($seq);
            $data   = $data_for($number);

            $suppress = $wpdb->suppress_errors(true);
            $ok       = $wpdb->insert($this->table(), [
                'order_id'   => $order_id,
                'type'       => $type,
                'series'     => $series,
                'seq'        => $seq,
                'number'     => $number,
                'issued_at'  => current_time('mysql'),
                'total'      => number_format((float) ($data['totals']['gross'] ?? 0), 2, '.', ''),
                'refers_to'  => $refers_to,
                'data'       => (string) wp_json_encode($data),
                'created_by' => $user_id,
            ]);
            $wpdb->suppress_errors($suppress);

            if ($ok) {
                return ['id' => (int) $wpdb->insert_id, 'number' => $number, 'data' => $data];
            }
        }

        return null;
    }

    public function set_file(int $id, string $file): void
    {
        global $wpdb;

        $wpdb->update($this->table(), ['file' => $file], ['id' => $id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $this->table(), $id), ARRAY_A);

        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * Alle Belege einer Bestellung, aelteste zuerst.
     *
     * @return array<int, array<string, mixed>>
     */
    public function for_order(int $order_id): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE order_id = %d ORDER BY id ASC', $this->table(), $order_id), ARRAY_A);

        return array_map([self::class, 'hydrate'], is_array($rows) ? $rows : []);
    }

    /**
     * Die (erste) Rechnung einer Bestellung oder null.
     *
     * @return array<string, mixed>|null
     */
    public function invoice_for_order(int $order_id): ?array
    {
        foreach ($this->for_order($order_id) as $row) {
            if ($row['type'] === 'invoice') {
                return $row;
            }
        }

        return null;
    }

    /**
     * Ist die Rechnung schon vollstaendig storniert (Summe aller Belege 0)?
     */
    public function is_settled(int $order_id): bool
    {
        $rows = $this->for_order($order_id);

        return $rows !== [] && abs(array_sum(array_map(static fn (array $row): float => (float) $row['total'], $rows))) < 0.005;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function hydrate(array $row): array
    {
        $data = json_decode((string) ($row['data'] ?? ''), true);

        return [
            'id'        => (int) $row['id'],
            'order_id'  => (int) $row['order_id'],
            'type'      => (string) $row['type'],
            'number'    => (string) $row['number'],
            'issued_at' => (string) $row['issued_at'],
            'total'     => (float) $row['total'],
            'refers_to' => isset($row['refers_to']) && $row['refers_to'] !== null ? (int) $row['refers_to'] : null,
            'data'      => is_array($data) ? $data : [],
            'file'      => (string) ($row['file'] ?? ''),
        ];
    }
}
