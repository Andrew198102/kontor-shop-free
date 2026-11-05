<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Invoices;

use Kontor\Shop\Core\Capabilities;
use Kontor\Shop\Core\Registerable;
use Kontor\Shop\Domain\Invoice\InvoiceDocument;
use Kontor\Shop\Domain\Order\Order;
use Kontor\Shop\Domain\Order\OrderItem;
use Kontor\Shop\Domain\Order\OrderLink;
use Kontor\Shop\Domain\Order\OrderStatus;
use Kontor\Shop\Infrastructure\Invoices\InvoicePdf;
use Kontor\Shop\Infrastructure\Invoices\InvoiceRepository;
use Kontor\Shop\Infrastructure\Invoices\InvoiceStorage;
use Kontor\Shop\Infrastructure\Logging\Logger;
use Kontor\Shop\Infrastructure\Payment\BankAccounts;
use Kontor\Shop\Infrastructure\Repositories\OrderRepository;
use Kontor\Shop\Modules\Checkout\OrderStatusLink;
use Kontor\Shop\Modules\Notifications\NotificationsModule;
use Kontor\Shop\Modules\Notifications\PoweredBy;
use Kontor\Shop\Modules\Payments\PaymentOptions;
use Kontor\Shop\Settings\Repository;
use Kontor\Shop\Settings\TextDefaults;

/**
 * PDF-Rechnungen und Gutschriften (Einstellungen -> Rechnungen).
 *
 * - Ausgestellt wird je nach Einstellung beim Zahlungseingang, beim Versand
 *   oder nur per Knopf. Auf Rechnung und bar bei Abholung (bezahlt wird nach
 *   der Uebergabe) immer erst mit dem Versand bzw. der Abholung.
 * - Storno und Retoure einer schon berechneten Bestellung erzeugen eine
 *   Gutschrift; die Rechnung selbst bleibt unveraendert (Aufbewahrung).
 * - Der Kunde bekommt das PDF als Anhang (Mail "Rechnung") und als Download
 *   auf seiner Bestellseite; im Backend steht es bei der Bestellung.
 * - Nummer: je Kalenderjahr fortlaufend "2026-0001". Pro (oder ein Add-on)
 *   aendert Format, Logo und Fusszeile ueber kontor_shop/invoice/series,
 *   kontor_shop/invoice/number und kontor_shop/invoice/extras.
 */
final class InvoicesModule implements Registerable
{
    public const TRIGGERS        = ['paid', 'shipped', 'manual'];
    public const DOWNLOAD_ACTION = 'kontor_shop_invoice';
    public const CREATE_ACTION   = 'kontor_shop_invoice_create';

    private InvoiceRepository $invoices;
    private OrderRepository $orders;

    public function __construct(private readonly Repository $settings)
    {
        $this->invoices = new InvoiceRepository();
        $this->orders   = new OrderRepository();
    }

    public function register(): void
    {
        add_action(NotificationsModule::HOOK_ORDER_PAID, [$this, 'on_paid'], 20);
        add_action(NotificationsModule::HOOK_ORDER_SHIPPED, [$this, 'on_shipped'], 20, 2);
        add_action(NotificationsModule::HOOK_ORDER_CANCELLATION, [$this, 'on_cancellation'], 20, 6);
        add_action(NotificationsModule::HOOK_ORDER_RETURN, [$this, 'on_return'], 20, 5);
        add_action('admin_post_' . self::DOWNLOAD_ACTION, [$this, 'admin_download']);
        add_action('admin_post_' . self::CREATE_ACTION, [$this, 'admin_create']);
        add_action('admin_post_' . \Kontor\Shop\Admin\Settings\InvoicesSection::PREVIEW_ACTION, [$this, 'admin_preview']);
        add_action('template_redirect', [$this, 'customer_download']);
        add_action('kontor_shop/admin/order_detail/documents', [$this, 'render_admin_box'], 10, 2);
        add_filter('kontor_shop/checkout/order_documents', [$this, 'customer_links'], 10, 2);
    }

    /**
     * @return array{name: string, address: string, vat_id: string, extra: string}
     */
    public function seller(): array
    {
        return [
            'name'    => trim((string) $this->settings->get('invoices.seller_name', '')),
            'address' => trim((string) $this->settings->get('invoices.seller_address', '')),
            'vat_id'  => trim((string) $this->settings->get('invoices.seller_vat_id', '')),
            'extra'   => trim((string) $this->settings->get('invoices.seller_extra', '')),
        ];
    }

    /** Absender vollstaendig (Name und Adresse sind Pflicht auf jeder Rechnung)? */
    public function ready(): bool
    {
        $seller = $this->seller();

        return $seller['name'] !== '' && $seller['address'] !== '' && InvoicePdf::available();
    }

