<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin\Settings;

use Kontor\Shop\Infrastructure\Payment\BankAccounts;
use Kontor\Shop\Modules\Shipping\ShippingRates;
use Kontor\Shop\Modules\Shipping\SplitShipping;
use Kontor\Shop\Settings\Repository;
use Kontor\Shop\Support\Countries;

/**
 * Einstellungen → Versand (mit Pro "Versand & Rueckgabe"), seit 2.9.14 in
 * Schritten statt einer langen Liste:
 *  1. Wohin liefert ihr? Inland, Auslandslaender als Kaertchen, Selbstabholung.
 *  2. Was kostet der Versand? Preise Inland/Ausland (Pro: Spalte Mitglieder),
 *     "Versandkostenfrei ab" mit Live-Vorschau "So rechnet die Kasse"
 *     (assets/admin/shipping-settings.js).
 *  3. Karten von Erweiterungen (Pro: Lieferzeit & Rueckgabe), ein Speichern.
 *  Danach eingeklappt: Versand bei aufgeteilten Bestellungen.
 * Telefonnummer, Rechnungsadresse und Bestellstatus-Link: CheckoutSection.
 */
final class ShippingSection extends Section
{
    /** EU-Laender aus Countries (Knopf "Alle EU-Laender"). */
    private const EU = ['AT', 'DE', 'IT', 'FR', 'NL', 'BE', 'LU', 'CZ', 'SK', 'HU', 'SI', 'HR', 'PL', 'ES', 'PT', 'DK', 'SE', 'FI', 'IE'];

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

    public function description(): string
    {
        return $this->embedded_forms() !== []
            ? __('Where you deliver, what it costs and how long it takes. The preview shows right away what customers pay at checkout.', 'kontor-shop')
            : __('Where you deliver and what it costs. The preview shows right away what customers pay at checkout.', 'kontor-shop');
    }

    public function save(string $tab): ?string
    {
        if ($tab !== 'shipping') {
            return null;
        }

        $this->save_shipping();

        foreach ($this->embedded_forms() as $form) {
            $form->save_embedded();
        }

        return __('Settings saved.', 'kontor-shop');
    }

    public function render(): void
    {
        $this->form_open('shipping', 'ks-ship');

        $step = 0;

        $this->card_open(++$step, __('Where do you deliver?', 'kontor-shop'));
        $this->render_where();
        echo '</section>';

        $this->card_open(++$step, __('What does shipping cost?', 'kontor-shop'));
        $this->render_costs();
        echo '</section>';

        foreach ($this->embedded_forms() as $form) {
            $this->card_open(++$step, $form->label(), $form->description());
            $form->render_embedded();
            echo '</section>';
        }

        $this->render_split();

        echo '<p class="ks-ship-moved">' . self::icon_info() . '<span>' . sprintf(
            /* translators: %s: link "Sell › Checkout" */
            esc_html__('Phone number, billing address and the order status link have moved to %s.', 'kontor-shop'),
            '<a href="' . esc_url(self::url('checkout')) . '">' . esc_html__('Sell › Checkout', 'kontor-shop') . '</a>'
        ) . '</span></p>';

        $this->savebar();
        echo '</form>';
    }

