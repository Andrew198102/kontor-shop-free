<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Notifications;

use Kontor\Shop\Core\Registerable;
use Kontor\Shop\Domain\Notifications\DefaultTemplates;
use Kontor\Shop\Domain\Notifications\MailMessage;
use Kontor\Shop\Domain\Notifications\Recipients;
use Kontor\Shop\Domain\Notifications\Template;
use Kontor\Shop\Domain\Payment\EpcQr;
use Kontor\Shop\Domain\Payment\BankAccount;
use Kontor\Shop\Domain\Order\Carrier;
use Kontor\Shop\Domain\Order\Fulfilment;
use Kontor\Shop\Domain\Order\ItemCancellation;
use Kontor\Shop\Domain\Order\Order;
use Kontor\Shop\Domain\Order\OrderItem;
use Kontor\Shop\Domain\Order\OrderStatus;
use Kontor\Shop\Domain\Order\PaymentMethod;
use Kontor\Shop\Domain\Order\PaymentReminder;
use Kontor\Shop\Domain\Order\PaymentTerms;
use Kontor\Shop\Domain\Order\Shipment;
use Kontor\Shop\Infrastructure\Frontend\ThemePalette;
use Kontor\Shop\Infrastructure\Notifications\MailHealth;
use Kontor\Shop\Infrastructure\Notifications\MailLayout;
use Kontor\Shop\Infrastructure\Notifications\MailerFactory;
use Kontor\Shop\Infrastructure\Payment\BankAccounts;
use Kontor\Shop\Infrastructure\Repositories\OrderRepository;
use Kontor\Shop\Modules\Checkout\OrderStatusLink;
use Kontor\Shop\Modules\Payments\PaymentOptions;
use Kontor\Shop\Infrastructure\Repositories\WarehouseRepository;
use Kontor\Shop\Domain\Catalog\Warehouse;
use Kontor\Shop\Settings\Repository;

/**
 * Mail-Trigger je Bestell-Ereignis (Abschnitt 9). Hoert auf generische
 * Lifecycle-Hooks statt Order-Status direkt zu ueberwachen, damit
 * auslösende Module (Checkout fuer HOOK_ORDER_CREATED; P8s OrdersPage
 * spaeter fuer HOOK_ORDER_PAID/HOOK_ORDER_SHIPPED) unabhaengig bleiben.
 */
final class NotificationsModule implements Registerable
{
    public const HOOK_ORDER_CREATED = 'kontor_shop/order/created';
    public const HOOK_ORDER_PAID    = 'kontor_shop/order/paid';
    public const HOOK_ORDER_SHIPPED = 'kontor_shop/order/shipped';
    public const HOOK_ORDER_CANCELLED = 'kontor_shop/order/cancelled';

    /** Storno (ganz oder einzelne Positionen); Argumente: Order, stornierte OrderItems, Rueckzahlung, Grund, Kunde informieren, ganze Bestellung */
    public const HOOK_ORDER_CANCELLATION = 'kontor_shop/order/cancellation';

    /** Retoure; Argumente: Order, Zeilen array{item: OrderItem, quantity: int}, Rueckzahlung, Grund, Kunde informieren */
    public const HOOK_ORDER_RETURN = 'kontor_shop/order/return';

    /** Bestaetigung erneut senden (Backoffice); Argumente: Order, optionale abweichende Empfaengeradresse */
    public const HOOK_RESEND_CONFIRMATION = 'kontor_shop/order/resend_confirmation';

    private Repository $settings;
    private OrderRepository $orders;
    private WarehouseRepository $warehouses;

    public function __construct(Repository $settings)
    {
        $this->settings   = $settings;
        $this->orders     = new OrderRepository();
        $this->warehouses = new WarehouseRepository();
    }

    public function register(): void
    {
        MailHealth::register();

        add_action(self::HOOK_ORDER_CREATED, [$this, 'send_order_confirmation']);
        add_action(self::HOOK_ORDER_CREATED, [$this, 'send_new_order_notification']);
        // Auf Rechnung: Versandauftrag sofort, nicht erst nach der Zahlung.
        add_action(self::HOOK_ORDER_CREATED, [$this, 'send_invoice_ship_order']);
        add_action(self::HOOK_ORDER_CREATED, [$this, 'send_free_ship_order']);
        add_action(self::HOOK_RESEND_CONFIRMATION, [$this, 'resend_order_confirmation'], 10, 2);
        add_action(self::HOOK_ORDER_PAID, [$this, 'send_payment_received']);
        add_action(self::HOOK_ORDER_PAID, [$this, 'send_payment_confirmed']);
        add_action(self::HOOK_ORDER_SHIPPED, [$this, 'send_shipped_confirmation'], 10, 2);
        add_action(self::HOOK_ORDER_CANCELLED, [$this, 'send_order_cancelled']);
        add_action(self::HOOK_ORDER_CANCELLATION, [$this, 'on_cancellation'], 10, 6);
        add_action(self::HOOK_ORDER_RETURN, [$this, 'on_return'], 10, 5);
    }

    /**
     * Zahlungserinnerung an den Kunden (per Knopf, mit Pro auch automatisch:
     * $user_id null) und Eintrag im Statusverlauf. false = passt nicht
     * (bezahlt, Rechnung noch nicht versendet).
     */
    public function send_payment_reminder(Order $order, ?int $user_id): bool
    {
        if (!PaymentReminder::applies($order)) {
            return false;
        }

        $event = $order->is_invoice() ? DefaultTemplates::INVOICE_REMINDER : DefaultTemplates::PAYMENT_REMINDER;

        $this->send_templated($event, $order->customer_email, $order->customer_name, $this->reminder_vars($order));

        $this->orders->add_log_note(
            (int) $order->id,
            $user_id,
            sprintf(
                /* translators: %s: email address */
                $user_id !== null ? __('Payment reminder sent manually to %s.', 'kontor-shop') : __('Automatic payment reminder sent to %s.', 'kontor-shop'),
                $order->customer_email
            ),
            PaymentReminder::LOG_ACTOR
        );

        return true;
    }

