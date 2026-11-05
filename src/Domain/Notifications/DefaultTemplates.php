<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Notifications;

/**
 * Eingebaute Standard-Mailvorlagen fuer alle Mail-Ereignisse (Abschnitt 9).
 * Werden nur verwendet, solange die jeweilige Vorlage nicht ueber
 * Admin\TemplatesPage ueberschrieben wurde (Settings-Ebenen,
 * Settings\Repository). Texte sind HTML (Editor in den Einstellungen).
 * Erweiterungen (Pro) bringen eigene Ereignisse ueber den Filter
 * kontor_shop/notifications/events mit (siehe definitions()).
 */
final class DefaultTemplates
{
    public const ORDER_CONFIRMATION = 'order_confirmation';
    public const NEW_ORDER          = 'new_order';
    public const PAYMENT_RECEIVED   = 'payment_received';
    public const PAYMENT_CONFIRMED  = 'payment_confirmed';
    public const PAYMENT_REMINDER   = 'payment_reminder';
    public const INVOICE_REMINDER   = 'invoice_reminder';
    public const INVOICE_SHIP_ORDER = 'invoice_ship_order';
    public const SHIPPED            = 'shipped';
    public const READY_FOR_PICKUP   = 'ready_for_pickup';
    public const INVOICE            = 'invoice';
    public const ORDER_CANCELLED    = 'order_cancelled';
    public const ITEMS_CANCELLED    = 'items_cancelled';
    public const RETURN_RECEIVED    = 'return_received';
    public const CANCELLATION_INTERNAL = 'cancellation_internal';

    /**
     * Alle Mail-Ereignisse mit Bezeichnung, Erklaertext, Platzhaltern,
     * Standardtext und Beispielwerten - die eingebauten plus die von
     * Erweiterungen.
     *
     * @return array<string, array{label: string, description: string, customer: bool, placeholders: array<int, string>, subject: string, body: string, sample: array<string, string>, configurable_recipients: bool}>
     */
    public static function definitions(): array
    {
        $labels       = self::core_labels();
        $descriptions = self::core_descriptions();
        $placeholders = self::core_placeholders();
        $core         = [];

        foreach (self::core_templates() as $event => $template) {
            $core[$event] = [
                'label'                   => $labels[$event],
                'description'             => $descriptions[$event],
                'customer'                => in_array($event, [
                    self::ORDER_CONFIRMATION, self::PAYMENT_REMINDER, self::INVOICE_REMINDER, self::PAYMENT_RECEIVED, self::SHIPPED, self::READY_FOR_PICKUP, self::INVOICE, self::ORDER_CANCELLED, self::ITEMS_CANCELLED, self::RETURN_RECEIVED,
                ], true),
                'placeholders'            => $placeholders[$event],
                'subject'                 => $template['subject'],
                'body'                    => $template['body'],
                'sample'                  => [],
                'configurable_recipients' => in_array($event, [self::NEW_ORDER, self::PAYMENT_CONFIRMED, self::CANCELLATION_INTERNAL], true),
            ];
        }

        /**
         * Filter kontor_shop/notifications/events: weitere Mail-Ereignisse
         * (z. B. Verfuegbarkeitsanfragen, letzte Zahlungserinnerung) oder
         * Aenderungen an eingebauten (z. B. ein anderer Erklaertext). Die
         * Reihenfolge ist die im Vorlagen-Editor. Standard: nur die
         * eingebauten. Faellt eine Erweiterung weg, verschwinden ihre
         * Ereignisse aus dem Editor; gespeicherte Texte bleiben in den
         * Einstellungen (notifications.templates.<event>).
         *
         * @param array<string, array{label: string, description?: string, customer?: bool, placeholders?: array<int, string>, subject: string, body: string, sample?: array<string, string>, configurable_recipients?: bool}> $events
         *        je Ereignis: Bezeichnung, Erklaertext, Mail an Kunden (sonst intern),
         *        erlaubte Platzhalter, Standard-Betreff/-Text (HTML), Beispielwerte
         *        fuer eigene Platzhalter (Testmail), Empfaenger im Editor einstellbar
         */
        $filtered    = apply_filters('kontor_shop/notifications/events', $core);
        $definitions = [];

        foreach (is_array($filtered) ? $filtered : $core as $event => $definition) {
            if (!is_string($event) || $event === '' || !is_array($definition)) {
                continue;
            }

            $definitions[$event] = [
                'label'                   => (string) ($definition['label'] ?? $event),
                'description'             => (string) ($definition['description'] ?? ''),
                'customer'                => (bool) ($definition['customer'] ?? false),
                'placeholders'            => array_values(array_map('strval', (array) ($definition['placeholders'] ?? []))),
                'subject'                 => (string) ($definition['subject'] ?? ''),
                'body'                    => (string) ($definition['body'] ?? ''),
                'sample'                  => array_map('strval', (array) ($definition['sample'] ?? [])),
                'configurable_recipients' => (bool) ($definition['configurable_recipients'] ?? false),
            ];
        }

        return $definitions;
    }

