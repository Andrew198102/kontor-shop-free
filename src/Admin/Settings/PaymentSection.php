<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin\Settings;

use Kontor\Shop\Settings\TextDefaults;
use Kontor\Shop\Domain\Order\PaymentDeadline;
use Kontor\Shop\Domain\Order\PaymentTerms;
use Kontor\Shop\Domain\Payment\BankAccount;
use Kontor\Shop\Domain\Stock\Availability;
use Kontor\Shop\Domain\Tax\TaxCalculator;
use Kontor\Shop\Infrastructure\Payment\BankAccounts;
use Kontor\Shop\Infrastructure\Repositories\OrderRepository;
use Kontor\Shop\Infrastructure\Repositories\WarehouseRepository;
use Kontor\Shop\Modules\Payments\PaymentOptions;
use Kontor\Shop\Support\Iban;

/**
 * Bankkonten, Zahlungsarten, Reservierung/Zahlungsfrist, Zahlungsziel bei Rechnung und Umsatzsteuer.
 * Erweiterungen (Pro: automatische Erinnerungen, Autostorno) haengen eigene Zeilen ueber
 * kontor_shop/settings/payment/fields ein und speichern sie ueber kontor_shop/settings/payment/save.
 *
 * Konten mit gesetzter Quelle (BankAccount::$source, z. B. von einem Add-on abgeglichen) sind im Shop nur lesbar.
 * Wie die Quelle heisst und ob sie noch verbunden ist, liefert der Filter kontor_shop/payment/external_source
 * (siehe external_source()).
 */
final class PaymentSection extends Section
{
    public function key(): string
    {
        return 'payment';
    }

    public function label(): string
    {
        return __('Payment & sales tax', 'kontor-shop');
    }

    public function group(): string
    {
        return self::GROUP_SELL;
    }

    public function handles(): array
    {
        return ['payment', 'tax'];
    }

    public function save(string $tab): ?string
    {
        return match ($tab) {
            'payment' => $this->save_payment(),
            'tax' => $this->save_tax(),
            default => null,
        };
    }

    public function render(): void
    {
        $this->group_title(__('Payment', 'kontor-shop'));
        $this->render_payment_tab();
        $this->group_title(__('VAT', 'kontor-shop'), 'setup-payment');
        $this->render_tax_tab();
    }

    private function save_payment(): ?string
    {
        $prepayment = !empty($_POST['prepayment_enabled']); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
        $invoice    = !empty($_POST['invoice_enabled']); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
        $cash       = !empty($_POST['cash_enabled']); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
        $qr         = !empty($_POST['qr_enabled']); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request

        /**
         * Filter kontor_shop/settings/payment/save: eigene Felder aus
         * kontor_shop/settings/payment/fields mitspeichern (Werte aus $_POST
         * selbst bereinigen; Nonce und Recht sind geprueft). Die eingebauten
         * Schluessel lassen sich nicht ueberschreiben. Standard: nur die
         * eingebauten - ohne Erweiterung bleiben deren gespeicherte Werte
         * unberuehrt.
         *
         * @param array<string, mixed> $values zusaetzliche Werte unter payment.*
         */
        $extra = apply_filters('kontor_shop/settings/payment/save', []);
        $extra = is_array($extra) ? $extra : [];

        /**
         * Filter kontor_shop/settings/payment/invoice_for_everyone: steht
         * "Auf Rechnung" nach dem Speichern jedem Kaeufer offen? Pro: nein,
         * wenn "nur fuer erkannte Mitglieder" angehakt ist - dann muss
         * Vorkasse an bleiben. Standard: true.
         *
         * @param bool                 $for_everyone
         * @param array<string, mixed> $extra Zusatzwerte aus kontor_shop/settings/payment/save
         */
        $for_everyone = (bool) apply_filters('kontor_shop/settings/payment/invoice_for_everyone', true, $extra);

        // Jeder Kaeufer braucht mindestens eine Zahlungsart - sonst bleibt Vorkasse an.
        if (!PaymentOptions::covers_everyone($prepayment, $invoice, !$for_everyone)) {
            $prepayment       = true;
            $this->warnings[] = $invoice
                ? __('Prepayment stays enabled: “On invoice” is only available to members – everyone else would otherwise have no payment method.', 'kontor-shop')
                : __('Prepayment stays enabled: at least one payment method must be offered.', 'kontor-shop');
        }

        $payment = [
            'accounts'                              => $this->collect_accounts((array) map_deep(wp_unslash($_POST['accounts'] ?? []), 'sanitize_text_field')), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
            'prepayment_enabled'                    => $prepayment,
            'invoice_enabled'                       => $invoice,
            'cash_enabled'                          => $cash,
            'qr_enabled'                            => $qr,
            'invoice_days'                          => PaymentTerms::sanitize_invoice_days(intval($_POST['invoice_days'] ?? PaymentTerms::DEFAULT_INVOICE_DAYS)), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
            // abgeloest durch invoice_enabled (mit Pro: invoice_members_only)
            'allow_invoice_on_delivery_for_members' => false,
            'payment_days'                          => PaymentDeadline::sanitize_days(intval($_POST['payment_days'] ?? PaymentDeadline::DEFAULT_DAYS)), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
        ];

        $this->settings->save(['payment' => $payment + $extra, 'checkout' => [
            'reservation_minutes' => (int) (Availability::ttl_seconds(intval($_POST['reservation_minutes'] ?? Availability::DEFAULT_MINUTES)) / 60), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
        ]]);

        return __('Settings saved.', 'kontor-shop');
    }