    /**
     * Platzhalter-Werte der Zahlungserinnerungen (shop_name, customer_name,
     * order_number, items, total, payment_details, payment_reference,
     * payment_due_date, order_status_url) - auch fuer Erweiterungen, die
     * eigene Erinnerungen ueber send_event() verschicken.
     *
     * @return array<string, string>
     */
    public function reminder_vars(Order $order): array
    {
        $items_lines = [];

        foreach ($this->orders->items_for_order((int) $order->id) as $item) {
            if (!$item->is_cancelled()) {
                $items_lines[] = sprintf('- %dx %s', $item->quantity, $item->product_name);
            }
        }

        [$payment_details, $due_text] = $this->payment_details($order);

        return [
            'shop_name'         => $this->shop_name(),
            'customer_name'     => $order->customer_name,
            'order_number'      => $order->order_number,
            'items'             => implode("\n", $items_lines),
            'total'             => number_format($order->total, 2, ',', '.'),
            'payment_details'   => $payment_details,
            'payment_reference' => $order->payment_reference,
            'payment_due_date'  => $due_text !== '' ? $due_text : '-',
            'order_status_url'  => OrderStatusLink::url($order, $this->settings),
        ];
    }

    /**
     * Zahlungsdaten fuer Bestaetigung und Erinnerung.
     *
     * @return array{0: string, 1: string} [Text, Faelligkeitsdatum d.m.Y oder '']
     */
    private function payment_details(Order $order): array
    {
        $account  = (new BankAccounts($this->settings))->resolve($order->bank_account_id);
        $terms    = $this->terms();
        $due      = $terms->due_date($order);
        $due_text = $due !== null ? mysql2date('d.m.Y', $due) : '';

        if ($order->nothing_to_pay()) {
            return [$order->self_pickup
                ? __('Nothing to pay: your discount code covers the full amount. We\'ll set your order aside for pickup.', 'kontor-shop')
                : __('Nothing to pay: your discount code covers the full amount. We\'ll ship your order as soon as it\'s packed.', 'kontor-shop'), ''];
        }

        if ($order->is_invoice()) {
            return [$this->invoice_payment_text($order, $terms, $due_text), $due_text];
        }

        if ($order->is_cash_on_pickup()) {
            return [sprintf(
                /* translators: %s: amount */
                __('Please pay %s € in cash when you pick up your order. We\'ll let you know by email as soon as it\'s ready.', 'kontor-shop'),
                number_format_i18n($order->total, 2)
            ), ''];
        }

        $deadline = $due_text !== ''
            ? sprintf(
                /* translators: %s: date */
                __('by %s at the latest', 'kontor-shop'),
                $due_text
            ) . ' ' . (PaymentOptions::auto_cancel_active()
                ? __('(the goods are reserved for you until then; after that, the order is cancelled automatically)', 'kontor-shop')
                : __('(the goods are reserved for you until then)', 'kontor-shop'))
            : '';

        $text = $this->transfer_request($order->total, $deadline) . "\n" . $this->bank_lines($account, $order)
            . $this->qr_hint($order, $account);

        return [$text, $due_text];
    }

    /**
     * Zahlungstext auf Rechnung: vor dem Versand das Zahlungsziel, danach
     * Betrag, Faelligkeit und Bankdaten.
     */
    private function invoice_payment_text(Order $order, PaymentTerms $terms, string $due_text): string
    {
        $account = (new BankAccounts($this->settings))->resolve($order->bank_account_id);
        $bank    = $this->bank_lines($account, $order);

        if (!$order->is_dispatched()) {
            return ($terms->invoice_days > 0
                ? sprintf(
                    /* translators: %d: days */
                    __('You get the goods first and pay afterward: the invoice is due within %d days of shipping. The payment details are also in the shipping email.', 'kontor-shop'),
                    $terms->invoice_days
                )
                : __('You get the goods first and pay afterward. The payment details are also in the shipping email.', 'kontor-shop'))
                . "\n" . $bank;
        }

        // ueberfaellig: kein "bis spaetestens" mit vergangenem Datum (die Erinnerung nennt die Faelligkeit)
        $deadline = $due_text !== '' && !$terms->is_overdue($order, current_time('Y-m-d'))
            /* translators: %s: date */
            ? sprintf(__('by %s at the latest', 'kontor-shop'), $due_text)
            : '';

        return $this->transfer_request($order->total, $deadline) . "\n" . $bank . $this->qr_hint($order, $account);
    }

    /**
     * "Bitte ueberweise 34,50 Euro bis spaetestens …" (Frist optional).
     */
    private function transfer_request(float $total, string $deadline): string
    {
        $amount = number_format($total, 2, ',', '.');

        return $deadline !== ''
            /* translators: 1: amount, 2: deadline, e.g. "by 08.10.2026 at the latest (…)" */
            ? sprintf(__('Please transfer %1$s euros %2$s to:', 'kontor-shop'), $amount, $deadline)
            /* translators: %s: amount */
            : sprintf(__('Please transfer %s euros to:', 'kontor-shop'), $amount);
    }

    /**
     * Empfaenger, IBAN, BIC und Zahlungsreferenz, je eine Zeile.
     */
    private function bank_lines(?BankAccount $account, Order $order): string
    {
        return implode("\n", [
            $account->recipient ?? '',
            'IBAN: ' . ($account->iban ?? ''),
            'BIC: ' . ($account->bic ?? ''),
            /* translators: %s: payment reference */
            sprintf(__('Payment reference (please be sure to include it): %s', 'kontor-shop'), $order->payment_reference),
        ]);
    }

