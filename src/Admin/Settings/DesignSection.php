<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin\Settings;

use Kontor\Shop\Admin\MediaPicker;
use Kontor\Shop\Infrastructure\Frontend\ThemePalette;
use Kontor\Shop\Infrastructure\Media\ManagedImages;
use Kontor\Shop\Modules\Storefront\DesignModule;
use Kontor\Shop\Support\Color;

/**
 * Wie der Shop fuer Kunden aussieht: Farben, Warenkorb-Button, Suche, Platzhalterbild.
 */
final class DesignSection extends Section
{
    public function key(): string
    {
        return 'design';
    }

    public function label(): string
    {
        return __('Design', 'kontor-shop');
    }

    public function group(): string
    {
        return self::GROUP_SHOP;
    }

    public function save(string $tab): ?string
    {
        return match ($tab) {
            'design' => $this->save_design(),
            default => null,
        };
    }

    public function render(): void
    {
        $this->render_design_tab();
    }

    private function save_design(): ?string
    {
        $primary_color = Color::sanitize_hex(sanitize_text_field(wp_unslash((string) ($_POST['primary_color'] ?? '')))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request

        $old_placeholder = absint($this->settings->get('design.placeholder_image_id', 0));
        $new_placeholder = $this->sanitize_attachment_id(absint($_POST['placeholder_image_id'] ?? 0)); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request

        $cart_icon_color = Color::sanitize_hex(sanitize_text_field(wp_unslash((string) ($_POST['cart_icon_color'] ?? '')))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request

        /**
         * Filter kontor_shop/settings/design/save: eigene Felder aus
         * kontor_shop/settings/design/fields mitspeichern (Werte aus $_POST
         * selbst bereinigen; Nonce und Recht sind geprueft), z. B. Pro:
         * "Artikel pro Kategorieseite". Die eingebauten Schluessel lassen
         * sich nicht ueberschreiben. Standard: nur die eingebauten - ohne
         * Erweiterung bleiben deren gespeicherte Werte unberuehrt.
         *
         * @param array<string, mixed> $values zusaetzliche Werte unter catalog.*
         */
        $extra = apply_filters('kontor_shop/settings/design/save', []);

        $this->settings->save(['design' => [
            'primary_color'             => $primary_color,
            'show_floating_cart_button' => !empty($_POST['show_floating_cart_button']), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
            'cart_icon_color'           => $cart_icon_color,
            'hide_cart_icon_when_empty' => !empty($_POST['hide_cart_icon_when_empty']), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
            'placeholder_image_id'      => $new_placeholder,
            'card_effect'               => DesignModule::sanitize_card_effect(sanitize_text_field(wp_unslash((string) ($_POST['card_effect'] ?? '')))), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
        ], 'catalog' => [
            'search_enabled'            => !empty($_POST['search_enabled']), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
        ] + (is_array($extra) ? $extra : [])]);

        ManagedImages::claim($new_placeholder);

        if ($old_placeholder > 0 && $old_placeholder !== $new_placeholder && ManagedImages::release($old_placeholder, $this->settings, null)) {
            $this->warnings[] = __(
                'The previous placeholder image is no longer used. You can delete it from the media library under Products → "Unused images".',
                'kontor-shop'
            );
        }

        return __('Settings saved.', 'kontor-shop');
    }

    private function render_design_tab(): void
    {
        $current    = (string) $this->settings->get('design.primary_color', '');
        $suggestion = $current === '' ? ThemePalette::suggest_primary_color() : '';
        $show_float      = (bool) $this->settings->get('design.show_floating_cart_button', true);
        $cart_icon_color = (string) $this->settings->get('design.cart_icon_color', '');
        $hide_when_empty = (bool) $this->settings->get('design.hide_cart_icon_when_empty', false);

        $this->form_open('design');

        $this->intro(__('Here you decide how the shop looks to customers: main color, cart button, search, and the image for products without a photo.', 'kontor-shop'));

        echo '<table class="form-table"><tbody>';

        echo '<tr><th><label for="ks-primary-color">' . esc_html__('Main shop color', 'kontor-shop') . '</label></th><td>';
        echo '<input type="color" id="ks-primary-color" name="primary_color" value="'
            . esc_attr($current !== '' ? $current : ($suggestion !== '' ? $suggestion : '#2271b1')) . '" />';

        if ($suggestion !== '') {
            echo '<p class="description">' . sprintf(
                /* translators: %s: hex color value suggested by the theme */
                esc_html__('Suggestion from the active theme’s color palette: %s (applied, change it here if needed).', 'kontor-shop'),
                esc_html($suggestion)
            ) . '</p>';
        } else {
            echo '<p class="description">' . esc_html__(
                'Used for buttons/accents in the catalog, cart, and checkout, unless a category has its own accent color (“Categories” menu).',
                'kontor-shop'
            ) . '</p>';
        }

        echo '</td></tr>';

        echo '<tr><th>' . esc_html__('Floating cart button', 'kontor-shop') . '</th><td>';
        echo '<label><input type="checkbox" name="show_floating_cart_button" value="1" '
            . checked($show_float, true, false) . ' /> '
            . esc_html__('show (bottom right, on all pages)', 'kontor-shop') . '</label>';
        echo '<p class="description">' . esc_html__(
            'Turn this off if you place the cart icon in your theme yourself - shortcode: [kontor_shop_cart_icon].',
            'kontor-shop'
        ) . '</p>';
        echo '<p><label><input type="checkbox" name="hide_cart_icon_when_empty" value="1" '
            . checked($hide_when_empty, true, false) . ' /> '
            . esc_html__('Only show once there are products in the cart', 'kontor-shop') . '</label></p>';
        echo '<p class="description">' . esc_html__(
            'When the cart is empty, the button stays hidden and appears automatically as soon as a product is added.',
            'kontor-shop'
        ) . '</p></td></tr>';

        echo '<tr><th><label for="ks-cart-icon-color">' . esc_html__('Cart button color', 'kontor-shop') . '</label></th><td>';
        echo '<input type="color" id="ks-cart-icon-color" name="cart_icon_color" value="'
            . esc_attr($cart_icon_color !== '' ? $cart_icon_color : ($current !== '' ? $current : ($suggestion !== '' ? $suggestion : '#2271b1'))) . '" />';
        echo '<p class="description">' . esc_html__(
            'Leave empty to use the shop’s main color.',
            'kontor-shop'
        ) . '</p></td></tr>';

        echo '<tr><th>' . esc_html__('Product search', 'kontor-shop') . '</th><td>';
        echo '<label><input type="checkbox" name="search_enabled" value="1" '
            . checked((bool) $this->settings->get('catalog.search_enabled', true), true, false) . ' /> '
            . esc_html__('Show search field in the catalog', 'kontor-shop') . '</label></td></tr>';

        /**
         * Aktion kontor_shop/settings/design/fields: eigene Zeilen im
         * Formular "Design" nach "Artikelsuche" (<tr><th>…</th><td>…</td></tr>),
         * z. B. Pro: "Artikel pro Kategorieseite". Gespeichert wird ueber den
         * Filter kontor_shop/settings/design/save.
         *
         * @param Repository $settings Einstellungen
         */
        do_action('kontor_shop/settings/design/fields', $this->settings);

        $card_effect = DesignModule::sanitize_card_effect((string) $this->settings->get('design.card_effect', 'spotlight'));
        echo '<tr><th>' . esc_html__('Card effect', 'kontor-shop') . '</th><td><fieldset>';
        foreach ([
            'spotlight' => __('Spotlight: the border glows in the accent color where the mouse is', 'kontor-shop'),
            'lift'      => __('Lift: the card lifts slightly on hover', 'kontor-shop'),
            'none'      => __('Calm: no effect', 'kontor-shop'),
        ] as $value => $label) {
            echo '<label class="ks-radio-line"><input type="radio" name="card_effect" value="' . esc_attr($value) . '" '
                . checked($card_effect, $value, false) . ' /> ' . esc_html($label) . '</label>';
        }
        echo '<p class="description">' . esc_html__(
            'Only works on a computer with a mouse; on mobile the cards stay calm.',
            'kontor-shop'
        ) . '</p></fieldset></td></tr>';

        echo '<tr><th>' . esc_html__('Placeholder image', 'kontor-shop') . '</th><td>';
        MediaPicker::render('placeholder_image_id', absint($this->settings->get('design.placeholder_image_id', 0)), __('Choose image', 'kontor-shop'));
        echo '<p class="description">' . esc_html__(
            'Shown for products without their own photo (e.g. your logo). Empty = neutral icon.',
            'kontor-shop'
        ) . '</p></td></tr>';

        echo '</tbody></table>';

        $this->savebar();
        echo '</form>';
    }

    private function sanitize_attachment_id(mixed $value): int
    {
        $id = absint($value);

        return $id > 0 && wp_attachment_is_image($id) ? $id : 0;
    }
}