    public function enabled(): bool
    {
        return (bool) $this->settings->get('invoices.enabled', false) && $this->ready();
    }

    private function trigger(): string
    {
        $trigger = (string) $this->settings->get('invoices.trigger', 'paid');

        return in_array($trigger, self::TRIGGERS, true) ? $trigger : 'paid';
    }

    // ---------------------------------------------------------------- Ausloeser

    public function on_paid(Order $order): void
    {
        if ($this->enabled() && $this->trigger() === 'paid' && !$order->pays_on_handover()) {
            $this->issue_safely($order, null);
        }
    }

    public function on_shipped(Order $order, mixed $shipment = null): void
    {
        if (!$this->enabled() || $this->trigger() === 'manual' || !in_array($order->status, [OrderStatus::SHIPPED, OrderStatus::PICKED_UP], true)) {
            return;
        }

        // "Beim Zahlungseingang" hat Vorkasse-Bestellungen schon berechnet; hier nur, was noch fehlt.
        $this->issue_safely($order, null);
    }

    /**
     * @param array<int, OrderItem> $items stornierte Positionen
     */
    public function on_cancellation(Order $order, array $items, float $refund, string $reason, bool $notify_customer, bool $full): void
    {
        $lines = array_map(static fn (OrderItem $item): array => ['item' => $item, 'quantity' => $item->quantity], $items);
        $this->credit_safely($order, $lines, $order->status === OrderStatus::CANCELLED, $reason);
    }

    /**
     * @param array<int, array{item: OrderItem, quantity: int}> $lines
     */
    public function on_return(Order $order, array $lines, float $refund, string $reason, bool $notify_customer): void
    {
        $this->credit_safely($order, $lines, false, $reason);
    }

    // ---------------------------------------------------------------- Ausstellen

    private function issue_safely(Order $order, ?int $user_id): void
    {
        try {
            $this->issue($order, $user_id);
        } catch (\Throwable $e) {
            Logger::exception($e, 'invoices', 'Rechnung konnte nicht erstellt werden', ['order_id' => $order->id]);
        }
    }

    /**
     * Rechnung ausstellen (falls noch keine existiert). Liefert den Beleg oder null.
     *
     * @return array<string, mixed>|null
     */
    public function issue(Order $order, ?int $user_id): ?array
    {
        if (!$this->ready() || $order->status === OrderStatus::CANCELLED || $this->invoices->invoice_for_order((int) $order->id) !== null) {
            return null;
        }

        $order   = $this->orders->find((int) $order->id) ?? $order;
        $items   = $this->orders->items_for_order((int) $order->id);
        $today   = current_time('Y-m-d');
        $terms   = (new PaymentOptions($this->settings))->terms();
        $due     = $order->awaits_payment() ? $terms->due_date($order) : null;
        $exempt  = TextDefaults::or_default((string) $this->settings->get('invoices.exempt_note', ''), TextDefaults::or_default((string) $this->settings->get('tax.exempt_note', ''), TextDefaults::exempt_note()));
        $series  = self::series($today);
        $account = (new BankAccounts($this->settings))->resolve($order->bank_account_id);

        $created = $this->invoices->create(
            (int) $order->id,
            InvoiceDocument::TYPE_INVOICE,
            $series,
            fn (int $seq): string => self::number($series, $seq, InvoiceDocument::TYPE_INVOICE),
            function (string $number) use ($order, $items, $today, $exempt, $due, $account): array {
                $data = InvoiceDocument::invoice($order, $items, $this->seller(), $number, $today, $exempt, $due !== null ? substr($due, 0, 10) : null);

                if ($order->awaits_payment() && $account !== null && !$order->is_cash_on_pickup()) {
                    $data['bank'] = ['recipient' => $account->recipient, 'iban' => $account->iban, 'bic' => $account->bic];
                }

                return $data;
            },
            null,
            $user_id
        );

        if ($created === null) {
            return null;
        }

        /* translators: %s: invoice number */
        $this->store_and_send($order, $created, $user_id, __('Invoice %s created.', 'kontor-shop'));

        return $this->invoices->find($created['id']);
    }