    /**
     * Hinweis auf den Ueberweisungs-QR-Code auf der Bestellseite (Mails
     * zeigen keine Grafiken zuverlaessig an) - nur wenn ein Code moeglich ist.
     */
    private function qr_hint(Order $order, ?BankAccount $account): string
    {
        if ($account === null || !PaymentOptions::qr_enabled($this->settings) || EpcQr::payload($account, $order->total, $order->payment_reference) === null) {
            return '';
        }

        $url = OrderStatusLink::url($order, $this->settings);

        return $url !== '' ? "\n\n" . sprintf(
            /* translators: %s: link to the order page */
            __('Tip: your order page has a QR code – scan it with your banking app and all payment details are filled in: %s', 'kontor-shop'),
            $url
        ) : '';
    }

    private function terms(): PaymentTerms
    {
        return (new PaymentOptions($this->settings))->terms();
    }

    /**
     * Ist die Mail fuer dieses Ereignis eingeschaltet? "Neue Bestellung"
     * nutzt weiterhin den bestehenden Schalter admin_notify_enabled.
     */
    public function is_enabled(string $event): bool
    {
        if ($event === DefaultTemplates::NEW_ORDER) {
            return (bool) $this->settings->get('notifications.admin_notify_enabled', true);
        }

        return (bool) $this->settings->get("notifications.templates.{$event}.enabled", true);
    }

    /**
     * Betreff + Text einer Vorlage. Eine gespeicherte, nie angepasste
     * Nur-Text-Vorlage der frueheren Version wird durch die neue
     * HTML-Standardvorlage ersetzt.
     *
     * @return array{subject: string, body: string}
     */
    public function template(string $event): array
    {
        $defaults = DefaultTemplates::all()[$event] ?? ['subject' => '', 'body' => ''];
        $subject  = (string) $this->settings->get("notifications.templates.{$event}.subject", '');
        $body     = (string) $this->settings->get("notifications.templates.{$event}.body", '');

        if ($body === '' || in_array($body, DefaultTemplates::legacy_bodies()[$event] ?? [], true)) {
            $body = $defaults['body'];
        }

        return ['subject' => $subject !== '' ? $subject : $defaults['subject'], 'body' => $body];
    }

    /**
     * Bestaetigung erneut senden (Backoffice): bewusste Handlung, geht auch
     * raus, wenn die automatische Bestellbestaetigung ausgeschaltet ist.
     */
    public function resend_order_confirmation(Order $order, ?string $to = null): void
    {
        $this->send_order_confirmation($order, $to, true);
    }

    /**
     * Bestellbestaetigung an den Kaeufer - oder, fuer das erneute Senden aus dem
     * Backoffice, an eine abweichende Adresse ($to).
     */
    public function send_order_confirmation(Order $order, ?string $to = null, bool $force = false): void
    {
        if (!$force && !$this->is_enabled(DefaultTemplates::ORDER_CONFIRMATION)) {
            return;
        }

        $items = $this->orders->items_for_order((int) $order->id);

        $items_lines = [];

        foreach ($items as $item) {
            $items_lines[] = sprintf(
                /* translators: 1: quantity, 2: product, 3: unit price */
                __('- %1$dx %2$s (%3$s euros each)', 'kontor-shop'),
                $item->quantity,
                $item->product_name,
                number_format($item->unit_price, 2, ',', '.')
            );
        }

        [$payment_details, $due_text] = $this->payment_details($order);

        $recipient = $to !== null && is_email($to) ? $to : $order->customer_email;

        $confirmation_url = OrderStatusLink::url($order, $this->settings);

        $this->send_templated(DefaultTemplates::ORDER_CONFIRMATION, $recipient, $order->customer_name, [
            'confirmation_url'      => $confirmation_url,
            'order_status_url'      => $confirmation_url,
            'shop_name'             => $this->shop_name(),
            'customer_name'         => $order->customer_name,
            'order_number'          => $order->order_number,
            'items'                 => implode("\n", $items_lines),
            'items_total'           => number_format($order->items_total, 2, ',', '.'),
            'goods_total'           => number_format($order->goods_total(), 2, ',', '.'),
            'discount_line'         => self::discount_line($order),
            'shipping_cost'         => number_format($order->shipping_cost, 2, ',', '.'),
            'total'                 => number_format($order->total, 2, ',', '.'),
            'payment_method_label'  => PaymentMethod::label($order->payment_method),
            'payment_details'       => $payment_details,
            'payment_reference'     => $order->payment_reference,
            'payment_due_date'      => $due_text !== '' ? $due_text : '-',
        ]);
    }

    /**
     * Zeile "Rabatt (CODE): −5,00 €" mit fuehrendem Umbruch fuer {{discount_line}}
     * (steht in der Vorlage direkt hinter dem Warenwert); ohne Rabatt leer.
     */
    private static function discount_line(Order $order): string
    {
        if (!$order->has_discount()) {
            return '';
        }

        return "\n" . sprintf(
            /* translators: 1: discount code, 2: amount */
            __('Discount (%1$s): −%2$s €', 'kontor-shop'),
            (string) $order->discount_code,
            number_format($order->discount_total, 2, ',', '.')
        );
    }

