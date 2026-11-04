<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin\Settings;

use Kontor\Shop\Admin\HelpCenter\HelpCenter;
use Kontor\Shop\Admin\MediaPicker;
use Kontor\Shop\Infrastructure\Frontend\ThemeStyles;
use Kontor\Shop\Infrastructure\Media\ManagedImages;
use Kontor\Shop\Modules\Storefront\DesignCss;
use Kontor\Shop\Modules\Storefront\DesignModule;
use Kontor\Shop\Support\Color;

/**
 * Wie der Shop fuer Kunden aussieht: Grundstil, Farben, Farbschema, Ecken,
 * Raster, Bildformat, Schrift, Karten-Effekt, Suche, Platzhalterbild und
 * eigenes CSS - mit Live-Vorschau der echten Shop-Seite im Rahmen
 * (assets/admin/design.js, Modules\Storefront\DesignModule).
 *
 * Farben: "vom Theme" speichert bewusst einen leeren Wert. Ein
 * <input type="color"> kann nicht leer sein; bis 2.0.12 wurde deshalb bei
 * jedem Speichern eine feste Farbe eingetragen, die das Theme ueberstimmte.
 */
final class DesignSection extends Section
{
    /** Farbe, die fruehere Versionen ohne Zutun gespeichert haben (Standard des Farbfelds). */
    private const LEGACY_DEFAULT = '#2271b1';

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

