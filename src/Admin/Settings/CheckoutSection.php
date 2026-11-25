<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin\Settings;

use Kontor\Shop\Domain\Checkout\CheckoutValidator;
use Kontor\Shop\Domain\Order\OrderLink;

/**
 * Einstellungen → Kasse: was die Kasse abfragt (Telefonnummer, abweichende
 * Rechnungsadresse) und wie lange der Bestellstatus-Link fuer Kunden gilt.
 * Bis 2.9.13 standen diese Felder unter Versand.
 */
final class CheckoutSection extends Section
{
    public function key(): string
    {
        return 'checkout';
    }

    public function label(): string
    {
        return __('Checkout', 'kontor-shop');
    }

    public function group(): string
    {
        return self::GROUP_SELL;
    }

    public function description(): string
    {
        return __('What checkout asks your customers for, and how long they can follow their order.', 'kontor-shop');
    }

    public function save(string $tab): ?string
    {
        if ($tab !== 'checkout') {
            return null;
        }

        $phone_mode = sanitize_text_field(wp_unslash((string) ($_POST['phone_mode'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request

        $this->settings->save(['checkout' => [
            'phone'           => in_array($phone_mode, [CheckoutValidator::PHONE_OFF, CheckoutValidator::PHONE_OPTIONAL, CheckoutValidator::PHONE_REQUIRED], true)
                ? $phone_mode
                : CheckoutValidator::PHONE_OPTIONAL,
            'billing_address' => !empty($_POST['billing_address']), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
            'order_link_days' => OrderLink::sanitize_days((int) sanitize_text_field(wp_unslash((string) ($_POST['order_link_days'] ?? OrderLink::DEFAULT_DAYS)))), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
        ]]);

        return __('Settings saved.', 'kontor-shop');
    }

    public function render(): void
    {
        $this->form_open('checkout');

        echo '<table class="form-table"><tbody>';

        echo '<tr><th>' . esc_html__('Phone number', 'kontor-shop') . '</th><td><div class="ks-segmented" role="radiogroup" aria-label="' . esc_attr__('Phone number at checkout', 'kontor-shop') . '">';

        foreach ([
            CheckoutValidator::PHONE_OFF      => __('don’t ask', 'kontor-shop'),
            CheckoutValidator::PHONE_OPTIONAL => __('optional', 'kontor-shop'),
            CheckoutValidator::PHONE_REQUIRED => __('Required', 'kontor-shop'),
        ] as $mode => $label) {
            echo '<label><input type="radio" name="phone_mode" value="' . esc_attr($mode) . '" '
                . checked((string) $this->settings->get('checkout.phone', CheckoutValidator::PHONE_OPTIONAL), $mode, false) . ' /> <span>' . esc_html($label) . '</span></label>';
        }

        echo '</div><p class="description">' . esc_html__('Whether customers must, may, or aren’t asked to enter a phone number at checkout. Useful for delivery questions.', 'kontor-shop') . '</p></td></tr>';

        echo '<tr><th>' . esc_html__('Billing address', 'kontor-shop') . '</th><td>';
        echo '<label class="ks-check-line"><input type="checkbox" name="billing_address" value="1" ' . checked((bool) $this->settings->get('checkout.billing_address', true), true, false)
            . ' /> ' . esc_html__('offer a different billing address / company with VAT ID at checkout', 'kontor-shop') . '</label></td></tr>';

        echo '<tr><th><label for="ks-order-link-days">' . esc_html__('Order status link for customers', 'kontor-shop') . '</label></th><td>'
            . esc_html__('valid until', 'kontor-shop') . ' <input type="number" id="ks-order-link-days" name="order_link_days" min="1" max="' . esc_attr((string) OrderLink::MAX_DAYS) . '" class="small-text" value="'
            . esc_attr((string) OrderLink::sanitize_days($this->settings->get('checkout.order_link_days', OrderLink::DEFAULT_DAYS))) . '" /> '
            . esc_html__('days after the order is completed', 'kontor-shop')
            . '<p class="description">' . esc_html__('Every email to the customer (confirmation, payment, shipping/pickup, cancellation) contains a personal link to a page with the current state of their order: status, payment, shipments with tracking, cancellations. The link works while the order is in progress, and afterward for this many more days from shipping, pickup, or cancellation.', 'kontor-shop') . '</p></td></tr>';

        echo '</tbody></table>';

        $this->savebar();
        echo '</form>';
    }
}
