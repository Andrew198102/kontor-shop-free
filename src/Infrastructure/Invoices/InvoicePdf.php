<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Invoices;

use Kontor\Shop\Domain\Invoice\InvoiceDocument;
use Kontor\Shop\Domain\Payment\BankAccount;
use Kontor\Shop\Domain\Payment\EpcQr;

/**
 * Macht aus dem Schnappschuss (Domain\Invoice\InvoiceDocument) ein A4-PDF
 * mit Dompdf (mitgeliefert unter vendor/dompdf-bundle, LGPL). Nur die
 * eingebauten PDF-Schriften (Helvetica) - sie decken deutsche Umlaute, ß
 * und das Euro-Zeichen ab, ohne Schriftdateien mitzuliefern. Keine
 * externen Ressourcen (isRemoteEnabled aus): das Logo kommt als data-URI.
 */
final class InvoicePdf
{
    public static function available(): bool
    {
        if (!class_exists(\Dompdf\Dompdf::class)) {
            $autoload = KONTOR_SHOP_PATH . 'vendor/dompdf-bundle/autoload.inc.php';

            if (is_readable($autoload)) {
                require_once $autoload;
            }
        }

        return class_exists(\Dompdf\Dompdf::class);
    }

    /**
     * @param array<string, mixed> $data   Schnappschuss
     * @param array{logo?: string, footer?: string, powered_by?: string} $extras logo = data-URI, footer/powered_by = Klartext
     */
    public static function render(array $data, array $extras = []): string
    {
        if (!self::available()) {
            return '';
        }

        $tmp     = get_temp_dir();
        $options = new \Dompdf\Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setDefaultFont('Helvetica');
        $options->setTempDir($tmp);
        $options->setFontCache($tmp);
        $options->setChroot([KONTOR_SHOP_PATH, $tmp]);

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml(self::html($data, $extras), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * @param array<string, mixed> $data
     * @param array{logo?: string, footer?: string, powered_by?: string} $extras
     */
    public static function html(array $data, array $extras = []): string
    {
        $e      = static fn (mixed $text): string => esc_html((string) $text);
        $br     = static fn (mixed $text): string => nl2br(esc_html(trim((string) $text)));
        $money  = static fn (mixed $amount): string => esc_html(number_format_i18n((float) $amount, 2) . ' €');
        $date   = static fn (mixed $ymd): string => (string) $ymd !== '' ? esc_html(mysql2date('d.m.Y', (string) $ymd . ' 00:00:00')) : '';
        $rate   = static fn (mixed $value): string => $value === null || (float) $value <= 0 ? '–' : esc_html(rtrim(rtrim(number_format_i18n((float) $value, 2), '0'), ',.') . ' %');
        $seller = (array) ($data['seller'] ?? []);
        $buyer  = (array) ($data['buyer'] ?? []);
        $order  = (array) ($data['order'] ?? []);
        $credit = ($data['type'] ?? '') === InvoiceDocument::TYPE_CREDIT_NOTE;

        $title = $credit ? __('Credit note', 'kontor-shop') : __('Invoice', 'kontor-shop');

        $meta = [
            [$credit ? __('Credit note no.', 'kontor-shop') : __('Invoice no.', 'kontor-shop'), $e($data['number'] ?? '')],
            [__('Date', 'kontor-shop'), $date($data['issued_at'] ?? '')],
        ];

        if ($credit) {
            $meta[] = [__('For invoice no.', 'kontor-shop'), $e($data['refers_to_number'] ?? '')];
        } else {
            $meta[] = [__('Delivery date', 'kontor-shop'), $date($data['service_date'] ?? '')];
        }

        $meta[] = [__('Order', 'kontor-shop'), $e(($order['number'] ?? '') . ' · ' . $date($order['date'] ?? ''))];

        if (($buyer['vat_id'] ?? '') !== '') {
            $meta[] = [__('Customer VAT ID', 'kontor-shop'), $e($buyer['vat_id'])];
        }

        $html  = '<!doctype html><html><head><meta charset="utf-8"><style>' . self::css() . '</style></head><body>';
        $html .= '<div class="footer">' . self::footer_html($seller, $extras) . '</div>';

        // Kopf: Absender, Logo
        $html .= '<table class="head"><tr><td class="seller"><strong>' . $e($seller['name'] ?? '') . '</strong><br>' . $br($seller['address'] ?? '')
            . (($seller['vat_id'] ?? '') !== '' ? '<br>' . $e(__('VAT ID', 'kontor-shop') . ': ' . $seller['vat_id']) : '')
            . (($seller['extra'] ?? '') !== '' ? '<br>' . $br($seller['extra']) : '') . '</td>';
        $html .= '<td class="logo">' . (($extras['logo'] ?? '') !== '' ? '<img src="' . esc_attr((string) $extras['logo']) . '" alt="">' : '') . '</td></tr></table>';

        // Empfaenger (Fensterbereich) und Belegdaten
        $html .= '<table class="address"><tr><td class="buyer"><div class="sender-line">' . $e(($seller['name'] ?? '') . ' · ' . str_replace("\n", ', ', trim((string) ($seller['address'] ?? '')))) . '</div>'
            . '<strong>' . $e($buyer['name'] ?? '') . '</strong>' . (($buyer['address'] ?? '') !== '' ? '<br>' . $br($buyer['address']) : '') . '</td>';
        $html .= '<td class="meta"><table>';

        foreach ($meta as [$label, $value]) {
            $html .= '<tr><th>' . $e($label) . '</th><td>' . $value . '</td></tr>';
        }

        $html .= '</table></td></tr></table>';

        $html .= '<h1>' . $e($title) . ' ' . $e($data['number'] ?? '') . '</h1>';

        if (($data['note'] ?? '') !== '') {
            $html .= '<p>' . $br($data['note']) . '</p>';
        }

        // Positionen
        $html .= '<table class="lines"><thead><tr><th class="qty">' . $e(__('Qty', 'kontor-shop')) . '</th><th>' . $e(__('Description', 'kontor-shop')) . '</th>'
            . '<th class="num">' . $e(__('Unit price', 'kontor-shop')) . '</th><th class="num">' . $e(__('VAT', 'kontor-shop')) . '</th><th class="num">' . $e(__('Amount', 'kontor-shop')) . '</th></tr></thead><tbody>';

        foreach ((array) ($data['lines'] ?? []) as $line) {
            $html .= '<tr><td class="qty">' . $e($line['qty'] ?? '') . '</td><td>' . $e($line['name'] ?? '') . '</td><td class="num">' . $money($line['unit'] ?? 0)
                . '</td><td class="num">' . $rate($line['rate'] ?? null) . '</td><td class="num">' . $money($line['total'] ?? 0) . '</td></tr>';
        }

        if (is_array($data['discount'] ?? null)) {
            $label = (string) ($data['discount']['label'] ?? '') !== ''
                /* translators: %s: discount code */
                ? sprintf(__('Discount (%s)', 'kontor-shop'), $data['discount']['label'])
                : __('Discount', 'kontor-shop');
            $html .= '<tr><td></td><td>' . $e($label) . '</td><td></td><td></td><td class="num">' . $money($data['discount']['amount'] ?? 0) . '</td></tr>';
        }

        if (is_array($data['shipping'] ?? null)) {
            $html .= '<tr><td></td><td>' . $e(__('Shipping', 'kontor-shop')) . '</td><td></td><td></td><td class="num">' . $money($data['shipping']['amount'] ?? 0) . '</td></tr>';
        }

        $html .= '</tbody></table>';

        // Summen
        $totals = (array) ($data['totals'] ?? []);
        $html  .= '<table class="totals"><tr><th>' . $e(__('Net amount', 'kontor-shop')) . '</th><td>' . $money($totals['net'] ?? 0) . '</td></tr>';

        foreach ((array) ($totals['vat'] ?? []) as $vat) {
            $html .= '<tr><th>' . $e(sprintf(
                /* translators: 1: VAT rate, 2: net amount */
                __('VAT %1$s on %2$s', 'kontor-shop'),
                wp_strip_all_tags($rate($vat['rate'] ?? 0)),
                wp_strip_all_tags($money($vat['net'] ?? 0))
            )) . '</th><td>' . $money($vat['vat'] ?? 0) . '</td></tr>';
        }

        $html .= '<tr class="grand"><th>' . $e($credit ? __('Total credit', 'kontor-shop') : __('Total', 'kontor-shop')) . '</th><td>' . $money($totals['gross'] ?? 0) . '</td></tr></table>';

        if (($data['exempt_note'] ?? '') !== '') {
            $html .= '<p class="small">' . $br($data['exempt_note']) . '</p>';
        }

        $html .= self::payment_html($data);
        $html .= '</body></html>';

        return $html;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function payment_html(array $data): string
    {
        $e = static fn (mixed $text): string => esc_html((string) $text);

        if (($data['type'] ?? '') === InvoiceDocument::TYPE_CREDIT_NOTE) {
            return '<p>' . $e(__('The amount will be refunded to you or set off against open amounts.', 'kontor-shop')) . '</p>';
        }

        if (($data['paid_at'] ?? '') !== '') {
            return '<p class="paid">' . $e(sprintf(
                /* translators: 1: date, 2: payment method */
                __('Paid on %1$s (%2$s). Thank you!', 'kontor-shop'),
                mysql2date('d.m.Y', (string) $data['paid_at'] . ' 00:00:00'),
                (string) ($data['order']['payment_method'] ?? '')
            )) . '</p>';
        }

        $bank = (array) ($data['bank'] ?? []);

        if (($bank['iban'] ?? '') === '') {
            return '';
        }

        $amount = (float) ($data['totals']['gross'] ?? 0);
        $html   = '<table class="pay"><tr><td><strong>' . $e(($data['due_date'] ?? '') !== ''
            ? sprintf(
                /* translators: %s: date */
                __('Please transfer the amount by %s to:', 'kontor-shop'),
                mysql2date('d.m.Y', (string) $data['due_date'] . ' 00:00:00')
            )
            : __('Please transfer the amount to:', 'kontor-shop')) . '</strong><br>'
            . $e($bank['recipient'] ?? '') . '<br>IBAN: ' . $e($bank['iban'] ?? '') . (($bank['bic'] ?? '') !== '' ? '<br>BIC: ' . $e($bank['bic']) : '')
            . '<br>' . $e(__('Payment reference', 'kontor-shop') . ': ' . ($data['order']['payment_reference'] ?? '')) . '</td>';

        $svg = EpcQr::svg(BankAccount::from_array(['id' => '', 'label' => '', 'recipient' => (string) ($bank['recipient'] ?? ''), 'iban' => (string) ($bank['iban'] ?? ''), 'bic' => (string) ($bank['bic'] ?? '')]), $amount, (string) ($data['order']['payment_reference'] ?? ''));

        if ($svg !== '') {
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- SVG als data-URI fuer Dompdf (keine externen Ressourcen)
            $html .= '<td class="qr"><img src="data:image/svg+xml;base64,' . base64_encode($svg) . '" alt=""><br><span class="small">' . $e(__('Scan with your banking app', 'kontor-shop')) . '</span></td>';
        }

        return $html . '</tr></table>';
    }

    /**
     * @param array<string, mixed>                                      $seller
     * @param array{logo?: string, footer?: string, powered_by?: string} $extras
     */
    private static function footer_html(array $seller, array $extras): string
    {
        $parts = array_filter([
            (string) ($seller['name'] ?? ''),
            str_replace("\n", ', ', trim((string) ($seller['address'] ?? ''))),
            ($seller['vat_id'] ?? '') !== '' ? __('VAT ID', 'kontor-shop') . ' ' . $seller['vat_id'] : '',
        ]);
        $html = esc_html(implode(' · ', $parts));

        if (($extras['footer'] ?? '') !== '') {
            $html .= '<br>' . nl2br(esc_html((string) $extras['footer']));
        }

        if (($extras['powered_by'] ?? '') !== '') {
            $html .= '<br><span class="powered">' . esc_html((string) $extras['powered_by']) . '</span>';
        }

        return $html;
    }

    private static function css(): string
    {
        return '@page{margin:18mm 18mm 28mm 20mm}'
            . 'body{font-family:Helvetica,sans-serif;font-size:9.5pt;color:#1d2327;line-height:1.35}'
            . 'table{border-collapse:collapse;width:100%}'
            . '.head td{vertical-align:top}.head .logo{text-align:right}.head .logo img{max-height:22mm;max-width:60mm}'
            . '.address{margin-top:14mm}.address td{vertical-align:top}.buyer{width:55%;height:40mm}'
            . '.sender-line{font-size:7pt;color:#646970;border-bottom:.3pt solid #c3c4c7;margin-bottom:3mm;padding-bottom:1mm}'
            . '.meta table th{text-align:left;font-weight:normal;color:#646970;padding:0 4mm 1mm 0;white-space:nowrap}.meta table td{padding:0 0 1mm}'
            . 'h1{font-size:15pt;margin:8mm 0 4mm}'
            . '.lines th{border-bottom:.8pt solid #1d2327;text-align:left;padding:1.5mm 1.5mm;font-size:8.5pt}'
            . '.lines td{border-bottom:.3pt solid #dcdcde;padding:1.5mm;vertical-align:top}'
            . '.qty{width:12mm}.num{text-align:right;white-space:nowrap}'
            . '.totals{width:62%;margin:4mm 0 0 38%}.totals th{text-align:left;font-weight:normal;padding:1mm 1.5mm}.totals td{text-align:right;padding:1mm 1.5mm;white-space:nowrap}'
            . '.totals .grand th,.totals .grand td{font-weight:bold;font-size:11pt;border-top:.8pt solid #1d2327;padding-top:2mm}'
            . '.small{font-size:8pt;color:#50575e}.paid{margin-top:6mm;font-weight:bold}'
            . '.pay{margin-top:7mm;border:.5pt solid #c3c4c7}.pay td{padding:3mm;vertical-align:top}.pay .qr{width:42mm;text-align:center}.pay .qr img{width:30mm;height:30mm}'
            . '.footer{position:fixed;bottom:-20mm;left:0;right:0;font-size:7.5pt;color:#646970;text-align:center;border-top:.3pt solid #c3c4c7;padding-top:2mm}'
            . '.powered{color:#8c8f94}';
    }
}
