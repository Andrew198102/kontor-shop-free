<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin\Settings;

/**
 * Rechtstexte, Pflicht-Checkboxen, Texte am Bestell-Button und Aufbewahrung
 * (Gewaehrleistung: Pro, ueber kontor_shop/settings/forms).
 */
final class LegalSection extends Section
{
    public function key(): string
    {
        return 'legal';
    }

    public function label(): string
    {
        // Mit Gewaehrleistungs-Angaben einer Erweiterung (Pro) "Rechtliches & Gewaehrleistung".
        return $this->forms() !== [] ? __('Legal & warranty', 'kontor-shop') : __('Legal', 'kontor-shop');
    }

    public function group(): string
    {
        return self::GROUP_SELL;
    }

    public function save(string $tab): ?string
    {
        return $tab === 'legal' ? $this->save_legal() : null;
    }

    public function render(): void
    {
        if ($this->forms() !== []) {
            $this->group_title(__('Legal', 'kontor-shop'));
        }

        $this->render_legal_tab();
    }

    private function save_legal(): ?string
    {
        $page_ids = [];

        foreach (['terms_page_id', 'withdrawal_page_id', 'privacy_page_id', 'imprint_page_id'] as $key) {
            $page_ids[$key] = absint($_POST[$key] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
        }

        $this->settings->save(['legal' => array_merge($page_ids, [
            'require_terms'      => !empty($_POST['require_terms']), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
            'require_privacy'    => !empty($_POST['require_privacy']), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
            'terms_label'        => sanitize_text_field(wp_unslash($_POST['terms_label'] ?? '')), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
            'privacy_label'      => sanitize_text_field(wp_unslash($_POST['privacy_label'] ?? '')), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
            'price_note'         => sanitize_text_field(wp_unslash($_POST['price_note'] ?? '')), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
            'order_button_label' => sanitize_text_field(wp_unslash($_POST['order_button_label'] ?? '')), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
        ]), 'privacy' => [
            'retention_years' => min(30, max(0, absint($_POST['retention_years'] ?? 7))), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
        ]]);

        return __('Settings saved.', 'kontor-shop');
    }

    private function render_legal_tab(): void
    {
        $text       = fn (string $key, string $default = ''): string => (string) $this->settings->get('legal.' . $key, $default);

        $this->form_open('legal');

        $this->intro(__(
            'Here you link your legal texts to the checkout and decide what customers must confirm before ordering. You write the texts themselves as regular WordPress pages (Pages → Add New); they come from the club or legal counsel.',
            'kontor-shop'
        ));

        echo '<h2>' . esc_html__('1. Link legal texts', 'kontor-shop') . '</h2>';
        echo '<p class="description">' . esc_html__('The selected pages are linked automatically in the checkout. If you use a language plugin (Polylang or WPML), choose the page in the main language here – visitors automatically get the translated page in their language linked, if there is one.', 'kontor-shop') . '</p>';
        echo '<table class="form-table"><tbody>';

        foreach ([
            'terms_page_id'      => __('Page: Terms and conditions', 'kontor-shop'),
            'withdrawal_page_id' => __('Page: Right of withdrawal', 'kontor-shop'),
            'privacy_page_id'    => __('Page: Privacy policy', 'kontor-shop'),
            'imprint_page_id'    => __('Page: Legal notice', 'kontor-shop'),
        ] as $key => $label) {
            echo '<tr><th><label for="ks-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
            wp_dropdown_pages([
                'name'              => esc_attr($key),
                'id'                => 'ks-' . esc_attr($key),
                'selected'          => absint($this->settings->get('legal.' . $key, 0)),
                'show_option_none'  => esc_html__('– don’t link –', 'kontor-shop'),
                'option_none_value' => '0',
            ]);
            echo '</td></tr>';
        }

        echo '</tbody></table>';

        echo '<h2>' . esc_html__('2. Confirmations in the checkout', 'kontor-shop') . '</h2>';
        echo '<table class="form-table"><tbody>';

        echo '<tr><th>' . esc_html__('Required checkboxes', 'kontor-shop') . '</th><td>';
        echo '<label><input type="checkbox" name="require_terms" value="1" ' . checked((bool) $this->settings->get('legal.require_terms', true), true, false)
            . ' /> ' . esc_html__('Terms and conditions / withdrawal must be accepted', 'kontor-shop') . '</label><br />';
        echo '<label><input type="checkbox" name="require_privacy" value="1" ' . checked((bool) $this->settings->get('legal.require_privacy', true), true, false)
            . ' /> ' . esc_html__('Privacy policy must be confirmed', 'kontor-shop') . '</label>';
        echo '<p class="description">' . esc_html__('When checked, a customer can only order if they tick the box in the checkout. The time of consent is saved with the order.', 'kontor-shop') . '</p></td></tr>';

        echo '<tr><th><label for="ks-terms-label">' . esc_html__('Text: terms and conditions checkbox', 'kontor-shop') . '</label></th><td>';
        echo '<input type="text" id="ks-terms-label" name="terms_label" class="large-text" value="' . esc_attr($text('terms_label')) . '" />';
        echo '<p class="description">' . esc_html__('Placeholders become links to the pages above: {agb} {widerruf} {datenschutz} {impressum}.', 'kontor-shop') . '</p>';
        echo '<div class="ks-example">' . esc_html__('Example: I accept the {agb} and have read the {widerruf}.', 'kontor-shop') . '</div></td></tr>';

        echo '<tr><th><label for="ks-privacy-label">' . esc_html__('Text: privacy policy checkbox', 'kontor-shop') . '</label></th><td>';
        echo '<input type="text" id="ks-privacy-label" name="privacy_label" class="large-text" value="' . esc_attr($text('privacy_label')) . '" />';
        echo '<p class="description">' . esc_html__('Same placeholders as above.', 'kontor-shop') . '</p></td></tr>';

        echo '</tbody></table>';

        echo '<h2>' . esc_html__('3. Texts at the order button', 'kontor-shop') . '</h2>';
        echo '<table class="form-table"><tbody>';

        echo '<tr><th><label for="ks-price-note">' . esc_html__('Additional price text', 'kontor-shop') . '</label></th><td>';
        echo '<input type="text" id="ks-price-note" name="price_note" class="large-text" value="' . esc_attr($text('price_note')) . '" />';
        echo '<p class="description">' . esc_html__('Optional extra text above the order button. The VAT line (“incl. VAT” or the note for tax exemption) is generated automatically from the “Payment & VAT” section.', 'kontor-shop') . '</p></td></tr>';

        echo '<tr><th><label for="ks-button-label">' . esc_html__('Order button text', 'kontor-shop') . '</label></th><td>';
        echo '<input type="text" id="ks-button-label" name="order_button_label" class="regular-text" value="' . esc_attr($text('order_button_label')) . '" />';
        echo '<p class="description">' . esc_html__('Label of the last button in the checkout (in Austria, it must be clearly recognizable that the order requires payment, e.g. “Order with obligation to pay”).', 'kontor-shop') . '</p></td></tr>';

        echo '</tbody></table>';

        echo '<h2>' . esc_html__('4. Privacy & retention', 'kontor-shop') . '</h2>';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="ks-retention">' . esc_html__('Retention period for order data (years)', 'kontor-shop') . '</label></th><td>';
        echo '<input type="number" id="ks-retention" name="retention_years" class="small-text" min="0" max="30" value="'
            . esc_attr((string) $this->settings->get('privacy.retention_years', 7)) . '" /> ' . esc_html__('years', 'kontor-shop');
        echo '<p class="description">' . esc_html__(
            'Order and invoice data are accounting records (in Austria usually 7 years). Nothing is ever deleted automatically. If someone requests erasure via WordPress → Tools → Erase Personal Data, their orders are only anonymized after this period has passed. You can find suggested text for the privacy policy under Settings → Privacy.',
            'kontor-shop'
        ) . '</p></td></tr>';
        echo '</tbody></table>';

        $this->savebar();
        echo '</form>';
    }
}