    /**
     * "Notfall"-Benachrichtigung an den Shop-Betreiber: laeuft ohne jede
     * Integration (Standard-Mailer wp_mail), damit niemand im Backend
     * nachsehen muss, ob bestellt wurde. Empfaenger: Einstellung, sonst die
     * WordPress-Administrator-Adresse.
     */
    public function send_new_order_notification(Order $order): void
    {
        if (!$this->is_enabled(DefaultTemplates::NEW_ORDER)) {
            return;
        }

        $recipients = $this->new_order_recipients();

        $items_lines = [];

        foreach ($this->orders->items_for_order((int) $order->id) as $item) {
            /* translators: 1: quantity, 2: product, 3: unit price */
            $items_lines[] = sprintf(__('- %1$dx %2$s (%3$s euros each)', 'kontor-shop'), $item->quantity, $item->product_name, number_format($item->unit_price, 2, ',', '.'));
        }

        $values = [
            'shop_name'            => $this->shop_name(),
            'order_number'         => $order->order_number,
            'payment_reference'    => $order->payment_reference,
            'customer_name'        => $order->customer_name,
            'customer_email'       => $order->customer_email,
            'customer_phone'       => $order->customer_phone ?? '-',
            'billing_info'         => $order->billing_address !== null
                ? str_replace("\n", ', ', $order->billing_address) . ($order->billing_vat_id !== null ? ' (UID ' . $order->billing_vat_id . ')' : '')
                : __('same as shipping address', 'kontor-shop'),
            'items'                => implode("\n", $items_lines),
            'items_total'          => number_format($order->items_total, 2, ',', '.'),
            'goods_total'          => number_format($order->goods_total(), 2, ',', '.'),
            'discount_line'        => self::discount_line($order),
            'shipping_cost'        => number_format($order->shipping_cost, 2, ',', '.'),
            'total'                => number_format($order->total, 2, ',', '.'),
            'payment_method_label' => PaymentMethod::label($order->payment_method),
            'delivery_info'        => $order->self_pickup
                ? ($order->is_cash_on_pickup() && !$order->nothing_to_pay()
                ? sprintf(
                    /* translators: %s: amount */
                    __('Self pickup – the customer pays %s € in cash on pickup', 'kontor-shop'),
                    number_format_i18n($order->total, 2)
                )
                : __('Self pickup', 'kontor-shop'))
                : __('Delivery address:', 'kontor-shop') . "\n" . (string) $order->delivery_address,
            'orders_url'           => admin_url('admin.php?page=kontor-shop-orders'),
        ];

        foreach ($recipients as $recipient) {
            $this->send_templated(DefaultTemplates::NEW_ORDER, $recipient, '', $values);
        }
    }

    public function send_payment_received(Order $order): void
    {
        if (!$this->is_enabled(DefaultTemplates::PAYMENT_RECEIVED)) {
            return;
        }

        $this->send_templated(DefaultTemplates::PAYMENT_RECEIVED, $order->customer_email, $order->customer_name, [
            'shop_name'     => $this->shop_name(),
            'customer_name' => $order->customer_name,
            'order_number'  => $order->order_number,
            'order_status_url' => OrderStatusLink::url($order, $this->settings),
            'total'         => number_format($order->total, 2, ',', '.'),
            'next_step'     => match (true) {
                $order->is_invoice() && $order->is_dispatched() => __('That settles your invoice.', 'kontor-shop'),
                $order->is_invoice()                            => __('That settles your invoice – the goods are already on their way or being prepared.', 'kontor-shop'),
                $order->self_pickup                             => __('We\'re now setting the goods aside for you to pick up – we\'ll let you know as soon as everything is ready.', 'kontor-shop'),
                default                                         => __('We\'ll ship your order in the next few days – you\'ll get a shipping confirmation then.', 'kontor-shop'),
            },
        ]);
    }

    /**
     * @param array<int, OrderItem> $items
     */
    public function on_cancellation(Order $order, array $items, float $refund, string $reason, bool $notify_customer, bool $full): void
    {
        if ($notify_customer) {
            if ($full || $order->status === OrderStatus::CANCELLED) {
                $this->send_order_cancelled($order, $refund, $reason);
            } else {
                $this->send_items_cancelled($order, $items, $refund, $reason);
            }
        }

        $this->send_cancellation_internal($order, $items, $refund, $reason, $full || $order->status === OrderStatus::CANCELLED);
    }

    public function send_order_cancelled(Order $order, ?float $refund = null, string $reason = ''): void
    {
        if (!$this->is_enabled(DefaultTemplates::ORDER_CANCELLED)) {
            return;
        }

        $refund ??= $order->refund_pending();

        $this->send_templated(DefaultTemplates::ORDER_CANCELLED, $order->customer_email, $order->customer_name, [
            'shop_name'     => $this->shop_name(),
            'customer_name' => $order->customer_name,
            'order_number'  => $order->order_number,
            'order_status_url' => OrderStatusLink::url($order, $this->settings),
            'total'         => number_format($order->total, 2, ',', '.'),
            /* translators: %s: cancellation reason */
            'cancel_reason' => $reason !== '' ? sprintf(__('Reason: %s', 'kontor-shop'), $reason) : '',
            'refund_note'   => $refund > 0
                /* translators: %s: refund amount */
                ? sprintf(__('We\'ll transfer the amount of %s euros back to your account in the next few days.', 'kontor-shop'), number_format($refund, 2, ',', '.'))
                : (ItemCancellation::is_paid($order)
                    ? ''
                    : __('You don\'t have to pay anything. If you\'ve already transferred the amount, we\'ll of course refund it.', 'kontor-shop')),
        ]);
    }

    /**
     * Einzelne Artikel storniert (Rest der Bestellung bleibt bestehen).
     *
     * @param array<int, OrderItem> $items
     */
    public function send_items_cancelled(Order $order, array $items, float $refund, string $reason): void
    {
        if (!$this->is_enabled(DefaultTemplates::ITEMS_CANCELLED)) {
            return;
        }

        $this->send_templated(DefaultTemplates::ITEMS_CANCELLED, $order->customer_email, $order->customer_name, [
            'shop_name'       => $this->shop_name(),
            'customer_name'   => $order->customer_name,
            'order_number'    => $order->order_number,
            'order_status_url' => OrderStatusLink::url($order, $this->settings),
            'cancelled_items' => $this->item_lines($items),
            /* translators: %s: cancellation reason */
            'cancel_reason'   => $reason !== '' ? sprintf(__('Reason: %s', 'kontor-shop'), $reason) : '',
            'amount_note'     => $this->customer_amount_note($order, $refund),
        ]);
    }