    private function post(string $key): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
        return sanitize_text_field(wp_unslash((string) ($_POST[$key] ?? '')));
    }

    private function save_design(): ?string
    {
        $design = DesignCss::sanitize([
            'primary_color'   => $this->post('primary_mode') === 'own' ? $this->post('primary_color') : '',
            'cart_icon_color' => $this->post('cart_mode') === 'own' ? $this->post('cart_icon_color') : '',
            'style'           => $this->post('style'),
            'radius'          => $this->post('radius_custom') !== '' ? $this->post('radius') : '',
            'scheme'          => $this->post('scheme'),
            'columns'         => $this->post('columns'),
            'image_ratio'     => $this->post('image_ratio'),
            'heading_font'    => $this->post('heading_font'),
        ]);

        $old_placeholder = absint($this->settings->get('design.placeholder_image_id', 0));
        $new_placeholder = $this->sanitize_attachment_id(absint($_POST['placeholder_image_id'] ?? 0)); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request

        $design += [
            'show_floating_cart_button' => !empty($_POST['show_floating_cart_button']), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
            'hide_cart_icon_when_empty' => !empty($_POST['hide_cart_icon_when_empty']), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
            'placeholder_image_id'      => $new_placeholder,
            'card_effect'               => DesignModule::sanitize_card_effect($this->post('card_effect')),
        ];

        // Eigenes CSS nur mit dem WordPress-Recht dafuer (wie "Zusaetzliches CSS" im Customizer); sonst bleibt der gespeicherte Wert.
        if (current_user_can('edit_css') && isset($_POST['custom_css'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
            $design['custom_css'] = DesignCss::sanitize_custom_css((string) wp_unslash($_POST['custom_css'])); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce geprueft; sanitize_custom_css entfernt Tags
        }

        /**
         * Filter kontor_shop/settings/design/save: eigene Felder aus
         * kontor_shop/settings/design/fields mitspeichern (Werte aus $_POST
         * selbst bereinigen; Nonce und Recht sind geprueft), z. B. Pro:
         * "Artikel pro Kategorieseite". Rueckgabe: Werte unter catalog.*, die
         * sich nicht ueberschreiben. Standard: nur die eingebauten - ohne
         * Erweiterung bleiben deren gespeicherte Werte unberuehrt.
         *
         * @param array<string, mixed> $values zusaetzliche Werte unter catalog.*
         */
        $extra = apply_filters('kontor_shop/settings/design/save', []);

        $this->settings->save(['design' => $design, 'catalog' => [
            'search_enabled' => !empty($_POST['search_enabled']), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
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

    /**
     * Auswahl als Knopfreihe (Radio-Buttons, ohne JavaScript bedienbar).
     *
     * @param array<string, string> $options
     */
    private function segmented(string $name, array $options, string $current, string $label): void
    {
        echo '<div class="ks-seg" role="radiogroup" aria-label="' . esc_attr($label) . '">';

        foreach ($options as $value => $text) {
            echo '<label class="ks-seg__item"><input type="radio" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" '
                . checked($current, $value, false) . ' data-ks-design /><span>' . esc_html($text) . '</span></label>';
        }

        echo '</div>';
    }

    private function row_open(string $title, string $for = ''): void
    {
        echo '<tr><th>' . ($for !== '' ? '<label for="' . esc_attr($for) . '">' . esc_html($title) . '</label>' : esc_html($title)) . '</th><td>';
    }

    private function swatch(string $hex): string
    {
        return '<span class="ks-swatch" style="background:' . esc_attr($hex) . '"></span><code>' . esc_html($hex) . '</code>';
    }

    private function render_design_tab(): void
    {
        $design = DesignCss::sanitize((array) $this->settings->get('design', []));
        $theme  = ThemeStyles::detect();

        $primary         = $design['primary_color'];
        $cart_color      = $design['cart_icon_color'];
        // Gleiche Farbe wie die Hauptfarbe stammt fast immer aus dem alten Formular: als "wie Hauptfarbe" anzeigen.
        $cart_own        = $cart_color !== '' && strcasecmp($cart_color, $primary) !== 0;
        $theme_primary   = (string) ($theme['primary_hex'] ?? '');
        $show_float      = (bool) $this->settings->get('design.show_floating_cart_button', true);
        $hide_when_empty = (bool) $this->settings->get('design.hide_cart_icon_when_empty', false);
        $shop_url        = (string) (get_permalink(absint($this->settings->get('general.shop_page_id', 0))) ?: '');

        $this->enqueue_assets($shop_url);

        echo '<div class="ks-design">';
        echo '<div class="ks-design__form">';
        $this->form_open('design', 'ks-design-form');

        $this->intro(__('Here you decide how the shop looks to customers. Start with “Like my theme”, check the preview and only change what doesn’t fit. Changes apply after saving.', 'kontor-shop'));

        if ($shop_url !== '') {
            echo '<p class="ks-design__jump"><a href="#ks-design-preview">' . esc_html__('Jump to the preview ↓', 'kontor-shop') . '</a></p>';
        }

        echo '<table class="form-table ks-design-table"><tbody>';

        // Grundstil
        $this->row_open(__('Basic style', 'kontor-shop'));
        $this->segmented('style', [
            'theme'  => __('Like my theme', 'kontor-shop'),
            'clear'  => __('Clear', 'kontor-shop'),
            'soft'   => __('Soft', 'kontor-shop'),
            'square' => __('Angular', 'kontor-shop'),
        ], $design['style'], __('Basic style', 'kontor-shop'));
        echo '<p class="description">' . esc_html__('Sets corners and shadows. “Like my theme” takes what your theme provides; the other styles are ready-made looks. You can still change every setting below.', 'kontor-shop') . '</p>';
        echo '</td></tr>';

        // Hauptfarbe
        $this->row_open(__('Main shop color', 'kontor-shop'));
        echo '<fieldset class="ks-color-choice">';
        echo '<label><input type="radio" name="primary_mode" value="theme" ' . checked($primary === '', true, false) . ' data-ks-design /> ' . esc_html__('from the theme', 'kontor-shop') . '</label>';
        echo '<label><input type="radio" name="primary_mode" value="own" ' . checked($primary !== '', true, false) . ' data-ks-design /> ' . esc_html__('own color:', 'kontor-shop') . '</label> ';
        echo '<input type="color" id="ks-primary-color" name="primary_color" value="' . esc_attr($primary !== '' ? $primary : ($theme_primary !== '' ? $theme_primary : self::LEGACY_DEFAULT)) . '" data-ks-design data-ks-mode="primary_mode" aria-label="' . esc_attr__('Own main color', 'kontor-shop') . '" />';
        echo '</fieldset>';

        if ($theme_primary !== '') {
            echo '<p class="description">' . esc_html__('Your theme uses:', 'kontor-shop') . ' ' . $this->swatch($theme_primary) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- swatch() escaped
        } else {
            echo '<p class="description">' . esc_html__('Your theme doesn’t reveal a color to WordPress. With “from the theme”, the shop uses the color your theme sets for Kontor Shop (help: Design → For web developers), otherwise a neutral blue.', 'kontor-shop') . '</p>';
        }

        if ($primary !== '' && (strcasecmp($primary, self::LEGACY_DEFAULT) === 0 || ($theme_primary !== '' && strcasecmp($primary, $theme_primary) === 0))) {
            echo '<div class="ks-design__legacy notice notice-warning inline"><p>' . esc_html__('A fixed color is saved here. Earlier versions entered it automatically when saving – it overrides your theme’s color, even if the theme changes later.', 'kontor-shop')
                . ' <button type="button" class="button button-small" data-ks-use-theme>' . esc_html__('Use theme color', 'kontor-shop') . '</button></p></div>';
        }

        echo '<p class="ks-contrast" data-ks-contrast aria-live="polite"></p>';
        echo '<p class="description">' . esc_html__('Used for buttons, active filters and links in the catalog, cart and checkout, unless a category has its own accent color (“Categories” menu). The text on buttons automatically turns white or dark so it stays readable.', 'kontor-shop') . '</p>';
        echo '</td></tr>';

        // Farbschema
        $this->row_open(__('Color scheme', 'kontor-shop'));
        $this->segmented('scheme', [
            'theme' => __('Like my theme', 'kontor-shop'),
            'light' => __('Light', 'kontor-shop'),
            'dark'  => __('Dark', 'kontor-shop'),
        ], $design['scheme'], __('Color scheme', 'kontor-shop'));

        if (isset($theme['dark'])) {
            echo '<p class="description">' . esc_html($theme['dark']
                ? __('Detected: your theme has a dark background – with “Like my theme”, cards, cart and checkout are dark too.', 'kontor-shop')
                : __('Detected: your theme has a light background.', 'kontor-shop')) . '</p>';
        } else {
            echo '<p class="description">' . esc_html__('Choose “Dark” if your website has a dark background. Your theme doesn’t reveal its background, so “Like my theme” stays light.', 'kontor-shop') . '</p>';
        }
        echo '</td></tr>';

        // Ecken
        $this->row_open(__('Corners', 'kontor-shop'), 'ks-radius');
        echo '<label><input type="checkbox" name="radius_custom" value="1" ' . checked($design['radius'] !== '', true, false) . ' data-ks-design /> ' . esc_html__('Own rounding', 'kontor-shop') . '</label>';
        echo '<div class="ks-range"><input type="range" id="ks-radius" name="radius" min="0" max="' . (int) DesignCss::MAX_RADIUS . '" step="1" value="' . esc_attr($design['radius'] !== '' ? $design['radius'] : '12') . '" data-ks-design data-ks-mode="radius_custom" />'
            . '<output for="ks-radius" data-ks-radius-out>' . esc_html($design['radius'] !== '' ? $design['radius'] : '12') . ' px</output></div>';
        echo '<p class="description">' . esc_html__('0 = square corners. Without “Own rounding”, the basic style decides.', 'kontor-shop') . '</p>';
        echo '</td></tr>';

        // Raster
        $this->row_open(__('Products per row', 'kontor-shop'));
        $this->segmented('columns', [
            'auto' => __('Automatic', 'kontor-shop'),
            '2'    => '2',
            '3'    => '3',
            '4'    => '4',
        ], $design['columns'], __('Products per row', 'kontor-shop'));
        echo '<p class="description">' . esc_html__('Maximum on a wide screen. Narrower screens show fewer; phones always show one product per row.', 'kontor-shop') . '</p>';
        echo '</td></tr>';

        // Bildformat
        $this->row_open(__('Image format', 'kontor-shop'));
        $this->segmented('image_ratio', [
            '4/3' => __('Landscape 4:3', 'kontor-shop'),
            '1/1' => __('Square', 'kontor-shop'),
            '3/4' => __('Portrait 3:4', 'kontor-shop'),
        ], $design['image_ratio'], __('Image format', 'kontor-shop'));
        echo '<p class="description">' . esc_html__('Product photos are cropped to this format in the catalog. “Portrait” suits clothing, “Square” suits mixed photos. The product page always shows the whole image.', 'kontor-shop') . '</p>';
        echo '</td></tr>';

        // Schrift
        $this->row_open(__('Font of product names', 'kontor-shop'));
        $this->segmented('heading_font', [
            'theme' => __('Like the theme’s headings', 'kontor-shop'),
            'body'  => __('Like the body text', 'kontor-shop'),
        ], $design['heading_font'], __('Font of product names', 'kontor-shop'));

        if (!isset($theme['heading_font'])) {
            echo '<p class="description">' . esc_html__('Your theme doesn’t reveal its heading font to WordPress, so product names use the body text font. A web developer can set it (help: Design → For web developers).', 'kontor-shop') . '</p>';
        }
        echo '</td></tr>';

        // Karten-Effekt
        $card_effect = DesignModule::sanitize_card_effect((string) $this->settings->get('design.card_effect', 'spotlight'));
        $this->row_open(__('Card effect', 'kontor-shop'));
        echo '<fieldset>';
        foreach ([
            'spotlight' => __('Spotlight: the border glows in the accent color where the mouse is', 'kontor-shop'),
            'lift'      => __('Lift: the card lifts slightly on hover', 'kontor-shop'),
            'none'      => __('Calm: no effect', 'kontor-shop'),
        ] as $value => $label) {
            echo '<label class="ks-radio-line"><input type="radio" name="card_effect" value="' . esc_attr($value) . '" '
                . checked($card_effect, $value, false) . ' data-ks-design /> ' . esc_html($label) . '</label>';
        }
        echo '<p class="description">' . esc_html__(
            'Only works on a computer with a mouse; on mobile the cards stay calm.',
            'kontor-shop'
        ) . '</p></fieldset></td></tr>';

        // Warenkorb-Button
        $this->row_open(__('Floating cart button', 'kontor-shop'));
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
        ) . '</p>';
        echo '<fieldset class="ks-color-choice"><legend class="screen-reader-text">' . esc_html__('Cart button color', 'kontor-shop') . '</legend>';
        echo '<span class="ks-color-choice__label">' . esc_html__('Color:', 'kontor-shop') . '</span> ';
        echo '<label><input type="radio" name="cart_mode" value="primary" ' . checked(!$cart_own, true, false) . ' data-ks-design /> ' . esc_html__('like the main color', 'kontor-shop') . '</label>';
        echo '<label><input type="radio" name="cart_mode" value="own" ' . checked($cart_own, true, false) . ' data-ks-design /> ' . esc_html__('own color:', 'kontor-shop') . '</label> ';
        echo '<input type="color" id="ks-cart-icon-color" name="cart_icon_color" value="' . esc_attr($cart_own ? $cart_color : ($primary !== '' ? $primary : ($theme_primary !== '' ? $theme_primary : self::LEGACY_DEFAULT))) . '" data-ks-design data-ks-mode="cart_mode" aria-label="' . esc_attr__('Own cart button color', 'kontor-shop') . '" />';
        echo '</fieldset></td></tr>';

        // Suche
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

        // Platzhalterbild
        echo '<tr><th>' . esc_html__('Placeholder image', 'kontor-shop') . '</th><td>';
        MediaPicker::render('placeholder_image_id', absint($this->settings->get('design.placeholder_image_id', 0)), __('Choose image', 'kontor-shop'));
        echo '<p class="description">' . esc_html__(
            'Shown for products without their own photo (e.g. your logo). Empty = neutral icon.',
            'kontor-shop'
        ) . '</p></td></tr>';

        echo '</tbody></table>';

        $this->render_developer_box();

        $this->savebar();
        echo '</form>';
        echo '</div>';

        $this->render_preview($shop_url);

        echo '</div>';
    }

    private function render_developer_box(): void
    {
        $custom   = (string) $this->settings->get('design.custom_css', '');
        $can_edit = current_user_can('edit_css');

        echo '<details class="ks-design__dev"' . ($custom !== '' ? ' open' : '') . '>';
        echo '<summary>' . esc_html__('For web developers: adjust CSS yourself', 'kontor-shop') . '</summary>';
        echo '<p>' . esc_html__('The settings above produce the block below. You can copy it into the style.css of your child theme and then choose “Like my theme” here, or add your own rules in the “Custom CSS” field.', 'kontor-shop') . ' '
            . '<a href="' . esc_url(HelpCenter::url('design-css')) . '">' . esc_html__('All variables, class names and examples', 'kontor-shop') . '</a></p>';
        echo '<pre class="ks-design__snippet" data-ks-snippet></pre>';
        echo '<p><button type="button" class="button" data-ks-copy-snippet>' . esc_html__('Copy CSS', 'kontor-shop') . '</button></p>';

        echo '<p><label for="ks-custom-css"><strong>' . esc_html__('Custom CSS', 'kontor-shop') . '</strong></label></p>';

        if ($can_edit) {
            echo '<textarea id="ks-custom-css" name="custom_css" rows="6" class="large-text code" spellcheck="false" data-ks-design placeholder=".kontor-shop-product__name { letter-spacing: .01em; }">' . esc_textarea($custom) . '</textarea>';
            echo '<p class="description">' . esc_html__('Loaded right after the shop styles on every page. Start your rules with a .kontor-shop-… class so they only affect the shop. HTML tags are removed.', 'kontor-shop') . '</p>';
        } else {
            echo '<p class="description">' . esc_html__('Only users who may edit CSS in WordPress (usually administrators) can change this field.', 'kontor-shop') . '</p>';

            if ($custom !== '') {
                echo '<pre class="ks-design__snippet">' . esc_html($custom) . '</pre>';
            }
        }

        echo '</details>';
    }

    private function render_preview(string $shop_url): void
    {
        echo '<div class="ks-design__preview" id="ks-design-preview">';
        echo '<div class="ks-design__bar"><strong>' . esc_html__('Preview', 'kontor-shop') . '</strong>';

        if ($shop_url === '') {
            echo '</div><p class="description">' . esc_html__('The shop page (with the shortcode [kontor_shop_catalog]) is missing, so there is no preview. Deactivating and reactivating Kontor Shop creates it again.', 'kontor-shop') . '</p></div>';

            return;
        }

        echo '<span class="ks-seg ks-seg--small" role="radiogroup" aria-label="' . esc_attr__('Preview width', 'kontor-shop') . '">'
            . '<label class="ks-seg__item"><input type="radio" name="ks_preview_width" value="desktop" checked /><span>' . esc_html__('Computer', 'kontor-shop') . '</span></label>'
            . '<label class="ks-seg__item"><input type="radio" name="ks_preview_width" value="phone" /><span>' . esc_html__('Phone', 'kontor-shop') . '</span></label>'
            . '</span>';
        echo '<a href="' . esc_url($shop_url) . '" target="_blank" rel="noopener">' . esc_html__('Open shop', 'kontor-shop') . ' ↗</a>';
        echo '</div>';
        echo '<div class="ks-design__frame-wrap"><iframe class="ks-design__frame" data-ks-preview-frame title="' . esc_attr__('Preview of the shop page with the current settings', 'kontor-shop') . '" src="'
            . esc_url(add_query_arg(DesignModule::PREVIEW_PARAM, '1', $shop_url)) . '" loading="lazy"></iframe></div>';
        echo '<p class="description">' . esc_html__('Shows your real shop page with the settings on the left – before saving. You can click through it; nothing is ordered unless you complete the checkout.', 'kontor-shop') . '</p>';
        echo '</div>';
    }

    private function enqueue_assets(string $shop_url): void
    {
        wp_enqueue_script('kontor-shop-design', KONTOR_SHOP_URL . 'assets/admin/design.js', [], KONTOR_SHOP_VERSION, true);
        wp_localize_script('kontor-shop-design', 'kontorShopDesign', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action'  => DesignModule::AJAX_ACTION,
            'nonce'   => wp_create_nonce(DesignModule::AJAX_ACTION),
            'origin'  => $shop_url !== '' ? (string) wp_parse_url($shop_url, PHP_URL_SCHEME) . '://' . (string) wp_parse_url($shop_url, PHP_URL_HOST) . (wp_parse_url($shop_url, PHP_URL_PORT) ? ':' . (int) wp_parse_url($shop_url, PHP_URL_PORT) : '') : '',
            'i18n'    => [
                /* translators: 1: "white" or "dark", 2: contrast ratio, e.g. 7.9 */
                'contrastGood' => __('Text on buttons: %1$s · contrast %2$s : 1 – easy to read.', 'kontor-shop'),
                /* translators: 1: "white" or "dark", 2: contrast ratio, e.g. 3.1 */
                'contrastBad'  => __('Text on buttons: %1$s · contrast only %2$s : 1 – hard to read. Choose a darker or lighter color.', 'kontor-shop'),
                'white'        => __('white', 'kontor-shop'),
                'dark'         => __('dark', 'kontor-shop'),
                'copied'       => __('Copied.', 'kontor-shop'),
                'copyManual'   => __('Text selected – now copy with Ctrl/⌘+C.', 'kontor-shop'),
                'px'           => 'px',
            ],
            'darkText' => Color::DARK_TEXT,
        ]);
    }

    private function sanitize_attachment_id(mixed $value): int
    {
        $id = absint($value);

        return $id > 0 && wp_attachment_is_image($id) ? $id : 0;
    }
}
