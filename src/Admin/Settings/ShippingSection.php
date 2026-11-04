<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin\Settings;

use Kontor\Shop\Domain\Checkout\CheckoutValidator;
use Kontor\Shop\Domain\Order\OrderLink;
use Kontor\Shop\Modules\Shipping\SplitShipping;
use Kontor\Shop\Support\Countries;

/**
 * Versandkosten, Lieferlaender, Kassenfelder und aufgeteilte Bestellungen
 * (Rueckgabe und Lieferzeit: Pro, ueber kontor_shop/settings/forms).
 */
final class ShippingSection extends Section
{
    public function key(): string
    {
        return 'shipping';
    }

    public function label(): string
    {
        // Mit Rueckgabe-Angaben einer Erweiterung (Pro) "Versand & Rueckgabe".
        return $this->forms() !== [] ? __('Shipping & returns', 'kontor-shop') : __('Shipping', 'kontor-shop');
    }

    public function group(): string
    {
        return self::GROUP_SELL;
    }

    public function save(string $tab): ?string
    {
        return $tab === 'shipping' ? $this->save_shipping() : null;
    }

    public function render(): void
    {
        if ($this->forms() !== []) {
            $this->group_title(__('Shipping', 'kontor-shop'));
        }

        $this->render_shipping_tab();
    }