    /**
     * Interne Info zur Stornierung - v. a. damit eine Rueckzahlung nicht vergessen wird.
     *
     * @param array<int, OrderItem> $items
     */
    public function send_cancellation_internal(Order $order, array $items, float $refund, string $reason, bool $full): void
    {
        if (!$this->is_enabled(DefaultTemplates::CANCELLATION_INTERNAL)) {
            return;
        }

        $values = [
            'shop_name'       => $this->shop_name(),
            'order_number'    => $order->order_number,
            'customer_name'   => $order->customer_name,
            'customer_email'  => $order->customer_email,
            'cancel_scope'    => $full ? __('The entire order was cancelled.', 'kontor-shop') : __('Individual products were cancelled, and the rest of the order stays as is.', 'kontor-shop'),
            'cancelled_items' => $this->item_lines($items),
            'cancel_reason'   => $reason !== '' ? $reason : '-',
            'amount_note'     => $refund > 0
                ? sprintf(
                    /* translators: 1: amount, 2: payment reference */
                    __('REFUND REQUIRED: Please transfer %1$s euros back to the customer (order payment reference: %2$s) and then click “Refund completed” in the order.', 'kontor-shop'),
                    number_format($refund, 2, ',', '.'),
                    $order->payment_reference
                )
                : (ItemCancellation::is_paid($order)
                    ? __('No refund saved.', 'kontor-shop')
                    /* translators: %s: new open amount */
                    : sprintf(__('No refund needed (not paid yet). New open amount: %s euros.', 'kontor-shop'), number_format($order->total, 2, ',', '.'))),
            'order_url'       => admin_url('admin.php?page=kontor-shop-orders&order=' . (int) $order->id),
        ];

        foreach ($this->internal_recipients(DefaultTemplates::CANCELLATION_INTERNAL) as $recipient) {
            $this->send_templated(DefaultTemplates::CANCELLATION_INTERNAL, $recipient, '', $values);
        }
    }

    /**
     * Retoure eingegangen: Kunde bekommt Bestaetigung mit Betragshinweis.
     *
     * @param array<int, array{item: OrderItem, quantity: int}> $lines
     */
    public function on_return(Order $order, array $lines, float $refund, string $reason, bool $notify_customer): void
    {
        if (!$notify_customer || !$this->is_enabled(DefaultTemplates::RETURN_RECEIVED)) {
            return;
        }

        $this->send_templated(DefaultTemplates::RETURN_RECEIVED, $order->customer_email, $order->customer_name, [
            'shop_name'        => $this->shop_name(),
            'customer_name'    => $order->customer_name,
            'order_number'     => $order->order_number,
            'returned_items'   => implode("\n", array_map(static fn (array $line): string => sprintf(
                /* translators: 1: quantity, 2: product, 3: amount */
                __('- %1$dx %2$s (%3$s euros)', 'kontor-shop'),
                $line['quantity'],
                $line['item']->product_name,
                number_format($line['item']->unit_price * $line['quantity'], 2, ',', '.')
            ), $lines)),
            /* translators: %s: return note */
            'return_reason'    => $reason !== '' ? sprintf(__('Note: %s', 'kontor-shop'), $reason) : '',
            'amount_note'      => $this->return_amount_note($order, $refund),
            'order_status_url' => OrderStatusLink::url($order, $this->settings),
        ]);
    }

    private function return_amount_note(Order $order, float $refund): string
    {
        if ($refund > 0) {
            /* translators: %s: refund amount */
            return sprintf(__('We\'ll transfer the amount of %s euros back to your account in the next few days.', 'kontor-shop'), number_format($refund, 2, ',', '.'));
        }

        if ($order->is_paid()) {
            return '';
        }

        return $order->total > 0
            ? sprintf(
                /* translators: 1: amount, 2: payment reference */
                __('That reduces your invoice to %1$s euros – please transfer only this amount (payment reference: %2$s).', 'kontor-shop'),
                number_format($order->total, 2, ',', '.'),
                $order->payment_reference
            )
            : __('That means there\'s nothing left to pay.', 'kontor-shop');
    }

    private function customer_amount_note(Order $order, float $refund): string
    {
        if ($refund > 0) {
            /* translators: %s: refund amount */
            return sprintf(__('We\'ll transfer the amount of %s euros back to your account in the next few days.', 'kontor-shop'), number_format($refund, 2, ',', '.'));
        }

        if (ItemCancellation::is_paid($order)) {
            return '';
        }

        if ($order->is_cash_on_pickup()) {
            return sprintf(
                /* translators: %s: new amount */
                __('Please pay only the new amount of %s euros in cash when you pick up your order.', 'kontor-shop'),
                number_format($order->total, 2, ',', '.')
            );
        }

        return $order->payment_method === PaymentMethod::PREPAYMENT
            ? sprintf(
                /* translators: 1: amount, 2: payment reference */
                __('Please transfer only the new amount of %1$s euros (payment reference: %2$s). If you\'ve already transferred more, we\'ll refund the difference.', 'kontor-shop'),
                number_format($order->total, 2, ',', '.'),
                $order->payment_reference
            )
            /* translators: %s: new invoice amount */
            : sprintf(__('The new invoice amount is %s euros.', 'kontor-shop'), number_format($order->total, 2, ',', '.'));
    }

    /**
     * @param array<int, OrderItem> $items
     */
    private function item_lines(array $items): string
    {
        return implode("\n", array_map(
            /* translators: 1: quantity, 2: product, 3: amount */
            static fn (OrderItem $item): string => sprintf(__('- %1$dx %2$s (%3$s euros)', 'kontor-shop'), $item->quantity, $item->product_name, number_format($item->line_total, 2, ',', '.')),
            $items
        ));
    }