    /**
     * @return array<int, string>
     */
    public static function events(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return array_map(static fn (array $d): string => $d['label'], self::definitions());
    }

    /**
     * Wer bekommt die Mail und wann - Erklaertext im Vorlagen-Editor.
     *
     * @return array<string, string>
     */
    public static function descriptions(): array
    {
        return array_map(static fn (array $d): string => $d['description'], self::definitions());
    }

    /**
     * Mails an Kunden (Rest: intern) - fuer Hinweise im Editor.
     */
    public static function is_customer_mail(string $event): bool
    {
        return self::definitions()[$event]['customer'] ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function placeholders(): array
    {
        return array_map(static fn (array $d): array => $d['placeholders'], self::definitions());
    }

    /**
     * @return array<string, array{subject: string, body: string}>
     */
    public static function all(): array
    {
        return array_map(static fn (array $d): array => ['subject' => $d['subject'], 'body' => $d['body']], self::definitions());
    }

    /**
     * @return array<string, string>
     */
    private static function core_labels(): array
    {
        return [
            self::ORDER_CONFIRMATION => __('Order confirmation', 'kontor-shop'),
            self::NEW_ORDER          => __('New order', 'kontor-shop'),
            self::PAYMENT_REMINDER   => __('Payment reminder', 'kontor-shop'),
            self::INVOICE_REMINDER   => __('Invoice payment reminder', 'kontor-shop'),
            self::PAYMENT_RECEIVED   => __('Payment received', 'kontor-shop'),
            self::PAYMENT_CONFIRMED  => __('Shipping order to warehouse', 'kontor-shop'),
            self::INVOICE_SHIP_ORDER => __('Shipping order to warehouse (pay later)', 'kontor-shop'),
            self::SHIPPED            => __('Shipped / picked up', 'kontor-shop'),
            self::READY_FOR_PICKUP   => __('Ready for pickup', 'kontor-shop'),
            self::INVOICE            => __('Invoice / credit note', 'kontor-shop'),
            self::ORDER_CANCELLED    => __('Order cancelled', 'kontor-shop'),
            self::ITEMS_CANCELLED    => __('Product cancelled', 'kontor-shop'),
            self::RETURN_RECEIVED    => __('Return received', 'kontor-shop'),
            self::CANCELLATION_INTERNAL => __('Cancellation / refund', 'kontor-shop'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function core_descriptions(): array
    {
        return [
            self::ORDER_CONFIRMATION => __('To the customer, right after ordering – with payment details (amount, IBAN, payment reference, deadline).', 'kontor-shop'),
            self::NEW_ORDER          => __('Internal notice for every new order – to the shop owner and/or the recipients selected below.', 'kontor-shop'),
            self::PAYMENT_REMINDER   => __('To the customer when a prepayment order hasn\'t been paid yet – sent with the “Send payment reminder” button in the order. Includes the payment details.', 'kontor-shop'),
            self::INVOICE_REMINDER   => __('To the customer when an order shipped on invoice hasn\'t been paid after the payment due date – sent with the “Send payment reminder” button in the order. Without a cancellation warning (the goods are already with the customer).', 'kontor-shop'),
            self::PAYMENT_RECEIVED   => __('To the customer as soon as an order is marked as “paid” in the backend.', 'kontor-shop'),
            self::PAYMENT_CONFIRMED  => __('As soon as a prepayment order is paid: one email per warehouse with its products as an order to ship/set aside – to the recipients selected below.', 'kontor-shop'),
            self::INVOICE_SHIP_ORDER => __('Immediately for an order on invoice or with cash on pickup (without waiting for payment): one email per warehouse with its products as an order to ship/set aside. Recipients are set as for the “Shipping order to warehouse”.', 'kontor-shop'),
            self::SHIPPED            => __('To the customer as soon as the order is marked as “shipped” or “picked up”.', 'kontor-shop'),
            self::READY_FOR_PICKUP   => __('To the customer of a pickup order when you click “Ready for pickup” in the order – with what is ready and where to collect it (address of the warehouse).', 'kontor-shop'),
            self::INVOICE            => __('To the customer as soon as an invoice or credit note is created (Settings → Invoices) – with the PDF attached and a download link.', 'kontor-shop'),
            self::ORDER_CANCELLED    => __('To the customer when the whole order is cancelled – with a note about the refund.', 'kontor-shop'),
            self::ITEMS_CANCELLED    => __('To the customer when individual products are cancelled (e.g. not deliverable) – with the new amount or refund.', 'kontor-shop'),
            self::RETURN_RECEIVED    => __('To the customer when returned goods are recorded as a return in the backend – with the refund or new invoice amount.', 'kontor-shop'),
            self::CANCELLATION_INTERNAL => __('Internal notice for every cancellation (whole order or individual products) – e.g. to the cashier so a refund gets handled.', 'kontor-shop'),
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private static function core_placeholders(): array
    {
        return [
            self::ORDER_CONFIRMATION => [
                'shop_name', 'customer_name', 'order_number', 'items', 'goods_total', 'discount_line', 'items_total',
                'shipping_cost', 'total', 'payment_method_label', 'payment_details', 'payment_reference', 'payment_due_date', 'order_status_url', 'confirmation_url',
            ],
            self::NEW_ORDER => [
                'shop_name', 'order_number', 'payment_reference', 'customer_name', 'customer_email', 'customer_phone', 'billing_info', 'items',
                'goods_total', 'discount_line', 'items_total', 'shipping_cost', 'total', 'payment_method_label', 'delivery_info', 'orders_url',
            ],
            self::PAYMENT_RECEIVED => [
                'shop_name', 'customer_name', 'order_number', 'total', 'next_step', 'order_status_url',
            ],
            self::PAYMENT_REMINDER => [
                'shop_name', 'customer_name', 'order_number', 'items', 'total', 'payment_details', 'payment_reference', 'payment_due_date', 'order_status_url',
            ],
            self::INVOICE_REMINDER => [
                'shop_name', 'customer_name', 'order_number', 'items', 'total', 'payment_details', 'payment_reference', 'payment_due_date', 'order_status_url',
            ],
            self::PAYMENT_CONFIRMED => [
                'shop_name', 'warehouse_manager_name', 'warehouse_name', 'order_number', 'items', 'delivery_info',
            ],
            self::INVOICE_SHIP_ORDER => [
                'shop_name', 'warehouse_manager_name', 'warehouse_name', 'order_number', 'items', 'delivery_info',
            ],
            self::SHIPPED => [
                'shop_name', 'customer_name', 'order_number', 'shipping_note', 'items', 'tracking_info', 'remaining_note', 'payment_note', 'order_status_url',
            ],
            self::READY_FOR_PICKUP => [
                'shop_name', 'customer_name', 'order_number', 'items', 'pickup_info', 'payment_note', 'order_status_url',
            ],
            self::INVOICE => [
                'shop_name', 'customer_name', 'order_number', 'document_title', 'invoice_number', 'total', 'invoice_url', 'order_status_url',
            ],
            self::ORDER_CANCELLED => [
                'shop_name', 'customer_name', 'order_number', 'total', 'cancel_reason', 'refund_note', 'order_status_url',
            ],
            self::ITEMS_CANCELLED => [
                'shop_name', 'customer_name', 'order_number', 'cancelled_items', 'cancel_reason', 'amount_note', 'order_status_url',
            ],
            self::RETURN_RECEIVED => [
                'shop_name', 'customer_name', 'order_number', 'returned_items', 'return_reason', 'amount_note', 'order_status_url',
            ],
            self::CANCELLATION_INTERNAL => [
                'shop_name', 'order_number', 'customer_name', 'customer_email', 'cancel_scope', 'cancelled_items', 'cancel_reason', 'amount_note', 'order_url',
            ],
        ];
    }

    /**
     * @return array<string, array{subject: string, body: string}>
     */
    private static function core_templates(): array
    {
        return [
            self::ORDER_CONFIRMATION => [
                'subject' => __('Your order {{order_number}} at {{shop_name}}', 'kontor-shop'),
                'body'    => __(
                    '<p>Hi {{customer_name}},</p><p>thank you for your order <strong>{{order_number}}</strong>!</p><h3>Your products</h3><p>{{items}}</p><p>Goods value: {{goods_total}} €{{discount_line}}<br>Shipping: {{shipping_cost}} €<br><strong>Total: {{total}} €</strong></p><h3>Payment: {{payment_method_label}}</h3><p>{{payment_details}}</p><p>View your order status anytime: {{order_status_url}}</p><p>Best regards<br>your {{shop_name}} team</p>',
                    'kontor-shop'
                ),
            ],
            self::NEW_ORDER => [
                'subject' => __('New order {{order_number}} at {{shop_name}}', 'kontor-shop'),
                'body'    => __(
                    '<p>A new order has come in.</p><p><strong>Order number:</strong> {{order_number}}<br><strong>Payment reference:</strong> {{payment_reference}}<br><strong>Customer:</strong> {{customer_name}} ({{customer_email}}, phone: {{customer_phone}})</p><h3>Products</h3><p>{{items}}</p><p>Goods value: {{goods_total}} €{{discount_line}}<br>Shipping: {{shipping_cost}} €<br><strong>Total: {{total}} €</strong><br>Payment method: {{payment_method_label}}</p><p>{{delivery_info}}</p><p>Invoice to: {{billing_info}}</p><p>View orders: {{orders_url}}</p>',
                    'kontor-shop'
                ),
            ],
            self::PAYMENT_RECEIVED => [
                'subject' => __('Payment received: order {{order_number}}', 'kontor-shop'),
                'body'    => __(
                    '<p>Hi {{customer_name}},</p><p>we have received your payment of <strong>{{total}} €</strong> for order <strong>{{order_number}}</strong> – thank you!</p><p>{{next_step}}</p><p>View your order status anytime: {{order_status_url}}</p><p>Best regards<br>your {{shop_name}} team</p>',
                    'kontor-shop'
                ),
            ],
            self::PAYMENT_REMINDER => [
                'subject' => __('Reminder: order {{order_number}} is still open', 'kontor-shop'),
                'body'    => __(
                    '<p>Hi {{customer_name}},</p><p>your order <strong>{{order_number}}</strong> is reserved for you, but we haven’t seen a payment yet. If you’ve already made the transfer, all good – the payment is probably on its way.</p><h3>Your products</h3><p>{{items}}</p><h3>Payment</h3><p>{{payment_details}}</p><p>View your order status anytime: {{order_status_url}}</p><p>If you have any questions, just reply to this email.</p><p>Best regards<br>your {{shop_name}} team</p>',
                    'kontor-shop'
                ),
            ],
            self::INVOICE_REMINDER => [
                'subject' => __('Payment reminder: invoice for order {{order_number}}', 'kontor-shop'),
                'body'    => __(
                    '<p>Hi {{customer_name}},</p><p>we hope your order <strong>{{order_number}}</strong> arrived safely. The invoice of <strong>{{total}} €</strong> was due on {{payment_due_date}} – we haven’t seen a payment so far. If you’ve already made the transfer, all good – the payment is probably on its way.</p><h3>Payment</h3><p>{{payment_details}}</p><h3>Your products</h3><p>{{items}}</p><p>View your order status anytime: {{order_status_url}}</p><p>If you have any questions, just reply to this email.</p><p>Best regards<br>your {{shop_name}} team</p>',
                    'kontor-shop'
                ),
            ],
            self::PAYMENT_CONFIRMED => [
                'subject' => __('Payment confirmed: order {{order_number}}', 'kontor-shop'),
                'body'    => __(
                    '<p>Hi {{warehouse_manager_name}},</p><p>payment for order <strong>{{order_number}}</strong> is confirmed. Please prepare it for shipping/pickup:</p><h3>Products</h3><p>{{items}}</p><p>{{delivery_info}}</p><p>{{shop_name}}</p>',
                    'kontor-shop'
                ),
            ],
            self::INVOICE_SHIP_ORDER => [
                'subject' => __('Shipping request (pay later): order {{order_number}}', 'kontor-shop'),
                'body'    => __(
                    '<p>Hi {{warehouse_manager_name}},</p><p>order <strong>{{order_number}}</strong> is paid later (on invoice or in cash on pickup) – please prepare it right away without waiting for payment:</p><h3>Products</h3><p>{{items}}</p><p>{{delivery_info}}</p><p>{{shop_name}}</p>',
                    'kontor-shop'
                ),
            ],
            self::SHIPPED => [
                'subject' => __('Your order {{order_number}} is on its way', 'kontor-shop'),
                'body'    => __(
                    '<p>Hi {{customer_name}},</p><p>good news about your order <strong>{{order_number}}</strong>: {{shipping_note}}</p><h3>In this package</h3><p>{{items}}</p><p>{{tracking_info}}</p><p>{{remaining_note}}</p><p>{{payment_note}}</p><p>View your order status anytime: {{order_status_url}}</p><p>Best regards<br>your {{shop_name}} team</p>',
                    'kontor-shop'
                ),
            ],
            self::READY_FOR_PICKUP => [
                'subject' => __('Your order {{order_number}} is ready for pickup', 'kontor-shop'),
                'body'    => __(
                    '<p>Hi {{customer_name}},</p><p>your order <strong>{{order_number}}</strong> is ready for pickup.</p><h3>Ready for you</h3><p>{{items}}</p><p>{{pickup_info}}</p><p>{{payment_note}}</p><p>View your order status anytime: {{order_status_url}}</p><p>Best regards<br>your {{shop_name}} team</p>',
                    'kontor-shop'
                ),
            ],
            self::INVOICE => [
                'subject' => __('{{document_title}} {{invoice_number}} for your order {{order_number}}', 'kontor-shop'),
                'body'    => __(
                    '<p>Hi {{customer_name}},</p><p>attached is the {{document_title}} <strong>{{invoice_number}}</strong> for your order {{order_number}} ({{total}} €).</p><p>You can also download it here: {{invoice_url}}</p><p>Best regards<br>your {{shop_name}} team</p>',
                    'kontor-shop'
                ),
            ],
            self::ORDER_CANCELLED => [
                'subject' => __('Your order {{order_number}} has been cancelled', 'kontor-shop'),
                'body'    => __(
                    '<p>Hi {{customer_name}},</p><p>your order <strong>{{order_number}}</strong> for {{total}} € has been cancelled.</p><p>{{cancel_reason}}</p><p>{{refund_note}}</p><p>View your order status anytime: {{order_status_url}}</p><p>If you have any questions, just reply to this email.</p><p>Best regards<br>your {{shop_name}} team</p>',
                    'kontor-shop'
                ),
            ],
            self::ITEMS_CANCELLED => [
                'subject' => __('Change to your order {{order_number}}', 'kontor-shop'),
                'body'    => __(
                    '<p>Hi {{customer_name}},</p><p>unfortunately we can’t deliver the following products from your order <strong>{{order_number}}</strong> – they have been cancelled:</p><p>{{cancelled_items}}</p><p>{{cancel_reason}}</p><p>{{amount_note}}</p><p>The rest of your order remains unchanged. If you have any questions, just reply to this email.</p><p>View your order status anytime: {{order_status_url}}</p><p>Best regards<br>your {{shop_name}} team</p>',
                    'kontor-shop'
                ),
            ],
            self::RETURN_RECEIVED => [
                'subject' => __('Return for your order {{order_number}} has arrived', 'kontor-shop'),
                'body'    => __(
                    '<p>Hi {{customer_name}},</p><p>your return for order <strong>{{order_number}}</strong> has arrived:</p><p>{{returned_items}}</p><p>{{return_reason}}</p><p>{{amount_note}}</p><p>View your order status anytime: {{order_status_url}}</p><p>If you have any questions, just reply to this email.</p><p>Best regards<br>your {{shop_name}} team</p>',
                    'kontor-shop'
                ),
            ],
            self::CANCELLATION_INTERNAL => [
                'subject' => __('Cancellation on order {{order_number}}', 'kontor-shop'),
                'body'    => __(
                    '<p><strong>{{cancel_scope}}</strong></p><p><strong>Order:</strong> {{order_number}}<br><strong>Customer:</strong> {{customer_name}} ({{customer_email}})</p><h3>Cancelled products</h3><p>{{cancelled_items}}</p><p><strong>Reason:</strong> {{cancel_reason}}</p><p><strong>{{amount_note}}</strong></p><p>View order: {{order_url}}</p>',
                    'kontor-shop'
                ),
            ],
        ];
    }

    /**
     * Fruehere Nur-Text-Standardtexte. Eine gespeicherte Vorlage, die exakt
     * so lautet, wurde nie angepasst und wird durch die neue HTML-Vorlage
     * ersetzt (siehe Modules\Notifications\NotificationsModule::template()).
     *
     * @return array<string, array<int, string>>
     */
    public static function legacy_bodies(): array
    {
        $legacy = self::legacy_plain_bodies();

        // Fruehere HTML-Standardtexte (wurden bei Updates automatisch in den Einstellungen gespeichert).
        foreach (self::previous_html_bodies() as [$event, $body]) {
            $legacy[$event][] = $body;
        }

        return $legacy;
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private static function previous_html_bodies(): array
    {
        return [
            [self::ORDER_CONFIRMATION, '<p>Hallo {{customer_name}},</p><p>vielen Dank für deine Bestellung <strong>{{order_number}}</strong>!</p><h3>Deine Artikel</h3><p>{{items}}</p><p>Warenwert: {{items_total}} €<br>Versand: {{shipping_cost}} €<br><strong>Gesamtsumme: {{total}} €</strong></p><h3>Zahlung: {{payment_method_label}}</h3><p>{{payment_details}}</p><p>Deine Bestellübersicht (30 Tage abrufbar): {{confirmation_url}}</p><p>Liebe Grüße<br>dein {{shop_name}}-Team</p>'],
            [self::NEW_ORDER, '<p>Es ist eine neue Bestellung eingegangen.</p><p><strong>Bestellnummer:</strong> {{order_number}}<br><strong>Zahlungsreferenz:</strong> {{payment_reference}}<br><strong>Kunde:</strong> {{customer_name}} ({{customer_email}}, Tel.: {{customer_phone}})</p><h3>Artikel</h3><p>{{items}}</p><p>Warenwert: {{items_total}} €<br>Versand: {{shipping_cost}} €<br><strong>Gesamtsumme: {{total}} €</strong><br>Zahlungsart: {{payment_method_label}}</p><p>{{delivery_info}}</p><p>Rechnung an: {{billing_info}}</p><p>Bestellungen ansehen: {{orders_url}}</p>'],
            [self::PAYMENT_RECEIVED, '<p>Hallo {{customer_name}},</p><p>deine Zahlung über <strong>{{total}} €</strong> für die Bestellung <strong>{{order_number}}</strong> ist bei uns eingegangen – vielen Dank!</p><p>{{next_step}}</p><p>Liebe Grüße<br>dein {{shop_name}}-Team</p>'],
            [self::PAYMENT_CONFIRMED, '<p>Hallo {{warehouse_manager_name}},</p><p>für Bestellung <strong>{{order_number}}</strong> ist der Zahlungseingang bestätigt. Bitte für Versand/Abholung vorbereiten:</p><h3>Artikel</h3><p>{{items}}</p><p>{{delivery_info}}</p><p>{{shop_name}}</p>'],
            [self::SHIPPED, '<p>Hallo {{customer_name}},</p><p>gute Nachrichten zu deiner Bestellung <strong>{{order_number}}</strong>: {{shipping_note}}</p><h3>In diesem Paket</h3><p>{{items}}</p><p>{{tracking_info}}</p><p>{{remaining_note}}</p><p>Liebe Grüße<br>dein {{shop_name}}-Team</p>'],
            [self::ORDER_CANCELLED, '<p>Hallo {{customer_name}},</p><p>deine Bestellung <strong>{{order_number}}</strong> über {{total}} € wurde storniert.</p><p>{{cancel_reason}}</p><p>{{refund_note}}</p><p>Bei Fragen antworte einfach auf diese Mail.</p><p>Liebe Grüße<br>dein {{shop_name}}-Team</p>'],
            [self::ITEMS_CANCELLED, '<p>Hallo {{customer_name}},</p><p>leider können wir aus deiner Bestellung <strong>{{order_number}}</strong> folgende Artikel nicht liefern – sie wurden storniert:</p><p>{{cancelled_items}}</p><p>{{cancel_reason}}</p><p>{{amount_note}}</p><p>Der Rest deiner Bestellung bleibt unverändert. Bei Fragen antworte einfach auf diese Mail.</p><p>Liebe Grüße<br>dein {{shop_name}}-Team</p>'],
            [self::CANCELLATION_INTERNAL, '<p><strong>{{cancel_scope}}</strong></p><p><strong>Bestellung:</strong> {{order_number}}<br><strong>Kunde:</strong> {{customer_name}} ({{customer_email}})</p><h3>Stornierte Artikel</h3><p>{{cancelled_items}}</p><p><strong>Grund:</strong> {{cancel_reason}}</p><p><strong>{{amount_note}}</strong></p><p>Bestellung ansehen: {{order_url}}</p>'],
            [self::ORDER_CANCELLED, '<p>Hallo {{customer_name}},</p><p>deine Bestellung <strong>{{order_number}}</strong> über {{total}} € wurde storniert.</p><p>{{refund_note}}</p><p>Bei Fragen antworte einfach auf diese Mail.</p><p>Liebe Grüße<br>dein {{shop_name}}-Team</p>'],
            // vor {{goods_total}}/{{discount_line}} (Rabattcodes)
            [self::ORDER_CONFIRMATION, '<p>Hallo {{customer_name}},</p><p>vielen Dank für deine Bestellung <strong>{{order_number}}</strong>!</p><h3>Deine Artikel</h3><p>{{items}}</p><p>Warenwert: {{items_total}} €<br>Versand: {{shipping_cost}} €<br><strong>Gesamtsumme: {{total}} €</strong></p><h3>Zahlung: {{payment_method_label}}</h3><p>{{payment_details}}</p><p>Status deiner Bestellung jederzeit ansehen: {{order_status_url}}</p><p>Liebe Grüße<br>dein {{shop_name}}-Team</p>'],
            [self::NEW_ORDER, '<p>Es ist eine neue Bestellung eingegangen.</p><p><strong>Bestellnummer:</strong> {{order_number}}<br><strong>Zahlungsreferenz:</strong> {{payment_reference}}<br><strong>Kunde:</strong> {{customer_name}} ({{customer_email}}, Tel.: {{customer_phone}})</p><h3>Artikel</h3><p>{{items}}</p><p>Warenwert: {{items_total}} €<br>Versand: {{shipping_cost}} €<br><strong>Gesamtsumme: {{total}} €</strong><br>Zahlungsart: {{payment_method_label}}</p><p>{{delivery_info}}</p><p>Rechnung an: {{billing_info}}</p><p>Bestellungen ansehen: {{orders_url}}</p>'],
            // vor {{payment_note}} (auf Rechnung)
            [self::SHIPPED, '<p>Hallo {{customer_name}},</p><p>gute Nachrichten zu deiner Bestellung <strong>{{order_number}}</strong>: {{shipping_note}}</p><h3>In diesem Paket</h3><p>{{items}}</p><p>{{tracking_info}}</p><p>{{remaining_note}}</p><p>Status deiner Bestellung jederzeit ansehen: {{order_status_url}}</p><p>Liebe Grüße<br>dein {{shop_name}}-Team</p>'],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private static function legacy_plain_bodies(): array
    {
        return [
            self::ORDER_CONFIRMATION => ["Hallo {{customer_name}},\n\nvielen Dank fuer deine Bestellung {{order_number}}.\n\n"
                . "Artikel:\n{{items}}\n\nWarenwert: {{items_total}} Euro\nVersand: {{shipping_cost}} Euro\n"
                . "Gesamtsumme: {{total}} Euro\n\nZahlungsart: {{payment_method_label}}\n{{payment_details}}\n\n"
                . "Deine Bestelluebersicht (30 Tage abrufbar): {{confirmation_url}}\n\n"
                . 'Dein {{shop_name}}-Team'],
            self::NEW_ORDER => ["Es ist eine neue Bestellung eingegangen.\n\nBestellnummer: {{order_number}}\n"
                . "Zahlungsreferenz: {{payment_reference}}\nKunde: {{customer_name}} ({{customer_email}}, Tel.: {{customer_phone}})\n\n"
                . "Artikel:\n{{items}}\n\nWarenwert: {{items_total}} Euro\nVersand: {{shipping_cost}} Euro\n"
                . "Gesamtsumme: {{total}} Euro\nZahlungsart: {{payment_method_label}}\n\n{{delivery_info}}\n\nRechnung an: {{billing_info}}\n\n"
                . 'Bestellungen ansehen: {{orders_url}}'],
            self::PAYMENT_CONFIRMED => ["Hallo {{warehouse_manager_name}},\n\nfuer Bestellung {{order_number}} ist der Zahlungseingang "
                . "bestaetigt. Bitte fuer Versand/Abholung vorbereiten:\n\nArtikel:\n{{items}}\n\n{{delivery_info}}\n\n"
                . 'Kontor Shop'],
            self::SHIPPED => ["Hallo {{customer_name}},\n\ndeine Bestellung {{order_number}} wurde soeben als versendet/abgeholt "
                . 'markiert.' . "\n\n{{shipping_note}}\n\nDein {{shop_name}}-Team",
                // HTML-Vorlage vor der Einzelsendung (ohne Artikel/Tracking)
                '<p>Hallo {{customer_name}},</p><p>gute Nachrichten zu deiner Bestellung <strong>{{order_number}}</strong>:</p>'
                . '<p>{{shipping_note}}</p><p>Liebe Grüße<br>dein {{shop_name}}-Team</p>',
            ],
        ];
    }

    /**
     * Beispielwerte fuer Testmails aus dem Editor (eingebaute Platzhalter
     * plus die Beispielwerte der Erweiterungs-Ereignisse).
     *
     * @return array<string, string>
     */
    public static function sample_values(string $shop_name): array
    {
        $samples = [];

        foreach (self::definitions() as $definition) {
            $samples += $definition['sample'];
        }

        return [
            'shop_name'              => $shop_name,
            'customer_name'          => 'Max Mustermann',
            'customer_email'         => 'max@example.org',
            'customer_phone'         => '+43 660 1234567',
            'warehouse_manager_name' => 'Erika Lager',
            'warehouse_name'         => 'Jagd',
            'order_number'           => 'B-000123',
            'payment_reference'      => 'KS-7F3K9Q',
            'payment_due_date'       => '08.10.2026',
            'items'                  => "- 1x Beagle-Wackler (23,60 Euro/Stk.)\n- 2x Aufkleber (2,50 Euro/Stk.)",
            'items_total'            => '25,74',
            'goods_total'            => '28,60',
            'discount_line'          => "\nRabatt (SOMMER10): −2,86 €",
            'shipping_cost'          => '5,90',
            'total'                  => '34,50',
            'payment_method_label'   => 'Vorkasse',
            'payment_details'        => "Bitte ueberweise 34,50 Euro bis spaetestens 08.10.2026 an:\nMusterverein\nIBAN: AT00 0000 0000 0000 0000\nBIC: MUSTATWW\nZahlungsreferenz (bitte unbedingt angeben): KS-7F3K9Q",
            'confirmation_url'       => 'https://example.org/kasse/?kontor_order=beispiel',
            'order_status_url'       => 'https://example.org/kasse/?kontor_order=beispiel',
            'orders_url'             => 'https://example.org/wp-admin/admin.php?page=kontor-shop-orders',
            'billing_info'           => 'wie Lieferadresse',
            'delivery_info'          => "Lieferadresse:\nMax Mustermann\nMusterstraße 1\n1010 Wien",
            'next_step'              => 'Wir verschicken deine Bestellung in den nächsten Tagen – du bekommst dann noch eine Versandbestätigung.',
            'shipping_note'          => 'Ein Teil deiner Bestellung ist unterwegs zu dir.',
            'tracking_info'          => "Versand mit Österreichische Post\nSendungsnummer: 1234567890\nSendungsverfolgung: https://www.post.at/s/sendungsdetails?snr=1234567890",
            'remaining_note'         => 'Noch nicht dabei (kommt separat): 1x Beagle-Mütze',
            'payment_note'           => "Zahlung (auf Rechnung):\nBitte ueberweise 34,50 Euro bis spaetestens 22.10.2026 an:\nMusterverein\nIBAN: AT00 0000 0000 0000 0000\nBIC: MUSTATWW\nZahlungsreferenz (bitte unbedingt angeben): KS-7F3K9Q",
            'refund_note'            => 'Den Betrag von 34,50 Euro überweisen wir dir in den nächsten Tagen auf dein Konto zurück.',
            'cancelled_items'        => '- 1x Beagle-Mütze (12,00 Euro)',
            'cancel_reason'          => 'Grund: leider ausverkauft',
            'returned_items'         => '- 1x Beagle-Mütze (12,00 Euro)',
            'return_reason'          => 'Anmerkung: passt leider nicht',
            'cancel_scope'           => 'Einzelne Artikel wurden storniert, der Rest der Bestellung bleibt bestehen.',
            'amount_note'            => 'Den Betrag von 12,00 Euro überweisen wir dir in den nächsten Tagen auf dein Konto zurück.',
            'order_url'              => 'https://example.org/wp-admin/admin.php?page=kontor-shop-orders&order=123',
            'pack_url'               => 'https://example.org/wp-admin/admin.php?page=kontor-shop-orders&pack=123',
            'pickup_info'            => "Abholung bei: Lager Jagd\nMusterstraße 5, 1010 Wien",
            'document_title'         => 'Rechnung',
            'invoice_number'         => '2026-0001',
            'invoice_url'            => 'https://example.org/kasse/?kontor_order=beispiel&kontor_invoice=1',
        ] + $samples;
    }
}