    private function save_shipping(): void
    {
        $post       = static fn (string $key, string $default = ''): string => sanitize_text_field(wp_unslash((string) ($_POST[$key] ?? $default))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
        $split_mode = $post('split_mode');
        $home       = $this->sanitize_country($post('home_country')) ?: ShippingRates::home_country($this->settings);
        $abroad     = !empty($_POST['abroad_on']) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
            ? array_map('sanitize_text_field', (array) wp_unslash($_POST['countries'] ?? [])) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
            : [];

        /**
         * Filter kontor_shop/settings/shipping/save: eigene Felder aus
         * kontor_shop/settings/shipping/fields bzw. .../rate_columns
         * mitspeichern (Werte aus $_POST selbst bereinigen; Nonce und Recht
         * sind geprueft), z. B. Pro: Versandpreise fuer Mitglieder. Die
         * eingebauten Schluessel lassen sich nicht ueberschreiben. Standard:
         * nur die eingebauten - ohne Erweiterung bleiben deren gespeicherte
         * Werte unberuehrt.
         *
         * @param array<string, mixed> $values zusaetzliche Werte unter shipping.*
         */
        $extra = apply_filters('kontor_shop/settings/shipping/save', []);

        $this->settings->save(['shipping' => [
            'home_country'      => $home,
            'flat_rate'         => ShippingRates::parse_input($post('flat_rate', '0')) ?? 0.0,
            // Leer = wie Inland.
            'flat_rate_abroad'  => ShippingRates::parse_input($post('flat_rate_abroad')),
            // Ohne Haekchen keine Grenze (0).
            'free_from_amount'  => !empty($_POST['free_on']) ? (ShippingRates::parse_input($post('free_from_amount')) ?? 0.0) : 0.0, // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
            'free_abroad'       => !empty($_POST['free_abroad']) || empty($_POST['free_abroad_shown']), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
            'allow_self_pickup' => !empty($_POST['allow_self_pickup']), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
            'split_mode'        => in_array($split_mode, SplitShipping::modes(), true) ? $split_mode : SplitShipping::SEPARATE,
            'split_flat_rate'   => ShippingRates::parse_input($post('split_flat_rate', '0')) ?? 0.0,
        ] + (is_array($extra) ? $extra : []), 'checkout' => [
            'countries' => self::collect_countries($home, $abroad),
        ]]);
    }

    private function card_open(int $step, string $title, string $note = ''): void
    {
        echo '<section class="ks-ship-card" aria-labelledby="ks-ship-step-' . esc_attr((string) $step) . '"><div class="ks-ship-card__head">'
            . '<span class="ks-ship-card__step" aria-hidden="true">' . esc_html((string) $step) . '</span>'
            . '<h3 id="ks-ship-step-' . esc_attr((string) $step) . '">' . esc_html($title) . '</h3>'
            . ($note !== '' ? '<span class="ks-ship-card__note">– ' . esc_html($note) . '</span>' : '')
            . '</div>';
    }

    private function render_where(): void
    {
        $home     = ShippingRates::home_country($this->settings);
        $selected = ShippingRates::abroad_countries($this->settings);
        $abroad   = $selected !== [];

        echo '<div class="ks-ship-row"><label for="ks-home-country" class="ks-ship-row__label">' . esc_html__('Your domestic country', 'kontor-shop') . '</label>'
            . '<select id="ks-home-country" name="home_country" data-ks-home>';

        foreach (Countries::all() as $code => $name) {
            echo '<option value="' . esc_attr($code) . '" ' . selected($home, $code, false) . '>' . esc_html($name) . '</option>';
        }

        echo '</select><span class="ks-ship-row__hint">' . esc_html__('Preselected at checkout.', 'kontor-shop') . '</span></div>';

        echo '<label class="ks-check-line"><input type="checkbox" name="abroad_on" value="1" data-ks-abroad-on ' . checked($abroad, true, false) . ' /> <strong>' . esc_html__('Also deliver abroad', 'kontor-shop') . '</strong></label>';

        // Ohne JavaScript: Haekchen in Kaertchen; mit JavaScript wandern sie zwischen "gewaehlt" und "hinzufuegen".
        echo '<div class="ks-abroad" data-ks-abroad' . ($abroad ? '' : ' hidden') . '><div class="ks-chips" data-ks-chips>';

        foreach ($selected as $code) {
            echo $this->chip($code, true, false); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- chip() escaped
        }

        echo '<details class="ks-chip-add" data-ks-add><summary class="ks-chip-add__toggle">' . esc_html__('+ Add country', 'kontor-shop') . '</summary>'
            . '<div class="ks-chip-add__panel"><div class="ks-chips" data-ks-pool>';

        foreach (array_keys(Countries::all()) as $code) {
            if (!in_array($code, $selected, true)) {
                echo $this->chip($code, false, $code === $home); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- chip() escaped
            }
        }

        echo '</div><p class="ks-chip-add__actions"><button type="button" class="button-link" data-ks-eu data-codes="' . esc_attr(implode(',', self::EU)) . '">'
            . esc_html__('All EU countries at once', 'kontor-shop') . '</button></p></div></details></div></div>';

        echo '<label class="ks-check-line ks-check-line--top"><input type="checkbox" name="allow_self_pickup" value="1" data-ks-pickup '
            . checked((bool) $this->settings->get('shipping.allow_self_pickup', true), true, false) . ' /> <span><strong>' . esc_html__('Offer self pickup', 'kontor-shop') . '</strong>'
            . '<span class="ks-check-line__hint">' . esc_html__('Customers choose “I’ll pick it up myself” at checkout and pay no shipping.', 'kontor-shop') . '</span></span></label>';
    }

    private function chip(string $code, bool $checked, bool $hidden): string
    {
        return '<label class="ks-chip"' . ($hidden ? ' hidden' : '') . ' data-code="' . esc_attr($code) . '"><input type="checkbox" name="countries[]" value="' . esc_attr($code) . '" '
            . checked($checked, true, false) . ' /> <span>' . esc_html(Countries::name($code)) . '</span>'
            . '<svg class="ks-chip__x" aria-hidden="true" viewBox="0 0 24 24" width="14" height="14"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg></label>';
    }

    private function render_costs(): void
    {
        $home       = ShippingRates::home_country($this->settings);
        $selected   = ShippingRates::abroad_countries($this->settings);
        $abroad     = ShippingRates::optional($this->settings->get('shipping.flat_rate_abroad', null));
        $free       = round((float) $this->settings->get('shipping.free_from_amount', 0), 2);
        $free_away  = (bool) $this->settings->get('shipping.free_abroad', true);
        $columns    = [[
            'key'    => 'all',
            'label'  => __('All customers', 'kontor-shop'),
            'home'   => ['name' => 'flat_rate', 'value' => self::number(ShippingRates::domestic($this->settings)), 'placeholder' => '0', 'id' => 'ks-flat-rate'],
            'abroad' => ['name' => 'flat_rate_abroad', 'value' => $abroad === null ? '' : self::number($abroad), 'placeholder' => __('as domestic', 'kontor-shop')],
        ]];

        /**
         * Filter kontor_shop/settings/shipping/rate_columns: weitere Spalten
         * in der Tabelle "Was kostet der Versand?" (Zeilen Inland/Ausland),
         * z. B. Pro: Preise fuer Mitglieder (key "members" - die Vorschau
         * rechnet damit). Gespeichert wird ueber kontor_shop/settings/shipping/save.
         *
         * @param array<int, array{key?: string, label: string, home: array{name: string, value: string, placeholder?: string}, abroad: array{name: string, value: string, placeholder?: string}, note?: string}> $columns
         * @param Repository $settings
         */
        $extra = apply_filters('kontor_shop/settings/shipping/rate_columns', [], $this->settings);

        foreach (is_array($extra) ? $extra : [] as $column) {
            if (is_array($column) && isset($column['label'], $column['home']['name'], $column['abroad']['name'])) {
                $columns[] = $column;
            }
        }

        $field = static fn (array $input, string $label, string $role): string => '<span class="ks-money"><input type="text" inputmode="decimal" name="' . esc_attr((string) $input['name']) . '"'
            . (isset($input['id']) ? ' id="' . esc_attr((string) $input['id']) . '"' : '')
            . ' value="' . esc_attr((string) $input['value']) . '" placeholder="' . esc_attr((string) ($input['placeholder'] ?? '')) . '"'
            . ' aria-label="' . esc_attr($label) . '" data-ks-rate="' . esc_attr($role) . '" /> €</span>';

        echo '<div class="ks-ship-costs"><div class="ks-ship-costs__main">';

        echo '<div class="ks-rates" style="--ks-rate-cols:' . esc_attr((string) count($columns)) . '"><span></span>';

        foreach ($columns as $column) {
            echo '<span class="ks-rates__head">' . esc_html((string) $column['label']) . '</span>';
        }

        echo '<span class="ks-rates__row"><strong>' . esc_html__('Domestic', 'kontor-shop') . '</strong><span data-ks-home-name>' . esc_html(Countries::name($home)) . '</span></span>';

        foreach ($columns as $column) {
            $key = (string) ($column['key'] ?? 'x');
            echo $field($column['home'], __('Domestic', 'kontor-shop') . ', ' . $column['label'], $key . '-home'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- in $field escaped
        }

        echo '<span class="ks-rates__row" data-ks-abroad-row' . ($selected === [] ? ' hidden' : '') . '><strong>' . esc_html__('Abroad', 'kontor-shop') . '</strong><span data-ks-abroad-names>'
            . esc_html(self::list_names($selected)) . '</span></span>';

        foreach ($columns as $column) {
            $key = (string) ($column['key'] ?? 'x');
            echo '<span data-ks-abroad-row' . ($selected === [] ? ' hidden' : '') . '>' . $field($column['abroad'], __('Abroad', 'kontor-shop') . ', ' . $column['label'], $key . '-abroad') . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- in $field escaped
        }

        echo '</div>';

        $notes = [__('Final price per order. 0 = free of charge. “Abroad” empty = same as domestic.', 'kontor-shop')];

        foreach ($columns as $column) {
            if (!empty($column['note'])) {
                $notes[] = (string) $column['note'];
            }
        }

        echo '<p class="description">' . esc_html(implode(' ', $notes)) . '</p>';
        echo '<p class="ks-ship-warn" role="status" data-ks-member-warn hidden>' . esc_html__('A member price is higher than the regular price. Members never pay more than everyone else – the regular price applies then.', 'kontor-shop') . '</p>';

        // Weitere Zeilen einer Erweiterung (Altform <tr>), z. B. eigener Versandschalter.
        ob_start();
        $this->extra_fields('costs');
        $rows = (string) ob_get_clean();

        if (trim($rows) !== '') {
            echo '<table class="form-table"><tbody>' . $rows . '</tbody></table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ausgabe der Erweiterung (escaped dort)
        }

        echo '<div class="ks-ship-free"><label class="ks-check-line"><input type="checkbox" name="free_on" value="1" data-ks-free-on ' . checked($free > 0, true, false) . ' /> <strong>' . esc_html__('Free shipping from', 'kontor-shop') . '</strong> '
            . '<span class="ks-money"><input type="text" inputmode="decimal" name="free_from_amount" value="' . esc_attr($free > 0 ? self::number($free) : '') . '" placeholder="50" aria-label="' . esc_attr__('Free shipping from goods value', 'kontor-shop') . '" data-ks-free /> €</span> '
            . esc_html__('goods value', 'kontor-shop') . '</label>';
        echo '<input type="hidden" name="free_abroad_shown" value="1" />';
        echo '<label class="ks-check-line ks-check-line--sub" data-ks-free-abroad-line' . ($free > 0 && $selected !== [] ? '' : ' hidden') . '><input type="checkbox" name="free_abroad" value="1" data-ks-free-abroad ' . checked($free_away, true, false) . ' /> '
            . esc_html__('also applies abroad', 'kontor-shop') . '</label></div>';

        echo '</div>';

        echo '<aside class="ks-ship-preview" aria-label="' . esc_attr__('Preview', 'kontor-shop') . '" aria-live="polite" data-ks-preview hidden>'
            . '<strong class="ks-ship-preview__title">' . esc_html__('This is how checkout calculates', 'kontor-shop') . '</strong>'
            . '<span class="ks-ship-preview__sample" data-ks-sample></span><ul class="ks-ship-preview__list" data-ks-examples></ul>'
            . '<strong class="ks-ship-preview__sub">' . esc_html__('The product page says', 'kontor-shop') . '</strong>'
            . '<p class="ks-ship-preview__line"><svg aria-hidden="true" viewBox="0 0 24 24" width="20" height="20"><path d="M3 7h11v9H3zM14 10h4l3 3v3h-7" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><circle cx="7" cy="17.5" r="1.8" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="17" cy="17.5" r="1.8" fill="none" stroke="currentColor" stroke-width="1.8"/></svg><span data-ks-line></span></p>'
            . '</aside></div>';

        echo '<script type="application/json" data-ks-ship-config>' . wp_json_encode($this->preview_config()) . '</script>';

        wp_enqueue_script('kontor-shop-shipping-settings', KONTOR_SHOP_URL . 'assets/admin/shipping-settings.js', ['kontor-shop-admin'], KONTOR_SHOP_VERSION, true);
    }

    /**
     * Texte und Laendernamen fuer die Live-Vorschau (shipping-settings.js).
     *
     * @return array<string, mixed>
     */
    private function preview_config(): array
    {
        return [
            'countries' => Countries::all(),
            'i18n'      => [
                /* translators: %s: country */
                'guest'        => __('Guest to %s', 'kontor-shop'),
                /* translators: %s: country */
                'member'       => __('Member to %s', 'kontor-shop'),
                /* translators: 1: amount such as "100 €", 2: country */
                'freeHome'     => __('Order from %1$s to %2$s', 'kontor-shop'),
                /* translators: %s: amount such as "100 €" */
                'freeAbroad'   => __('Order from %s abroad', 'kontor-shop'),
                'pickup'       => __('Self pickup', 'kontor-shop'),
                /* translators: %s: amount such as "30 €" */
                'sample'       => __('Example: %s goods value', 'kontor-shop'),
                /* translators: %s: country */
                'andOthers'    => __('%s and others', 'kontor-shop'),
                /* translators: 1: list of countries, 2: last country */
                'and'          => __('%1$s and %2$s', 'kontor-shop'),
                'free'         => __('free of charge', 'kontor-shop'),
                /* translators: 1: countries, 2: shipping cost */
                'shipTo'       => __('Shipping to %1$s: %2$s', 'kontor-shop'),
                /* translators: 1: countries, 2: shipping cost */
                'to'           => __('to %1$s: %2$s', 'kontor-shop'),
                /* translators: %s: countries */
                'freeTo'       => __('Free shipping to %s', 'kontor-shop'),
                /* translators: %s: order value such as "100 €" */
                'freeFrom'     => __('free from %s', 'kontor-shop'),
                /* translators: 1: order value such as "100 €", 2: country */
                'freeFromTo'   => __('free from %1$s to %2$s', 'kontor-shop'),
                'membersFree'  => __('members ship for free', 'kontor-shop'),
                /* translators: %s: shipping cost */
                'members'      => __('members: %s', 'kontor-shop'),
                /* translators: 1: domestic country, 2: shipping cost domestic, 3: shipping cost abroad */
                'membersSplit' => __('members: %1$s %2$s, abroad %3$s', 'kontor-shop'),
                'orPickup'     => __('Or pick it up yourself for free', 'kontor-shop'),
            ],
        ];
    }

    private function render_split(): void
    {
        $several    = count((new BankAccounts($this->settings))->all()) > 1;
        $split_mode = (string) $this->settings->get('shipping.split_mode', SplitShipping::SEPARATE);
        $rate       = (float) $this->settings->get('shipping.split_flat_rate', 0);

        echo '<details class="ks-ship-card ks-ship-split"' . ($several ? ' open' : '') . '><summary><strong>' . esc_html__('Several warehouses with their own account', 'kontor-shop') . '</strong>'
            . (!$several ? ' <span class="ks-ship-card__note">– ' . esc_html__('not needed for you at the moment', 'kontor-shop') . '</span>' : '') . '</summary>';
        echo '<p class="description">' . esc_html__('Only when products from warehouses with different bank accounts end up in one cart is the order split. How often does the customer pay shipping then?', 'kontor-shop') . '</p>';

        foreach ([
            SplitShipping::SEPARATE => __('For each partial order (simple, may cost twice)', 'kontor-shop'),
            SplitShipping::UNIFIED  => __('Only once, based on the whole cart', 'kontor-shop'),
            SplitShipping::CUSTOM   => __('Once, a fixed amount:', 'kontor-shop'),
        ] as $mode => $label) {
            echo '<label class="ks-check-line"><input type="radio" name="split_mode" value="' . esc_attr($mode) . '" ' . checked($split_mode, $mode, false) . ' /> <span>' . esc_html($label) . '</span>';

            if ($mode === SplitShipping::CUSTOM) {
                echo ' <span class="ks-money"><input type="text" inputmode="decimal" name="split_flat_rate" value="' . esc_attr($rate > 0 ? self::number($rate) : '') . '" placeholder="7,50" aria-label="' . esc_attr__('Flat rate for “Custom price”:', 'kontor-shop') . '" /> €</span>';
            }

            echo '</label>';
        }

        echo '<p class="description">' . esc_html__('With “only once” and the fixed amount, shipping is charged on the first partial order. Free-shipping rules then apply to the whole cart.', 'kontor-shop') . '</p></details>';
    }

    /**
     * Formulare von Erweiterungen, die als Karte in dieses Formular gehoeren.
     *
     * @return array<int, Section>
     */
    private function embedded_forms(): array
    {
        return array_values(array_filter($this->forms(), static fn (Section $form): bool => $form->embedded()));
    }

    private function sanitize_country(string $code): string
    {
        $code = strtoupper(sanitize_key($code));

        return Countries::is_known($code) ? $code : '';
    }

    /**
     * Lieferlaender: das Inland zuerst (Standard in der Kasse), dann das Ausland.
     *
     * @param array<int|string, mixed> $abroad
     * @return array<int, string>
     */
    public static function collect_countries(string $home, array $abroad): array
    {
        $codes = [$home];

        foreach ($abroad as $code) {
            $code = strtoupper(sanitize_key((string) $code));

            if (Countries::is_known($code) && !in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /** "Deutschland und Schweiz" */
    private static function list_names(array $codes): string
    {
        $names = array_map(static fn (string $code): string => Countries::name($code), $codes);

        if (count($names) <= 1) {
            return $names[0] ?? '';
        }

        $last = array_pop($names);

        /* translators: 1: list of countries, 2: last country */
        return sprintf(__('%1$s and %2$s', 'kontor-shop'), implode(', ', $names), $last);
    }

    /** 5.9 -> "5,90", 12 -> "12" (Eingabefeld). */
    private static function number(float $value): string
    {
        return abs($value - round($value)) < 0.005 ? (string) (int) round($value) : number_format($value, 2, ',', '');
    }

    private static function icon_info(): string
    {
        return '<svg aria-hidden="true" viewBox="0 0 24 24" width="20" height="20"><circle cx="12" cy="12" r="8.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M12 11v5M12 8v.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    }

    /**
     * Weitere Ausgaben im Formular "Versand".
     *
     * @param string $place 'costs' (Zeilen <tr> unter der Preistabelle)
     */
    private function extra_fields(string $place): void
    {
        /**
         * Aktion kontor_shop/settings/shipping/fields: eigene Zeilen
         * (<tr><th>…</th><td>…</td></tr>) unter der Preistabelle im Formular
         * "Versand" bei $place 'costs'. Gespeichert wird ueber den Filter
         * kontor_shop/settings/shipping/save. (Bis 2.9.13 auch 'rules' fuer
         * den Regelkasten - den ersetzt die Live-Vorschau.)
         *
         * @param string     $place    'costs'
         * @param Repository $settings Einstellungen
         */
        do_action('kontor_shop/settings/shipping/fields', $place, $this->settings);
    }
}