    /**
     * Empfaenger einer internen Mail mit Standard = Shop-Betreiber.
     *
     * @return array<int, string>
     */
    public function internal_recipients(string $event): array
    {
        $config = $this->recipient_config($event);

        return Recipients::merge($config['default'] ? $this->operator_emails() : [], $this->extra_recipients($event));
    }

    public function send_payment_confirmed(Order $order): void
    {
        // Auf Rechnung und bar bei Abholung hat das Lager den Auftrag schon mit der Bestellung bekommen.
        if ($order->pays_on_handover()) {
            return;
        }

        $this->send_ship_order($order, DefaultTemplates::PAYMENT_CONFIRMED);
    }

    /**
     * Auf Rechnung: Versandauftrag ans Lager direkt mit der Bestellung - es
     * wird nicht auf die Zahlung gewartet.
     */
    public function send_invoice_ship_order(Order $order): void
    {
        if ($order->pays_on_handover()) {
            $this->send_ship_order($order, DefaultTemplates::INVOICE_SHIP_ORDER);
        }
    }

    /**
     * Nichts zu bezahlen (0 €, beim Bestellen schon als bezahlt gebucht):
     * Versandauftrag ans Lager sofort. Keine "Zahlung eingegangen"-Mail an den
     * Kunden - die Bestaetigung sagt schon, dass nichts zu bezahlen ist.
     */
    public function send_free_ship_order(Order $order): void
    {
        if (!$order->pays_on_handover() && $order->status === OrderStatus::PAID && $order->nothing_to_pay()) {
            $this->send_ship_order($order, DefaultTemplates::PAYMENT_CONFIRMED);
        }
    }

    /**
     * Je Lager eine Mail mit dessen Artikeln als Auftrag zum Versenden/
     * Bereitlegen. Empfaenger fuer beide Anlaesse wie beim "Versandauftrag
     * ans Lager" eingestellt (PAYMENT_CONFIRMED): der Standard-Empfaenger
     * (ohne Erweiterung der Shop-Betreiber, mit Pro der Lagerverwalter des
     * Lagers) und die weiteren Empfaenger. Bleibt bei eingeschaltetem
     * Standard-Empfaenger niemand uebrig, geht der Auftrag an den Betreiber -
     * er darf nie verloren gehen.
     */
    private function send_ship_order(Order $order, string $event): void
    {
        if (!$this->is_enabled($event)) {
            return;
        }

        $items        = $this->orders->items_for_order((int) $order->id);
        $by_warehouse = [];

        foreach ($items as $item) {
            $by_warehouse[$item->warehouse_id][] = $item;
        }

        $delivery_info = $order->self_pickup
            ? ($order->is_cash_on_pickup() && !$order->nothing_to_pay()
                ? sprintf(
                    /* translators: %s: amount */
                    __('Self pickup – the customer pays %s € in cash on pickup', 'kontor-shop'),
                    number_format_i18n($order->total, 2)
                )
                : __('Self pickup', 'kontor-shop'))
            : __('Delivery address:', 'kontor-shop') . "\n" . (string) $order->delivery_address;
        $config        = $this->recipient_config(DefaultTemplates::PAYMENT_CONFIRMED);
        $copies        = $this->extra_recipients(DefaultTemplates::PAYMENT_CONFIRMED);
        $order_url     = admin_url('admin.php?page=kontor-shop-orders&order=' . (int) $order->id);

        /**
         * Filter kontor_shop/orders/pack_url: Link auf die Packansicht (siehe
         * Admin\OrdersPage::pack_url()); hier fuer {{pack_url}} im Versandauftrag.
         *
         * @param string               $url  '' = keine Packansicht
         * @param array<string, mixed> $args ['pack' => Bestell-ID]
         */
        $pack_url = (string) apply_filters('kontor_shop/orders/pack_url', '', ['pack' => (int) $order->id]);

        // Je Lager eine Mail mit dessen Artikeln: an den Standard-Empfaenger und in Kopie an die weiteren Empfaenger.
        foreach ($by_warehouse as $warehouse_id => $warehouse_items) {
            $warehouse = $this->warehouses->find((int) $warehouse_id);
            $primary   = $config['default'] ? $this->ship_order_recipients($warehouse, $order) : [];

            if ($config['default'] && $primary === [] && $copies === []) {
                $primary = array_fill_keys($this->operator_emails(), '');
            }

            $items_lines = [];

            foreach ($warehouse_items as $item) {
                $items_lines[] = sprintf('- %dx %s', $item->quantity, $item->product_name);
            }

            $names  = array_values(array_filter($primary));
            $values = [
                'shop_name'              => $this->shop_name(),
                'warehouse_manager_name' => count($names) === 1 ? $names[0] : __('Warehouse team', 'kontor-shop'),
                'warehouse_name'         => $warehouse?->name ?? '',
                'order_number'           => $order->order_number,
                'items'                  => implode("\n", $items_lines),
                'delivery_info'          => $delivery_info,
                // Ohne Packansicht (Free) fuehrt ein {{pack_url}} in eigenen Vorlagen auf die Bestellung.
                'pack_url'               => $pack_url !== '' ? $pack_url : $order_url,
            ];

            foreach ($primary as $email => $name) {
                $this->send_templated($event, (string) $email, (string) $name, $values);
            }

            foreach (Recipients::without($copies, array_map('strval', array_keys($primary))) as $copy) {
                $this->send_templated($event, $copy, '', $values);
            }
        }
    }