    private function save_shipping(): ?string
    {
        $split_mode = sanitize_text_field(wp_unslash((string) ($_POST['split_mode'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
        $phone_mode = sanitize_text_field(wp_unslash((string) ($_POST['phone_mode'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request

        /**
         * Filter kontor_shop/settings/shipping/save: eigene Felder aus
         * kontor_shop/settings/shipping/fields mitspeichern (Werte aus $_POST
         * selbst bereinigen; Nonce und Recht sind geprueft), z. B. Pro:
         * "Mitglieder zahlen keinen Versand". Die eingebauten Schluessel
         * lassen sich nicht ueberschreiben. Standard: nur die eingebauten -
         * ohne Erweiterung bleiben deren gespeicherte Werte unberuehrt.
         *
         * @param array<string, mixed> $values zusaetzliche Werte unter shipping.*
         */
        $extra = apply_filters('kontor_shop/settings/shipping/save', []);

        $this->settings->save(['shipping' => [
            'flat_rate'         => (float) str_replace(',', '.', sanitize_text_field(wp_unslash((string) ($_POST['flat_rate'] ?? '0')))), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
            'free_from_amount'  => (float) str_replace(',', '.', sanitize_text_field(wp_unslash((string) ($_POST['free_from_amount'] ?? '0')))), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
            'allow_self_pickup' => !empty($_POST['allow_self_pickup']), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
            'split_mode'        => in_array($split_mode, SplitShipping::modes(), true)
                ? $split_mode
                : SplitShipping::SEPARATE,
            'split_flat_rate'   => max(0.0, (float) str_replace(',', '.', sanitize_text_field(wp_unslash((string) ($_POST['split_flat_rate'] ?? '0'))))), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
        ] + (is_array($extra) ? $extra : []), 'checkout' => [
            'phone'           => in_array($phone_mode, [CheckoutValidator::PHONE_OFF, CheckoutValidator::PHONE_OPTIONAL, CheckoutValidator::PHONE_REQUIRED], true)
                ? $phone_mode
                : CheckoutValidator::PHONE_OPTIONAL,
            'billing_address' => !empty($_POST['billing_address']), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
            'order_link_days' => OrderLink::sanitize_days((int) sanitize_text_field(wp_unslash((string) ($_POST['order_link_days'] ?? OrderLink::DEFAULT_DAYS)))), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
            'countries'       => $this->collect_countries(array_map('sanitize_text_field', (array) wp_unslash($_POST['countries'] ?? []))), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
        ]]);

        return __('Settings saved.', 'kontor-shop');
    }

    private function render_shipping_tab(): void
    {
        $money      = fn (string $key): string => (string) $this->settings->get('shipping.' . $key, 0);

        $this->form_open('shipping');

        $this->intro(__('Here you set what shipping costs and when it doesn’t apply. The values take effect immediately at checkout.', 'kontor-shop'));

        echo '<div class="ks-example"><strong>' . esc_html__('This is how shipping is calculated (top to bottom, the first matching rule applies):', 'kontor-shop') . '</strong><ol class="ks-example__list">'
            . '<li>' . esc_html__('Self pickup selected → €0', 'kontor-shop') . '</li>';
        $this->extra_fields('rules');
        echo '<li>' . esc_html__('Order value reaches the “Free shipping from” threshold → €0', 'kontor-shop') . '</li>'
            . '<li>' . esc_html__('Otherwise → the flat shipping rate', 'kontor-shop') . '</li></ol></div>';

        echo '<table class="form-table"><tbody>';

        echo '<tr><th><label for="ks-flat-rate">' . esc_html__('Flat shipping rate', 'kontor-shop') . '</label></th><td>';
        echo '<input type="text" inputmode="decimal" id="ks-flat-rate" name="flat_rate" class="small-text" value="' . esc_attr($money('flat_rate')) . '" /> €';
        echo '<p class="description">' . esc_html__('What shipping costs per order (final price). 0 = free shipping for everyone.', 'kontor-shop') . '</p></td></tr>';

        echo '<tr><th><label for="ks-free-from">' . esc_html__('Free shipping from', 'kontor-shop') . '</label></th><td>';
        echo '<input type="text" inputmode="decimal" id="ks-free-from" name="free_from_amount" class="small-text" value="' . esc_attr($money('free_from_amount')) . '" /> €';
        echo '<p class="description">' . esc_html__('From this goods value, nobody pays shipping. 0 = no such threshold. Example: 50 → free shipping for orders of €50 or more.', 'kontor-shop') . '</p></td></tr>';

        $this->extra_fields('costs');

        echo '<tr><th>' . esc_html__('Self pickup', 'kontor-shop') . '</th><td>';
        echo '<label><input type="checkbox" name="allow_self_pickup" value="1" '
            . checked((bool) $this->settings->get('shipping.allow_self_pickup', true), true, false) . ' /> '
            . esc_html__('Customers may pick up the goods themselves', 'kontor-shop') . '</label>';
        echo '<p class="description">' . esc_html__('Checkout then offers the “Pickup” option; no shipping costs apply.', 'kontor-shop') . '</p></td></tr>';

        echo '<tr><th>' . esc_html__('Shipping countries', 'kontor-shop') . '</th><td>';

        $selected_countries = array_map('strtoupper', (array) $this->settings->get('checkout.countries', ['AT']));

        echo '<div class="ks-countries">';

        foreach (Countries::all() as $code => $name) {
            echo '<label><input type="checkbox" name="countries[]" value="' . esc_attr($code) . '" '
                . checked(in_array($code, $selected_countries, true), true, false) . ' /> ' . esc_html($name) . '</label>';
        }

        echo '</div><p class="description">' . esc_html__(
            'You ship to these countries. Customers can only choose from these at checkout; the postal code is validated to match the country. The default country is the first in the list. Self pickup is independent of this.',
            'kontor-shop'
        ) . '</p></td></tr>';

        echo '<tr><th>' . esc_html__('Phone number at checkout', 'kontor-shop') . '</th><td>';

        foreach ([
            CheckoutValidator::PHONE_OFF      => __('don’t ask', 'kontor-shop'),
            CheckoutValidator::PHONE_OPTIONAL => __('optional', 'kontor-shop'),
            CheckoutValidator::PHONE_REQUIRED => __('Required', 'kontor-shop'),
        ] as $mode => $label) {
            echo '<label class="ks-inline"><input type="radio" name="phone_mode" value="' . esc_attr($mode) . '" '
                . checked((string) $this->settings->get('checkout.phone', CheckoutValidator::PHONE_OPTIONAL), $mode, false) . ' /> ' . esc_html($label) . '</label>';
        }

        echo '<p class="description">' . esc_html__('Whether customers must, may, or aren’t asked to enter a phone number at checkout. Useful for delivery questions.', 'kontor-shop') . '</p></td></tr>';

        echo '<tr><th>' . esc_html__('Billing address', 'kontor-shop') . '</th><td>';
        echo '<label><input type="checkbox" name="billing_address" value="1" ' . checked((bool) $this->settings->get('checkout.billing_address', true), true, false)
            . ' /> ' . esc_html__('offer a different billing address / company with VAT ID at checkout', 'kontor-shop') . '</label></td></tr>';

        echo '<tr><th><label for="ks-order-link-days">' . esc_html__('Order status link for customers', 'kontor-shop') . '</label></th><td>'
            . esc_html__('valid until', 'kontor-shop') . ' <input type="number" id="ks-order-link-days" name="order_link_days" min="1" max="' . esc_attr((string) OrderLink::MAX_DAYS) . '" class="small-text" value="'
            . esc_attr((string) OrderLink::sanitize_days($this->settings->get('checkout.order_link_days', OrderLink::DEFAULT_DAYS))) . '" /> '
            . esc_html__('days after the order is completed', 'kontor-shop')
            . '<p class="description">' . esc_html__('Every email to the customer (confirmation, payment, shipping/pickup, cancellation) contains a personal link to a page with the current state of their order: status, payment, shipments with tracking, cancellations. The link works while the order is in progress, and afterward for this many more days from shipping, pickup, or cancellation.', 'kontor-shop') . '</p></td></tr>';

        echo '</tbody></table>';

        echo '<h2>' . esc_html__('Shipping for split orders', 'kontor-shop') . '</h2>';
        echo '<p class="description">' . esc_html__(
            'If a cart contains products from warehouses with different bank accounts, it is automatically split into several orders (one per account). Here you set how shipping is then calculated.',
            'kontor-shop'
        ) . '</p>';
        echo '<div class="ks-example">' . esc_html__('Example: a customer buys a sweater from warehouse A (account 1) and a cap from warehouse B (account 2) → two orders. With “Separate” they pay shipping twice, with “Unified” once.', 'kontor-shop') . '</div>';

        echo '<table class="form-table"><tbody><tr><th>' . esc_html__('Calculation', 'kontor-shop') . '</th><td>';

        $split_mode = (string) $this->settings->get('shipping.split_mode', SplitShipping::SEPARATE);

        foreach ([
            SplitShipping::SEPARATE => __('Separate: each partial order calculates its own shipping', 'kontor-shop'),
            SplitShipping::UNIFIED  => __('Unified: shipping once based on the entire cart', 'kontor-shop'),
            SplitShipping::CUSTOM   => __('Custom price: fixed flat rate for split orders', 'kontor-shop'),
        ] as $mode => $label) {
            echo '<label class="ks-radio-line"><input type="radio" name="split_mode" value="' . esc_attr($mode) . '" '
                . checked($split_mode, $mode, false) . ' /> ' . esc_html($label) . '</label>';
        }

        echo '<p class="ks-split-rate"><label>' . esc_html__('Flat rate for “Custom price”:', 'kontor-shop')
            . ' <input type="text" inputmode="decimal" name="split_flat_rate" class="small-text" value="' . esc_attr($money('split_flat_rate')) . '" /> €</label></p>';
        echo '<p class="description">' . esc_html__(
            'With “Unified” and “Custom price”, shipping is charged only once (on the first partial order, the others €0). Free-shipping rules then apply to the entire cart and also set the custom price to 0.',
            'kontor-shop'
        ) . '</p></td></tr></tbody></table>';

        $this->savebar();
        echo '</form>';
    }

    /**
     * @param array<int|string, mixed> $selected
     * @return array<int, string> mindestens ein Land (Standard Oesterreich)
     */
    private function collect_countries(array $selected): array
    {
        $codes = array_values(array_filter(
            array_map(static fn ($code): string => strtoupper(sanitize_key((string) $code)), $selected),
            [Countries::class, 'is_known']
        ));

        return $codes !== [] ? $codes : ['AT'];
    }

    /**
     * Weitere Ausgaben im Formular "Versand".
     *
     * @param string $place 'rules' (Listenpunkte <li> in "So wird der Versand berechnet", nach "Selbstabholung")
     *                      oder 'costs' (Zeilen nach "Versandkostenfrei ab")
     */
    private function extra_fields(string $place): void
    {
        /**
         * Aktion kontor_shop/settings/shipping/fields: eigene Ausgaben im
         * Formular "Versand", z. B. Pro: Regel und Schalter "Mitglieder zahlen
         * keinen Versand". Bei 'rules' Listenpunkte (<li>…</li>), bei 'costs'
         * Zeilen (<tr><th>…</th><td>…</td></tr>). Gespeichert wird ueber den
         * Filter kontor_shop/settings/shipping/save.
         *
         * @param string     $place    'rules' oder 'costs'
         * @param Repository $settings Einstellungen
         */
        do_action('kontor_shop/settings/shipping/fields', $place, $this->settings);
    }
}
