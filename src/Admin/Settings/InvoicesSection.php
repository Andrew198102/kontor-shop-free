<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin\Settings;

use Kontor\Shop\Infrastructure\Invoices\InvoicePdf;
use Kontor\Shop\Modules\Invoices\InvoicesModule;
use Kontor\Shop\Settings\TextDefaults;

/**
 * Einstellungen -> Rechnungen: Absender, Zeitpunkt, Mailversand. Pro (oder
 * ein Add-on) haengt Nummernformat, Logo und Fusszeile ueber
 * kontor_shop/settings/invoices/fields und .../save an.
 */
final class InvoicesSection extends Section
{
    public const PREVIEW_ACTION = 'kontor_shop_invoice_preview';

    public function key(): string
    {
        return 'invoices';
    }

    public function label(): string
    {
        return __('Invoices', 'kontor-shop');
    }

    public function group(): string
    {
        return self::GROUP_SELL;
    }

    public function description(): string
    {
        return __('PDF invoices with continuous numbers: created automatically, sent to the customer and stored with the order.', 'kontor-shop');
    }

    public function save(string $tab): ?string
    {
        if ($tab !== 'invoices') {
            return null;
        }

        $text = static fn (string $key, bool $multi = false): string => $multi
            ? sanitize_textarea_field(wp_unslash((string) ($_POST[$key] ?? ''))) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
            : sanitize_text_field(wp_unslash((string) ($_POST[$key] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request

        $trigger = $text('trigger');
        $enabled = !empty($_POST['enabled']); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request

        /**
         * Filter kontor_shop/settings/invoices/save: eigene Felder aus
         * kontor_shop/settings/invoices/fields mitspeichern (Werte selbst
         * bereinigen; Nonce und Recht sind geprueft). Rueckgabe: weitere Werte
         * unter invoices.*. Standard: keine.
         *
         * @param array<string, mixed> $values
         */
        $extra = (array) apply_filters('kontor_shop/settings/invoices/save', []);

        $this->settings->save(['invoices' => [
            'enabled'        => $enabled,
            'trigger'        => in_array($trigger, InvoicesModule::TRIGGERS, true) ? $trigger : 'paid',
            'send_email'     => !empty($_POST['send_email']), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
            'seller_name'    => $text('seller_name'),
            'seller_address' => $text('seller_address', true),
            'seller_vat_id'  => strtoupper(preg_replace('/\s+/', '', $text('seller_vat_id')) ?? ''),
            'seller_extra'   => $text('seller_extra', true),
            'exempt_note'    => $text('exempt_note', true),
        ] + $extra]);

        if ($enabled && ($text('seller_name') === '' || $text('seller_address', true) === '')) {
            $this->warnings[] = __('Invoices stay off until you enter the sender name and address – both are required on every invoice.', 'kontor-shop');
        }

        return __('Settings saved.', 'kontor-shop');
    }

    public function render(): void
    {
        $get = fn (string $key, mixed $default = ''): mixed => $this->settings->get('invoices.' . $key, $default);

        $this->form_open('invoices');
        $this->intro(__('When switched on, the shop creates a PDF invoice for every order, numbers it continuously (e.g. 2026-0001), sends it to the customer and keeps it with the order. Cancellations and returns of invoiced goods get a credit note. Invoices are never changed or deleted afterwards.', 'kontor-shop'), 'setup-invoices');

        if (!InvoicePdf::available()) {
            echo '<div class="notice notice-error inline"><p>' . esc_html__('The PDF library is missing from the plugin package. Please reinstall Kontor Shop.', 'kontor-shop') . '</p></div>';
        }

        echo '<table class="form-table"><tbody>';

        echo '<tr><th>' . esc_html__('Invoices', 'kontor-shop') . '</th><td><label><input type="checkbox" name="enabled" value="1" ' . checked((bool) $get('enabled', false), true, false) . ' /> '
            . esc_html__('Create PDF invoices', 'kontor-shop') . '</label></td></tr>';

        echo '<tr><th><label for="ks-inv-name">' . esc_html__('Sender (club name)', 'kontor-shop') . '</label></th><td>'
            . '<input type="text" id="ks-inv-name" name="seller_name" class="regular-text" value="' . esc_attr((string) $get('seller_name')) . '" placeholder="' . esc_attr(get_bloginfo('name')) . '" /></td></tr>';
        echo '<tr><th><label for="ks-inv-address">' . esc_html__('Address', 'kontor-shop') . '</label></th><td>'
            . '<textarea id="ks-inv-address" name="seller_address" rows="3" class="regular-text">' . esc_textarea((string) $get('seller_address')) . '</textarea></td></tr>';
        echo '<tr><th><label for="ks-inv-vat">' . esc_html__('VAT ID (optional)', 'kontor-shop') . '</label></th><td>'
            . '<input type="text" id="ks-inv-vat" name="seller_vat_id" class="regular-text" value="' . esc_attr((string) $get('seller_vat_id')) . '" placeholder="ATU12345678" />'
            . '<p class="description">' . esc_html__('Only if the club has one. Without a VAT ID and without VAT rates, the invoice shows the note for tax exemption.', 'kontor-shop') . '</p></td></tr>';
        echo '<tr><th><label for="ks-inv-extra">' . esc_html__('Further details (optional)', 'kontor-shop') . '</label></th><td>'
            . '<textarea id="ks-inv-extra" name="seller_extra" rows="2" class="regular-text" placeholder="ZVR-Zahl 123456789">' . esc_textarea((string) $get('seller_extra')) . '</textarea>'
            . '<p class="description">' . esc_html__('E.g. association register number, tax number, phone or email for questions about the invoice.', 'kontor-shop') . '</p></td></tr>';

        echo '<tr><th>' . esc_html__('When is the invoice created?', 'kontor-shop') . '</th><td><fieldset>';

        foreach ([
            'paid'    => __('When the payment is recorded (recommended for prepayment)', 'kontor-shop'),
            'shipped' => __('When the order is shipped or picked up', 'kontor-shop'),
            'manual'  => __('Only when I click “Create invoice now” in the order', 'kontor-shop'),
        ] as $value => $label) {
            echo '<label class="ks-radio-line"><input type="radio" name="trigger" value="' . esc_attr($value) . '" ' . checked((string) $get('trigger', 'paid'), $value, false) . ' /> ' . esc_html($label) . '</label>';
        }

        echo '<p class="description">' . esc_html__('Orders on invoice and with cash on pickup always get their invoice with the shipment or pickup – that is when they are paid.', 'kontor-shop') . '</p></fieldset></td></tr>';

        echo '<tr><th>' . esc_html__('Email', 'kontor-shop') . '</th><td><label><input type="checkbox" name="send_email" value="1" ' . checked((bool) $get('send_email', true), true, false) . ' /> '
            . esc_html__('Send invoices and credit notes to the customer (PDF attached)', 'kontor-shop') . '</label>'
            . '<p class="description">' . esc_html__('Text: Settings → Email templates → “Invoice / credit note”. The PDF is also available on the customer’s order page.', 'kontor-shop') . '</p></td></tr>';

        echo '<tr><th><label for="ks-inv-exempt">' . esc_html__('Note for tax-free amounts', 'kontor-shop') . '</label></th><td>'
            . '<textarea id="ks-inv-exempt" name="exempt_note" rows="2" class="large-text" placeholder="' . esc_attr(TextDefaults::or_default((string) $this->settings->get('tax.exempt_note', ''), TextDefaults::exempt_note())) . '">' . esc_textarea((string) $get('exempt_note')) . '</textarea>'
            . '<p class="description">' . esc_html__('Printed when the invoice contains amounts without VAT, e.g. as a small business. Agree on the exact wording with your tax advisor. Empty = the note from Payment & sales tax.', 'kontor-shop') . '</p></td></tr>';

        /**
         * Aktion kontor_shop/settings/invoices/fields: eigene Zeilen
         * (<tr><th>…</th><td>…</td></tr>), z. B. Pro: Nummernformat, Logo,
         * Fusszeile. Gespeichert wird ueber kontor_shop/settings/invoices/save.
         *
         * @param \Kontor\Shop\Settings\Repository $settings
         */
        do_action('kontor_shop/settings/invoices/fields', $this->settings);

        echo '</tbody></table>';

        echo '<p><a class="button" target="_blank" rel="noopener" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=' . self::PREVIEW_ACTION), self::PREVIEW_ACTION)) . '">'
            . esc_html__('View sample invoice (saved settings)', 'kontor-shop') . '</a></p>';

        $this->savebar();
        echo '</form>';
    }
}