    /**
     * @param array<int, array{item: OrderItem, quantity: int}> $lines
     */
    private function credit_safely(Order $order, array $lines, bool $whole_order, string $reason): void
    {
        try {
            $invoice = $this->invoices->invoice_for_order((int) $order->id);

            if ($invoice === null || $this->invoices->is_settled((int) $order->id)) {
                return;
            }

            // Ganze Bestellung storniert und noch keine Teil-Gutschrift: die ganze Rechnung gutschreiben (inkl. Versand).
            $only_invoice = count($this->invoices->for_order((int) $order->id)) === 1;
            $use_lines    = $whole_order && $only_invoice ? null : array_values(array_filter($lines, static fn (array $line): bool => $line['quantity'] > 0));

            if ($use_lines === []) {
                return;
            }

            $today  = current_time('Y-m-d');
            $series = self::series($today);
            $note   = $reason !== '' ? sprintf(
                /* translators: %s: reason */
                __('Reason: %s', 'kontor-shop'),
                $reason
            ) : '';

            $created = $this->invoices->create(
                (int) $order->id,
                InvoiceDocument::TYPE_CREDIT_NOTE,
                $series,
                fn (int $seq): string => self::number($series, $seq, InvoiceDocument::TYPE_CREDIT_NOTE),
                static fn (string $number): array => InvoiceDocument::credit_note($invoice['data'], $use_lines, $number, $today, $note),
                (int) $invoice['id'],
                get_current_user_id() ?: null
            );

            if ($created !== null) {
                /* translators: %s: credit note number */
                $this->store_and_send($order, $created, get_current_user_id() ?: null, __('Credit note %s created.', 'kontor-shop'));
            }
        } catch (\Throwable $e) {
            Logger::exception($e, 'invoices', 'Gutschrift konnte nicht erstellt werden', ['order_id' => $order->id]);
        }
    }

    /**
     * PDF ablegen, im Verlauf vermerken und dem Kunden schicken.
     *
     * @param array{id: int, number: string, data: array<string, mixed>} $created
     */
    private function store_and_send(Order $order, array $created, ?int $user_id, string $log): void
    {
        $file = InvoiceStorage::save($created['number'], InvoicePdf::render($created['data'], $this->extras()));

        if ($file !== '') {
            $this->invoices->set_file($created['id'], $file);
        }

        $this->orders->add_log_note((int) $order->id, $user_id, sprintf($log, $created['number']));

        if ((bool) $this->settings->get('invoices.send_email', true)) {
            /**
             * Aktion kontor_shop/invoice/send: Beleg an den Kunden schicken
             * (NotificationsModule::send_invoice, Mail "Rechnung").
             *
             * @param Order  $order
             * @param array  $created id, number, data
             * @param string $path    absoluter Pfad zum PDF ('' = nicht gespeichert)
             * @param string $url     Download-Link fuer den Kunden
             */
            do_action('kontor_shop/invoice/send', $order, $created, InvoiceStorage::path($file), $this->customer_url($order, (int) $created['id']));
        }
    }

    /**
     * Zusaetze fuer das PDF: Logo (data-URI), Fusszeile, "Vereinsshop mit Kontor Shop".
     *
     * @return array{logo: string, footer: string, powered_by: string}
     */
    public function extras(): array
    {
        $extras = [
            'logo'       => '',
            'footer'     => '',
            'powered_by' => PoweredBy::active($this->settings) ? PoweredBy::text() : '',
        ];

        /**
         * Filter kontor_shop/invoice/extras: Logo (data-URI eines PNG/JPG/SVG),
         * eigene Fusszeile, Hinweis "Vereinsshop mit Kontor Shop" (nur Free
         * nach Zustimmung). Pro setzt Logo und Fusszeile aus den Einstellungen.
         *
         * @param array{logo: string, footer: string, powered_by: string} $extras
         */
        $filtered = (array) apply_filters('kontor_shop/invoice/extras', $extras);

        return [
            'logo'       => str_starts_with((string) ($filtered['logo'] ?? ''), 'data:image/') ? (string) $filtered['logo'] : '',
            'footer'     => (string) ($filtered['footer'] ?? ''),
            'powered_by' => (string) ($filtered['powered_by'] ?? ''),
        ];
    }

    /**
     * Nummernkreis (Serie): Standard je Kalenderjahr.
     */
    public static function series(string $today): string
    {
        /**
         * Filter kontor_shop/invoice/series: Schluessel des Nummernkreises,
         * in dem lueckenlos weitergezaehlt wird. Standard: Jahr ("2026") -
         * jedes Jahr beginnt bei 1. Pro: "all" fuer durchgehend.
         *
         * @param string $series
         * @param string $today  Y-m-d
         */
        return substr(sanitize_key((string) apply_filters('kontor_shop/invoice/series', substr($today, 0, 4), $today)), 0, 20) ?: substr($today, 0, 4);
    }

    public static function number(string $series, int $seq, string $type): string
    {
        $default = (ctype_digit($series) ? $series . '-' : '') . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);