    /**
     * Standard-Empfaenger des Versandauftrags fuer ein Lager.
     *
     * @return array<string, string> E-Mail => Name
     */
    private function ship_order_recipients(?Warehouse $warehouse, Order $order): array
    {
        $operator = array_fill_keys($this->operator_emails(), '');

        /**
         * Filter kontor_shop/notifications/ship_order_recipients:
         * Hauptempfaenger des Versandauftrags fuer ein Lager, z. B. Pro: der
         * Lagerverwalter (leer = nur die Kopie-Empfaenger; ist auch dort
         * niemand, geht er an den Shop-Betreiber). Mit Zuhoerer zeigt der
         * Vorlagen-Editor die weiteren Empfaenger als "Kopie". Standard: die
         * Adresse(n) des Shop-Betreibers.
         *
         * @param array<string, string> $recipients E-Mail => Name (fuer die Anrede, darf leer sein)
         * @param Warehouse|null        $warehouse
         * @param Order                 $order
         */
        $recipients = apply_filters('kontor_shop/notifications/ship_order_recipients', $operator, $warehouse, $order);
        $out        = [];

        foreach (is_array($recipients) ? $recipients : [] as $email => $name) {
            if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $out[$email] = (string) $name;
            }
        }

        return $out;
    }

    /**
     * Empfaenger-Einstellung einer internen Mail (Recipients::configurable()).
     *
     * @return array{default: bool, users: array<int, int>, extra: string}
     */
    public function recipient_config(string $event): array
    {
        $raw = $this->settings->get("notifications.templates.{$event}.recipients", []);
        $raw = is_array($raw) ? $raw : [];

        return [
            'default' => (bool) ($raw['default'] ?? true),
            'users'   => array_values(array_filter(array_map('intval', (array) ($raw['users'] ?? [])))),
            'extra'   => (string) ($raw['extra'] ?? ''),
        ];
    }

    /**
     * Ausgewaehlte Benutzer + zusaetzliche Adressen (ohne Standard-Empfaenger).
     *
     * @return array<int, string>
     */
    public function extra_recipients(string $event): array
    {
        $config = $this->recipient_config($event);
        $users  = [];

        foreach ($config['users'] as $user_id) {
            $user = get_userdata($user_id);

            if ($user) {
                $users[] = (string) $user->user_email;
            }
        }

        return Recipients::merge($users, Recipients::parse_list($config['extra']));
    }

    /**
     * Adresse(n) des Shop-Betreibers: Einstellung, sonst die WordPress-Admin-Adresse.
     *
     * @return array<int, string>
     */
    public function operator_emails(): array
    {
        $list = Recipients::parse_list((string) $this->settings->get('notifications.admin_notify_email', ''));

        return $list !== [] ? $list : Recipients::merge([(string) get_option('admin_email')]);
    }

    /**
     * Alle Empfaenger der Mail "Neue Bestellung".
     *
     * @return array<int, string>
     */
    public function new_order_recipients(): array
    {
        return $this->internal_recipients(DefaultTemplates::NEW_ORDER);
    }

    /**
     * Versandmail je Sendung: welche Artikel in diesem Paket sind, Link zur
     * Sendungsverfolgung und - bei Teilversand - dass der Rest nachkommt.
     * Ohne Sendung (alte Aufrufe): Mail fuer die ganze Bestellung.
     */
    public function send_shipped_confirmation(Order $order, ?Shipment $shipment = null): void
    {
        if (!$this->is_enabled(DefaultTemplates::SHIPPED)) {
            return;
        }

        $all_items = $this->orders->items_for_order((int) $order->id);
        $items     = $shipment !== null
            ? array_values(array_filter($all_items, static fn (OrderItem $item): bool => $item->shipment_id === $shipment->id))
            : $all_items;
        $open      = (new Fulfilment($order, $all_items))->open_items();
        $pickup    = $order->self_pickup;

        $tracking_info = '';

        if ($shipment !== null && !$pickup) {
            $carrier       = Carrier::label($shipment->carrier);
            $url           = $shipment->tracking_url();
            $tracking_info = trim(
                /* translators: %s: carrier name */
                ($carrier !== '' ? sprintf(__('Shipped with %s', 'kontor-shop'), $carrier) : '')
                /* translators: %s: tracking number */
                . ($shipment->tracking_number !== null ? "\n" . sprintf(__('Tracking number: %s', 'kontor-shop'), $shipment->tracking_number) : '')
                . ($url !== '' ? "\n" . __('Tracking:', 'kontor-shop') . ' ' . $url : '')
            );
        }

        $this->send_templated(DefaultTemplates::SHIPPED, $order->customer_email, $order->customer_name, [
            'shop_name'      => $this->shop_name(),
            'customer_name'  => $order->customer_name,
            'order_number'   => $order->order_number,
            'order_status_url' => OrderStatusLink::url($order, $this->settings),
            'items'          => implode("\n", array_map(static fn (OrderItem $item): string => sprintf('- %dx %s', $item->quantity, $item->product_name), $items)),
            'tracking_info'  => $tracking_info,
            'remaining_note' => $open !== []
                ? sprintf(
                    /* translators: %s: list of open products */
                    __('Not included yet (coming separately): %s', 'kontor-shop'),
                    implode(', ', array_map(static fn (OrderItem $item): string => $item->quantity . 'x ' . $item->product_name, $open))
                )
                : '',
            'shipping_note'  => $pickup
                ? __('Your order has been picked up – enjoy!', 'kontor-shop')
                : ($open !== [] ? __('Part of your order is on its way to you.', 'kontor-shop') : __('Your order is on its way to you.', 'kontor-shop')),
            'payment_note'   => $this->shipped_payment_note($order, $open !== []),
        ]);
    }

    /**
     * "Abholbereit": Kunde einer Abhol-Bestellung erfaehrt, was bereitliegt
     * und wo (Name und Adresse der Lager mit offenen Artikeln). Bisher kam bei
     * Abholung erst nach der Uebergabe eine Mail. Verlaufseintrag als Nachweis.
     */
    public function send_ready_for_pickup(Order $order, ?int $user_id): bool
    {
        if (!$order->self_pickup || !$this->is_enabled(DefaultTemplates::READY_FOR_PICKUP)) {
            return false;
        }

        $open = (new Fulfilment($order, $this->orders->items_for_order((int) $order->id)))->open_items();

        if ($open === []) {
            return false;
        }

        $places = [];

        foreach (array_unique(array_map(static fn (OrderItem $item): int => $item->warehouse_id, $open)) as $warehouse_id) {
            $warehouse = $this->warehouses->find($warehouse_id);

            if ($warehouse !== null) {
                $places[] = trim($warehouse->name . ($warehouse->address !== null && trim($warehouse->address) !== '' ? "\n" . trim($warehouse->address) : ''));
            }
        }

        $this->send_templated(DefaultTemplates::READY_FOR_PICKUP, $order->customer_email, $order->customer_name, [
            'shop_name'        => $this->shop_name(),
            'customer_name'    => $order->customer_name,
            'order_number'     => $order->order_number,
            'order_status_url' => OrderStatusLink::url($order, $this->settings),
            'items'            => implode("\n", array_map(static fn (OrderItem $item): string => sprintf('- %dx %s', $item->quantity, $item->product_name), $open)),
            'pickup_info'      => $places !== [] ? __('Pickup at:', 'kontor-shop') . ' ' . implode("\n\n", $places) : '',
            'payment_note'     => $order->awaits_payment()
                ? sprintf(
                    /* translators: %s: open amount */
                    __('Still to pay: %s €.', 'kontor-shop'),
                    number_format_i18n($order->total, 2)
                )
                : '',
        ]);

        $this->orders->add_log_note((int) $order->id, $user_id, sprintf(
            /* translators: %s: email address */
            __('“Ready for pickup” sent to %s.', 'kontor-shop'),
            $order->customer_email
        ));

        return true;
    }

    /**
     * Auf Rechnung: mit der (letzten) Sendung die Zahlungsaufforderung samt
     * Faelligkeit und Bankdaten. Vorkasse/bezahlt: leer.
     */
    private function shipped_payment_note(Order $order, bool $more_to_come): string
    {
        if (!$order->is_invoice() || !$order->awaits_payment()) {
            return '';
        }

        if ($more_to_come || !$order->is_dispatched()) {
            return __('You pay on invoice once the whole order has arrived – you\'ll get the payment details with the last shipment.', 'kontor-shop');
        }

        [$details] = $this->payment_details($order);

        return __('Payment (on invoice):', 'kontor-shop') . "\n" . $details;
    }

    /**
     * Vorlage an einen Empfaenger schicken - fuer Mails von Erweiterungen
     * (Ereignisse aus kontor_shop/notifications/events, z. B.
     * Verfuegbarkeitsanfragen oder die letzte Zahlungserinnerung). Ob die
     * Mail eingeschaltet ist (is_enabled), prueft der Aufrufer.
     *
     * @param array<string, string> $values
     */
    public function send_event(string $event, string $to, string $to_name, array $values): void
    {
        $this->send_templated($event, $to, $to_name, $values + ['shop_name' => $this->shop_name()]);
    }

    /**
     * @param array<string, string> $values
     */
    private function send_templated(string $event, string $to, string $to_name, array $values): void
    {
        $template = $this->template($event);

        $this->send_rendered($event, $to, $to_name, $template['subject'], $template['body'], $values);
    }

    /**
     * Testmail aus dem Vorlagen-Editor: aktueller (gespeicherter) Text mit Beispielwerten.
     */
    public function send_test(string $event, string $to): void
    {
        $template = $this->template($event);

        $this->send_rendered(
            $event,
            $to,
            '',
            '[' . __('Test', 'kontor-shop') . '] ' . $template['subject'],
            $template['body'],
            DefaultTemplates::sample_values($this->shop_name())
        );
    }

    /**
     * @param array<string, string> $values
     */
    private function send_rendered(string $event, string $to, string $to_name, string $subject_template, string $body_template, array $values): void
    {
        $subject = Template::render($subject_template, $values);
        $content = Template::render_html($body_template, $values);
        $text    = Template::html_to_text($content);
        $accent  = (string) $this->settings->get('design.primary_color', '');
        $footer  = esc_html($this->shop_name()) . ' · ' . esc_html(home_url());

        /**
         * Filter kontor_shop/notifications/footer: Zusatz in der Fusszeile
         * jeder Shop-Mail - html unter Shopname und Adresse (fertiges,
         * escaptes HTML), text am Ende der Klartext-Fassung. Z. B. Free nach
         * Zustimmung: "Vereinsshop mit Kontor Shop" (Notifications\PoweredBy).
         * Standard: leer.
         *
         * @param array{html: string, text: string} $extra
         * @param string                            $event Mail-Ereignis (DefaultTemplates::*)
         */
        $extra = (array) apply_filters('kontor_shop/notifications/footer', ['html' => '', 'text' => ''], $event);

        if ((string) ($extra['html'] ?? '') !== '') {
            $footer .= '<br />' . (string) $extra['html'];
        }

        if ((string) ($extra['text'] ?? '') !== '') {
            $text = rtrim($text) . "\n\n-- \n" . (string) $extra['text'];
        }

        $accent  = $accent !== '' ? $accent : ThemePalette::suggest_primary_color();

        $mailer = MailerFactory::delivery((string) $this->settings->get('notifications.mailer', MailerFactory::WP_MAILER), $this->settings);
        $mailer->send(new MailMessage(
            $to,
            $subject,
            $text,
            $to_name,
            MailLayout::wrap($content, $this->shop_name(), (string) $accent, $footer),
            (string) $this->settings->get('notifications.from_email', ''),
            (string) $this->settings->get('notifications.from_name', ''),
            (string) $this->settings->get('notifications.reply_to', ''),
            $content,
            $event,
            DefaultTemplates::labels()[$event] ?? '',
            DefaultTemplates::is_customer_mail($event) ? 'customer' : 'internal',
            (string) ($values['order_number'] ?? '')
        ));
    }

    public function shop_name(): string
    {
        $name = (string) $this->settings->get('general.shop_name', '');

        return $name !== '' ? $name : get_bloginfo('name');
    }
}