    private function save_tax(): ?string
    {
        $rates   = TaxCalculator::parse_rates(sanitize_text_field(wp_unslash($_POST['rates'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
        $default = sanitize_text_field(wp_unslash((string) ($_POST['default_rate'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
        $mode    = sanitize_text_field(wp_unslash((string) ($_POST['shipping_mode'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
        $default = $default !== '' && in_array((float) $default, $rates, true) ? (string) (float) $default : '';

        $this->settings->save(['tax' => [
            'rates'        => $rates,
            'default_rate' => $default,
            'exempt_note'  => sanitize_text_field(wp_unslash($_POST['exempt_note'] ?? '')), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
            'shipping_mode' => in_array($mode, TaxCalculator::shipping_modes(), true)
                ? $mode
                : TaxCalculator::SHIPPING_PROPORTIONAL,
            'shipping_rate' => in_array((float) ($_POST['shipping_rate'] ?? 0), $rates, true) ? (string) (float) $_POST['shipping_rate'] : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
        ]]);

        return __('Settings saved.', 'kontor-shop');
    }

    private function render_payment_tab(): void
    {
        $accounts   = (new BankAccounts($this->settings))->all();
        $orders     = new OrderRepository();
        $used_by    = [];

        foreach ((new WarehouseRepository())->all() as $warehouse) {
            if ($warehouse->bank_account_id !== null && $warehouse->bank_account_id !== '') {
                $used_by[$warehouse->bank_account_id][] = $warehouse->name;
            }
        }

        $this->form_open('payment');

        $this->intro(__(
            'Here you store the bank accounts that customers transfer money to for prepayment. You can assign an account to each warehouse in the “Warehouses” menu; warehouses without an assignment use the default account. Accounts imported from outside (e.g. via an extension) appear grayed out and are read-only here – you can also add your own accounts.',
            'kontor-shop'
        ));

        echo '<h2>' . esc_html__('Bank accounts for prepayment', 'kontor-shop') . '</h2>';
        echo '<div class="ks-table-scroll"><table class="widefat striped ks-settings-table ks-bank-table"><thead><tr>';
        echo '<th class="ks-col-check">' . esc_html__('Default', 'kontor-shop') . '</th>';
        echo '<th>' . esc_html__('Label', 'kontor-shop') . '</th>';
        echo '<th>' . esc_html__('Account holder', 'kontor-shop') . '</th>';
        echo '<th>IBAN</th><th>BIC</th>';
        echo '<th class="ks-col-remove">' . esc_html__('Remove', 'kontor-shop') . '</th>';
        echo '</tr></thead><tbody>';

        $rows   = $accounts;
        $rows[] = new BankAccount('', '', '', '', ''); // Leerzeile fuer ein neues Konto

        foreach ($rows as $i => $account) {
            $is_new     = $account->id === '';
            $is_default = $i === 0 && !$is_new;
            $name       = 'accounts[' . $i . ']';
            $iban_bad   = $account->iban !== '' && !Iban::is_valid($account->iban);
            $bic_bad    = $account->bic !== '' && !Iban::is_valid_bic($account->bic);
            $warehouses = $used_by[$account->id] ?? [];
            $order_uses = $is_new ? 0 : $orders->count_using_bank_account($account->id);
            $external   = !$is_new && $account->is_external();
            $source     = $external ? self::external_source($account->source) : null;
            $connected  = $source !== null && $source['connected'];
            $removable  = !$is_new && $warehouses === [] && $order_uses === 0 && count($accounts) > 1 && !$connected;

            echo '<tr' . ($external ? ' class="ks-bank-external"' : '') . '>';
            echo '<td><input type="hidden" name="' . esc_attr($name) . '[id]" value="' . esc_attr($account->id) . '" />'
                . ($is_new ? '<span class="ks-muted">' . esc_html__('new', 'kontor-shop') . '</span>'
                    : '<label><input type="radio" name="default_account" value="' . esc_attr($account->id) . '" ' . checked($is_default, true, false) . ' /> <span class="screen-reader-text">'
                        . esc_html__('as default account', 'kontor-shop') . '</span></label>') . '</td>';
            echo '<td><input type="text" name="' . esc_attr($name) . '[label]" class="regular-text" placeholder="'
                . esc_attr($is_new ? __('New account: label', 'kontor-shop') : __('e.g. Main account', 'kontor-shop'))
                . '" value="' . esc_attr($account->label) . '"' . ($external ? ' disabled="disabled"' : '') . ' />'
                . ($source !== null ? '<br /><small class="ks-muted">' . esc_html($this->external_source_note($account, $source)) . '</small>' : '') . '</td>';
            echo '<td><input type="text" name="' . esc_attr($name) . '[recipient]" class="regular-text" value="' . esc_attr($account->recipient) . '"' . ($external ? ' disabled="disabled"' : '') . ' /></td>';
            echo '<td><input type="text" name="' . esc_attr($name) . '[iban]" class="regular-text" value="' . esc_attr($account->iban) . '"' . ($external ? ' disabled="disabled"' : '') . ' />'
                . ($iban_bad ? '<br /><small class="ks-text-bad">&#9888; ' . esc_html__('Validate IBAN (length/checksum)', 'kontor-shop') . '</small>' : '') . '</td>';
            echo '<td><input type="text" name="' . esc_attr($name) . '[bic]" class="regular-text ks-bank-bic" value="' . esc_attr($account->bic) . '"' . ($external ? ' disabled="disabled"' : '') . ' />'
                . ($bic_bad ? '<br /><small class="ks-text-bad">&#9888; ' . esc_html__('Validate BIC', 'kontor-shop') . '</small>' : '') . '</td>';
            echo '<td>';

            if ($is_new) {
                echo '—';
            } elseif ($removable) {
                echo '<label class="ks-bank-remove"><input type="checkbox" name="' . esc_attr($name) . '[remove]" value="1" /> ' . esc_html__('remove', 'kontor-shop') . '</label>';
            } elseif ($connected) {
                echo '<small class="ks-muted">' . esc_html__('Managed externally.', 'kontor-shop') . '</small>';
            } else {
                $reason = $warehouses !== []
                    ? sprintf(
                        /* translators: %s: warehouse names */
                        __('Used by warehouse %s.', 'kontor-shop'),
                        implode(', ', $warehouses)
                    )
                    : ($order_uses > 0 ? sprintf(
                        /* translators: %d: number of orders */
                        _n('Used in %d order.', 'Used in %d orders.', $order_uses, 'kontor-shop'),
                        $order_uses
                    ) : __('The last account stays.', 'kontor-shop'));

                echo '<small class="ks-muted" title="' . esc_attr($reason) . '">' . esc_html($reason) . '</small>';
            }

            echo '</td></tr>';
        }

        echo '</tbody></table></div>';
        echo '<p class="description">' . esc_html__(
            'The account with the dot is the default account (fallback for warehouses without an assignment). To add another account, fill in the last, empty row and save. An account can only be removed if no warehouse and no past order uses it – this keeps old orders and emails correct. IBAN and BIC are checked for format; anything unusual appears as a warning, but saving is never blocked.',
            'kontor-shop'
        ) . '</p>';

        $options = new PaymentOptions($this->settings);

        echo '<h2>' . esc_html__('Payment methods', 'kontor-shop') . '</h2>';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>' . esc_html__('Prepayment', 'kontor-shop') . '</th><td>'
            . '<label><input type="checkbox" name="prepayment_enabled" value="1"' . checked($options->prepayment_enabled(), true, false) . ' /> '
            . esc_html__('Offer prepayment (bank transfer)', 'kontor-shop') . '</label>'
            . '<p class="description">' . esc_html__('The customer pays first, and the order ships after the payment arrives. After the payment deadline (see below), unpaid orders count as overdue.', 'kontor-shop') . '</p></td></tr>';
        echo '<tr><th>' . esc_html__('QR code for the transfer', 'kontor-shop') . '</th><td>'
            . '<label><input type="checkbox" name="qr_enabled" value="1"' . checked(PaymentOptions::qr_enabled($this->settings), true, false) . ' /> '
            . esc_html__('Show a QR code with the payment details', 'kontor-shop') . '</label>'
            . '<p class="description">' . esc_html__('On the order page after ordering and on the order status page. Customers scan it with their banking app (“GiroCode”, standard of the European Payments Council): recipient, IBAN, amount and payment reference are filled in, nobody mistypes the reference. The order email points to it. Works with euro and a valid IBAN.', 'kontor-shop') . '</p></td></tr>';
        echo '<tr><th>' . esc_html__('On invoice', 'kontor-shop') . '</th><td>'
            . '<p><label><input type="checkbox" name="invoice_enabled" value="1"' . checked($options->invoice_enabled(), true, false) . ' /> '
            . esc_html__('Offer purchase on invoice', 'kontor-shop') . '</label></p>';
        $this->extra_fields('invoice_method');
        echo '<p class="description">' . esc_html__('The goods ship right away: with the order, the warehouse gets the shipping job without waiting for payment. Payment is due after receipt within the payment term (below). A shipped order is never cancelled – unpaid invoices only get reminders.', 'kontor-shop') . '</p>'
            . '</td></tr>';
        echo '<tr><th>' . esc_html__('Cash on pickup', 'kontor-shop') . '</th><td>'
            . '<label><input type="checkbox" name="cash_enabled" value="1"' . checked((bool) $this->settings->get('payment.cash_enabled', false), true, false) . ' /> '
            . esc_html__('Offer cash payment on pickup', 'kontor-shop') . '</label>'
            . '<p class="description">' . esc_html__('Only shown at checkout when the customer chooses self pickup. The warehouse gets the order to set the goods aside right away. The customer pays in cash when collecting; “Record pickup” books the cash payment at the same time. No payment deadline, no reminders, no automatic cancellation.', 'kontor-shop') . '</p>'
            . ((bool) $this->settings->get('shipping.allow_self_pickup', true) ? '' : '<p class="description"><strong>' . esc_html__('Self pickup is switched off under Shipping & returns – cash on pickup is not offered until you switch it on.', 'kontor-shop') . '</strong></p>')
            . '<p class="description">' . esc_html__('At least one payment method must apply to all buyers – otherwise prepayment stays enabled.', 'kontor-shop') . '</p></td></tr>';
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('Reservation & payment deadline', 'kontor-shop') . '</h2>';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="ks-reservation">' . esc_html__('Reservation at checkout', 'kontor-shop') . '</label></th><td>'
            . '<input type="number" id="ks-reservation" name="reservation_minutes" min="' . esc_attr((string) Availability::MIN_MINUTES) . '" max="' . esc_attr((string) Availability::MAX_MINUTES) . '" class="small-text" value="'
            . esc_attr((string) (int) ($this->settings->get('checkout.reservation_minutes', Availability::DEFAULT_MINUTES))) . '" /> ' . esc_html__('minutes', 'kontor-shop')
            . '<p class="description">' . esc_html__('While the customer has checkout open (entering an address etc.), the goods are held for them – this prevents two customers from buying the same last item. If they don’t order, it’s released again afterward.', 'kontor-shop') . '</p></td></tr>';
        echo '<tr><th><label for="ks-payment-days">' . esc_html__('Payment deadline for prepayment', 'kontor-shop') . '</label></th><td>'
            . '<input type="number" id="ks-payment-days" name="payment_days" min="0" max="' . esc_attr((string) PaymentDeadline::MAX_DAYS) . '" class="small-text" value="'
            . esc_attr((string) PaymentDeadline::sanitize_days($this->settings->get('payment.payment_days', PaymentDeadline::DEFAULT_DAYS))) . '" /> ' . esc_html__('days', 'kontor-shop')
            . '<p class="description">' . esc_html__('After ordering, the goods are firmly reserved for the customer. The deadline appears on the confirmation page and in the order email (“please transfer by …”). Once it has passed and the order is still unpaid, it counts as “overdue”. 0 = no deadline.', 'kontor-shop') . '</p>'
            . '</td></tr>';
        $this->extra_fields('deadline');
        echo '</tbody></table>';

        $terms = $options->terms();

        echo '<h2>' . esc_html__('On invoice: payment term', 'kontor-shop') . '</h2>';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="ks-invoice-days">' . esc_html__('Payment due date', 'kontor-shop') . '</label></th><td>'
            . '<input type="number" id="ks-invoice-days" name="invoice_days" min="0" max="' . esc_attr((string) PaymentTerms::MAX_INVOICE_DAYS) . '" class="small-text" value="'
            . esc_attr((string) $terms->invoice_days) . '" /> ' . esc_html__('days from shipping or pickup', 'kontor-shop')
            . '<p class="description">' . esc_html__('Counted from the day the entire order is out (for partial shipments: the last one). The due date appears in the shipping email and on the customer’s order page. After that, the invoice counts as “overdue”. 0 = no payment term.', 'kontor-shop') . '</p></td></tr>';
        $this->extra_fields('invoice');
        echo '</tbody></table>';

        $this->savebar();
        echo '</form>';
    }

    /**
     * Weitere Zeilen (form-table) in einer Tabelle des Zahlungs-Formulars.
     *
     * @param string $place 'invoice_method' (in der Zelle "Auf Rechnung", nach dem Schalter),
     *                      'deadline' (nach "Zahlungsfrist fuer Vorkasse") oder 'invoice' (nach "Zahlungsziel")
     */
    private function extra_fields(string $place): void
    {
        /**
         * Aktion kontor_shop/settings/payment/fields: eigene Zeilen
         * (<tr><th>…</th><td>…</td></tr>) im Formular "Zahlung" ausgeben, z. B.
         * automatische Zahlungserinnerung und Autostorno; bei 'invoice_method'
         * Absaetze (<p>…</p>) in der Zelle "Auf Rechnung", z. B. "nur fuer
         * erkannte Mitglieder". Gespeichert wird ueber den Filter
         * kontor_shop/settings/payment/save.
         *
         * @param string     $place    'invoice_method' (Zelle Auf Rechnung), 'deadline' (Tabelle Reservierung & Zahlungsfrist)
         *                             oder 'invoice' (Tabelle Auf Rechnung: Zahlungsziel)
         * @param Repository $settings Einstellungen
         */
        do_action('kontor_shop/settings/payment/fields', $place, $this->settings);
    }

    private function render_tax_tab(): void
    {
        $rates      = array_map('floatval', (array) $this->settings->get('tax.rates', []));
        $default    = (string) $this->settings->get('tax.default_rate', '');

        $this->form_open('tax');

        echo '<p class="description">' . esc_html__(
            'Without tax rates, the shop is treated as exempt from sales tax – then VAT is not shown anywhere. With tax rates, you pick the matching rate when creating a product (the default below can always be changed per product). All prices in the shop are consumer prices (gross); VAT is calculated from them and shown.',
            'kontor-shop'
        ) . '</p>';

        echo '<table class="form-table"><tbody>';

        echo '<tr><th><label for="ks-tax-rates">' . esc_html__('Tax rates (%)', 'kontor-shop') . '</label></th><td>';
        echo '<input type="text" id="ks-tax-rates" name="rates" class="regular-text" placeholder="20 10" value="'
            . esc_attr(implode(' ', array_map(static fn (float $rate): string => rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.'), $rates))) . '" />';
        echo '<p class="description">' . esc_html__(
            'Separated by spaces or semicolons, e.g. "20 10" or "20; 13.5". Empty = exempt from sales tax.',
            'kontor-shop'
        ) . '</p></td></tr>';

        echo '<tr><th><label for="ks-tax-default">' . esc_html__('Default for new products', 'kontor-shop') . '</label></th><td>';
        echo '<select id="ks-tax-default" name="default_rate">';
        echo '<option value="">' . esc_html__('no VAT (tax-exempt)', 'kontor-shop') . '</option>';

        foreach ($rates as $rate) {
            $value = (string) $rate;

            echo '<option value="' . esc_attr($value) . '" ' . selected((float) $default === $rate && $default !== '', true, false) . '>'
                . esc_html(number_format_i18n($rate, 2) . ' %') . '</option>';
        }

        echo '</select>';
        echo '<p class="description">' . esc_html__(
            'The selection appears only after the tax rates are saved.',
            'kontor-shop'
        ) . '</p></td></tr>';

        $ship_mode = (string) $this->settings->get('tax.shipping_mode', TaxCalculator::SHIPPING_PROPORTIONAL);
        $ship_rate = (string) $this->settings->get('tax.shipping_rate', '');

        echo '<tr><th>' . esc_html__('VAT on shipping cost', 'kontor-shop') . '</th><td>';

        foreach ([
            TaxCalculator::SHIPPING_PROPORTIONAL => __('Proportional to goods value (with mixed tax rates in the cart)', 'kontor-shop'),
            TaxCalculator::SHIPPING_FIXED        => __('Fixed tax rate for shipping:', 'kontor-shop'),
            TaxCalculator::SHIPPING_NONE         => __('No VAT on shipping cost', 'kontor-shop'),
        ] as $mode => $label) {
            echo '<label class="ks-radio-line"><input type="radio" name="shipping_mode" value="' . esc_attr($mode) . '" '
                . checked($ship_mode, $mode, false) . ' /> ' . esc_html($label);

            if ($mode === TaxCalculator::SHIPPING_FIXED) {
                echo ' <select name="shipping_rate"><option value="">-</option>';

                foreach ($rates as $rate) {
                    echo '<option value="' . esc_attr((string) $rate) . '" ' . selected($ship_rate !== '' && (float) $ship_rate === $rate, true, false) . '>'
                        . esc_html(number_format_i18n($rate, 2) . ' %') . '</option>';
                }

                echo '</select>';
            }

            echo '</label>';
        }

        echo '<p class="description">' . esc_html__(
            'Shipping costs are final prices (gross). “Proportional” splits them in proportion to the goods values per tax rate (shipping as an ancillary service follows the goods); a tax-exempt share of the goods also stays tax-exempt for its share of shipping. Please check with your tax advisor what applies to you.',
            'kontor-shop'
        ) . '</p></td></tr>';

        echo '<tr><th><label for="ks-tax-exempt">' . esc_html__('Notice for tax exemption', 'kontor-shop') . '</label></th><td>';
        echo '<input type="text" id="ks-tax-exempt" name="exempt_note" class="large-text" value="'
            . esc_attr((string) $this->settings->get('tax.exempt_note', '')) . '" placeholder="' . esc_attr(TextDefaults::exempt_note()) . '" />';
        echo '<p class="description">' . esc_html__(
            'Shown at checkout when there are products without a tax rate in the cart. You set the exact wording (e.g. a reference to the legal basis) together with your tax advisor.',
            'kontor-shop'
        ) . '</p></td></tr>';

        echo '</tbody></table>';

        $this->savebar();
        echo '</form>';
    }

    /**
     * Name und Verbindungsstand einer externen Kontoquelle (BankAccount::$source). Erweiterungen beantworten den
     * Filter kontor_shop/payment/external_source fuer ihre Quelle: connected = true heisst, das Konto wird weiter von
     * dort gepflegt (nicht entfernbar, Aenderungen im Formular zaehlen nicht). Ohne Antwort gilt die Quelle als
     * getrennt - das Konto bleibt mit dem letzten Stand erhalten und kann entfernt werden.
     *
     * @return array{label: string, connected: bool}
     */
    public static function external_source(string $source): array
    {
        $info = apply_filters('kontor_shop/payment/external_source', ['label' => $source, 'connected' => false], $source);
        $info  = is_array($info) ? $info : [];
        $label = trim((string) ($info['label'] ?? ''));

        return ['label' => $label !== '' ? $label : $source, 'connected' => (bool) ($info['connected'] ?? false)];
    }

    /**
     * @param array{label: string, connected: bool} $source
     */
    private function external_source_note(BankAccount $account, array $source): string
    {
        /* translators: %s: name of the source */
        $note = sprintf(__('Source: %s (read-only)', 'kontor-shop'), $source['label']);

        if ($account->synced_at !== '') {
            /* translators: %s: date */
            $note .= ' · ' . sprintf(__('As of %s', 'kontor-shop'), mysql2date(get_option('date_format'), $account->synced_at));
        }

        return $source['connected'] ? $note : $note . ' · ' . __('Connection lost, last known state', 'kontor-shop');
    }

    /**
     * Bankkonten aus dem Formular uebernehmen. Bestehende Konten bleiben
     * erhalten (auch bei leerem Formularfeld mit den bisherigen Werten), weil
     * Lager und Bestellungen ueber die ID auf sie verweisen; entfernt wird ein
     * Konto nur auf ausdruecklichen Wunsch und nur, wenn kein Lager und keine
     * Bestellung es mehr verwendet. Neue Zeilen bekommen eine stabile ID. Das
     * per Auswahlpunkt gewaehlte Konto ist das Standardkonto (steht an erster
     * Stelle). Auffaellige IBAN/BIC erzeugen nur eine Warnung.
     *
     * @param array<int|string, mixed> $rows
     * @return array<int, array<string, string>>
     */
    private function collect_accounts(array $rows): array
    {
        $previous = [];

        foreach ((new BankAccounts($this->settings))->all() as $account) {
            $previous[$account->id] = $account->to_array();
        }

        $submitted = [];
        $removals  = [];
        $new_rows  = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            // Bereits in save_payment() per map_deep mit wp_unslash() und sanitize_text_field() bereinigt.
            $clean = static fn (string $key): string => sanitize_text_field((string) ($row[$key] ?? ''));

            $account = [
                'id'        => sanitize_key((string) ($row['id'] ?? '')),
                'label'     => $clean('label'),
                'recipient' => $clean('recipient'),
                'iban'      => Iban::format($clean('iban')),
                'bic'       => strtoupper((string) preg_replace('/\s+/', '', $clean('bic'))),
            ];

            if ($account['id'] !== '' && isset($previous[$account['id']])) {
                $submitted[$account['id']] = $account;

                if (!empty($row['remove'])) {
                    $removals[$account['id']] = true;
                }

                continue;
            }

            if ($account['recipient'] === '' && $account['iban'] === '' && $account['bic'] === '') {
                continue;
            }

            $account['id'] = 'acc_' . strtolower(wp_generate_password(8, false, false));
            $new_rows[]    = $account;
        }

        $accounts = [];

        foreach ($previous as $id => $old) {
            $candidate = $submitted[$id] ?? $old;

            // Fremdkonto (Quelle gesetzt): im Shop nur lesbar - Formularwerte zaehlen nicht.
            if (($old['source'] ?? '') !== '') {
                $candidate = $old;
                $source    = self::external_source((string) $old['source']);

                if (isset($removals[$id]) && $source['connected']) {
                    unset($removals[$id]);
                    $this->warnings[] = sprintf(
                        /* translators: 1: account label, 2: name of the source */
                        __('The account “%1$s” can’t be removed as long as the connection to %2$s exists.', 'kontor-shop'),
                        (string) ($old['label'] ?? $id),
                        $source['label']
                    );
                }
            }

            // Komplett leer geschickt: bisherige Werte behalten statt das Konto zu loeschen.
            if ($candidate['recipient'] === '' && $candidate['iban'] === '' && $candidate['bic'] === '') {
                $candidate = $old;
            }

            if (isset($removals[$id])) {
                $blocker = $this->bank_account_blocker($id, $candidate);

                if ($blocker === null && count($previous) - count($removals) + count($new_rows) >= 1) {
                    continue; // entfernt
                }

                $this->warnings[] = $blocker ?? __('The last bank account can’t be removed.', 'kontor-shop');
            }

            $accounts[] = $candidate;
        }

        $accounts = array_merge($accounts, $new_rows);

        // Standardkonto = an erster Stelle.
        $default = sanitize_key((string) wp_unslash($_POST['default_account'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request

        if ($default !== '') {
            usort($accounts, static fn (array $a, array $b): int => (int) ($b['id'] === $default) <=> (int) ($a['id'] === $default));
        }

        foreach ($accounts as $account) {
            $name = $account['label'] !== '' ? $account['label'] : ($account['recipient'] !== '' ? $account['recipient'] : $account['iban']);

            if ($account['iban'] !== '' && !Iban::is_valid($account['iban'])) {
                $this->warnings[] = sprintf(
                    /* translators: %s: account label */
                    __('Account "%s": The IBAN looks invalid (length or checksum is wrong). Please check it – it was saved anyway.', 'kontor-shop'),
                    $name
                );
            }

            if ($account['bic'] !== '' && !Iban::is_valid_bic($account['bic'])) {
                $this->warnings[] = sprintf(
                    /* translators: %s: account label */
                    __('Account "%s": The BIC looks invalid. Please check it – it was saved anyway.', 'kontor-shop'),
                    $name
                );
            }
        }

        return $accounts;
    }

    /**
     * Grund, warum ein Bankkonto nicht entfernt werden darf (null = frei).
     *
     * @param array<string, string> $account
     */
    private function bank_account_blocker(string $id, array $account): ?string
    {
        $name = $account['label'] !== '' ? $account['label'] : ($account['recipient'] !== '' ? $account['recipient'] : $account['iban']);

        foreach ((new WarehouseRepository())->all() as $warehouse) {
            if ($warehouse->bank_account_id === $id) {
                return sprintf(
                    /* translators: 1: account label, 2: warehouse name */
                    __('Account "%1$s" was not removed: warehouse "%2$s" still uses it. First assign a different account to the warehouse.', 'kontor-shop'),
                    $name,
                    $warehouse->name
                );
            }
        }

        $orders = (new OrderRepository())->count_using_bank_account($id);

        if ($orders > 0) {
            return sprintf(
                /* translators: 1: account label, 2: number of orders */
                _n(
                    'Account "%1$s" was not removed: %2$d order still refers to it.',
                    'Account "%1$s" was not removed: %2$d orders still refer to it.',
                    $orders,
                    'kontor-shop'
                ),
                $name,
                $orders
            );
        }

        return null;
    }
}