        /**
         * Filter kontor_shop/invoice/number: Rechnungsnummer aus Serie und
         * laufender Nummer. Standard "2026-0001". Pro: Praefix/Stellen.
         *
         * @param string $number
         * @param string $series
         * @param int    $seq
         * @param string $type   invoice|credit_note
         */
        $number = (string) apply_filters('kontor_shop/invoice/number', $default, $series, $seq, $type);

        return substr(preg_replace('/[^A-Za-z0-9._\/-]/', '', $number) ?: $default, 0, 40);
    }

    // ---------------------------------------------------------------- Download

    public function customer_url(Order $order, int $invoice_id): string
    {
        $status = OrderStatusLink::url($order, $this->settings);

        return $status !== '' ? add_query_arg('kontor_invoice', $invoice_id, $status) : '';
    }

    public static function admin_url(int $invoice_id): string
    {
        return wp_nonce_url(admin_url('admin-post.php?action=' . self::DOWNLOAD_ACTION . '&invoice=' . $invoice_id), self::DOWNLOAD_ACTION . '_' . $invoice_id);
    }

    public function admin_download(): void
    {
        $id = absint($_GET['invoice'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce direkt darunter

        check_admin_referer(self::DOWNLOAD_ACTION . '_' . $id);

        if (!Capabilities::can_manage() && !Capabilities::is_cashier()) {
            wp_die(esc_html__('No permission.', 'kontor-shop'), '', ['response' => 403]);
        }

        $this->stream($this->invoices->find($id));
    }

    public function customer_download(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- geschuetzt ueber das geheime Bestell-Token (wie die Bestellseite)
        if (!isset($_GET['kontor_invoice'], $_GET['kontor_order'])) {
            return;
        }

        $id    = absint($_GET['kontor_invoice']);
        $token = sanitize_text_field(wp_unslash((string) $_GET['kontor_order']));
        // phpcs:enable

        $orders  = $token !== '' ? $this->orders->find_by_token($token) : [];
        $invoice = $this->invoices->find($id);
        $ids     = array_map(static fn (Order $order): int => (int) $order->id, $orders);

        if ($invoice === null || !in_array($invoice['order_id'], $ids, true) || !OrderLink::is_valid($orders, OrderStatusLink::days($this->settings), time())) {
            wp_die(esc_html__('This link is invalid or has expired.', 'kontor-shop'), '', ['response' => 404]);
        }

        $this->stream($invoice);
    }

    /**
     * @param array<string, mixed>|null $invoice
     */
    private function stream(?array $invoice): void
    {
        if ($invoice === null) {
            wp_die(esc_html__('Document not found.', 'kontor-shop'), '', ['response' => 404]);
        }

        $path = InvoiceStorage::path((string) $invoice['file']);
        // Fehlt die Datei (z. B. Umzug ohne Upload-Ordner), entsteht sie aus dem gespeicherten Schnappschuss neu.
        $pdf  = $path !== '' ? (string) file_get_contents($path) : InvoicePdf::render((array) $invoice['data'], $this->extras()); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- eigene, gesperrte Ablage

        if ($pdf === '') {
            wp_die(esc_html__('The PDF could not be created.', 'kontor-shop'), '', ['response' => 500]);
        }

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . sanitize_file_name((string) $invoice['number']) . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        header('X-Robots-Tag: noindex');
        echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PDF-Binaerdaten
        exit;
    }

    /**
     * Musterrechnung mit den gespeicherten Absenderdaten (nichts wird angelegt).
     */
    public function admin_preview(): void
    {
        check_admin_referer(\Kontor\Shop\Admin\Settings\InvoicesSection::PREVIEW_ACTION);

        if (!current_user_can(Capabilities::SETTINGS)) {
            wp_die(esc_html__('No permission.', 'kontor-shop'), '', ['response' => 403]);
        }

        $seller = $this->seller();
        $seller['name'] = $seller['name'] !== '' ? $seller['name'] : get_bloginfo('name');
        $today  = current_time('Y-m-d');
        $order  = new Order(0, 'B-000123', 'KS-000123', OrderStatus::OPEN, false, 'Max Mustermann', 'max@example.org', "Max Mustermann\nMusterstraße 1\n1010 Wien", false, 5.90, 44.80, 50.70,
            'prepayment', null, null, null, null, null, $today . ' 09:00:00', null, null, null, [['tax_rate' => 20.0, 'line_total' => 5.90]]);
        $items  = [
            new OrderItem(1, 0, 1, 1, __('Sample product', 'kontor-shop') . ' – L', 1, 39.90, 39.90, 39.90, 20.0),
            new OrderItem(2, 0, 2, 1, __('Sample book', 'kontor-shop'), 1, 4.90, 4.90, 4.90, 10.0),
        ];
        $data   = InvoiceDocument::invoice($order, $items, $seller, self::number(self::series($today), 1, InvoiceDocument::TYPE_INVOICE), $today, TextDefaults::exempt_note(), $today);
        $account = (new BankAccounts($this->settings))->resolve(null);

        if ($account !== null) {
            $data['bank'] = ['recipient' => $account->recipient, 'iban' => $account->iban, 'bic' => $account->bic];
        }

        $this->stream(['number' => __('sample', 'kontor-shop'), 'file' => '', 'data' => $data]);
    }

    // ---------------------------------------------------------------- Anzeige

    public function admin_create(): void
    {
        $order_id = absint($_POST['order_id'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce direkt darunter

        check_admin_referer(self::CREATE_ACTION . '_' . $order_id);

        if (!Capabilities::can_manage() && !Capabilities::is_cashier()) {
            wp_die(esc_html__('No permission.', 'kontor-shop'), '', ['response' => 403]);
        }

        $order   = $this->orders->find($order_id);
        $invoice = $order !== null ? $this->issue($order, get_current_user_id() ?: null) : null;
        $back    = admin_url('admin.php?page=kontor-shop-orders&order=' . $order_id);

        \Kontor\Shop\Admin\Ui\Flash::redirect(
            $back,
            $invoice !== null
                /* translators: %s: invoice number */
                ? sprintf(__('Invoice %s created and sent to the customer.', 'kontor-shop'), $invoice['number'])
                : __('No invoice was created. Check Settings → Invoices (sender name and address are required) – or the order already has an invoice or is cancelled.', 'kontor-shop'),
            $invoice !== null ? 'success' : 'error'
        );
    }

    /**
     * Kasten "Rechnungen" in der Bestelldetailansicht.
     */
    public function render_admin_box(Order $order, bool $full): void
    {
        $docs = $this->invoices->for_order((int) $order->id);

        if ($docs === [] && !$this->ready()) {
            if ($full && (bool) $this->settings->get('invoices.enabled', false)) {
                echo '<p class="description">' . esc_html__('Invoices are switched on, but sender name and address are missing under Settings → Invoices.', 'kontor-shop') . '</p>';
            }

            return;
        }

        echo '<h2>' . esc_html__('Invoices', 'kontor-shop') . '</h2><ul class="ks-docs">';

        foreach ($docs as $doc) {
            $label = $doc['type'] === InvoiceDocument::TYPE_CREDIT_NOTE ? __('Credit note', 'kontor-shop') : __('Invoice', 'kontor-shop');
            echo '<li><a href="' . esc_url(self::admin_url((int) $doc['id'])) . '" target="_blank" rel="noopener">' . esc_html($label . ' ' . $doc['number']) . '</a> · '
                . esc_html(mysql2date('d.m.Y', (string) $doc['issued_at'])) . ' · ' . esc_html(number_format_i18n((float) $doc['total'], 2) . ' €') . '</li>';
        }

        echo '</ul>';

        if ($full && $this->ready() && $order->status !== OrderStatus::CANCELLED && $this->invoices->invoice_for_order((int) $order->id) === null) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ks-inline-form">';
            wp_nonce_field(self::CREATE_ACTION . '_' . (int) $order->id);
            echo '<input type="hidden" name="action" value="' . esc_attr(self::CREATE_ACTION) . '" /><input type="hidden" name="order_id" value="' . esc_attr((string) $order->id) . '" />';
            echo '<button type="submit" class="button">' . esc_html__('Create invoice now', 'kontor-shop') . '</button></form>';
        }
    }

    /**
     * Download-Links auf der Bestellseite des Kunden.
     */
    public function customer_links(mixed $html, mixed $order = null): string
    {
        $html = (string) $html;

        if (!$order instanceof Order) {
            return $html;
        }

        $links = [];

        foreach ($this->invoices->for_order((int) $order->id) as $doc) {
            $url = $this->customer_url($order, (int) $doc['id']);

            if ($url !== '') {
                $label   = $doc['type'] === InvoiceDocument::TYPE_CREDIT_NOTE ? __('Credit note', 'kontor-shop') : __('Invoice', 'kontor-shop');
                $links[] = '<a class="kontor-shop-btn kontor-shop-btn--ghost" href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($label . ' ' . $doc['number']) . ' (PDF)</a>';
            }
        }

        return $links === [] ? $html : $html . '<div class="kontor-shop-order-confirmation__docs">' . implode(' ', $links) . '</div>';
    }
}
