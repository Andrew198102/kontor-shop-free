<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin\Settings;

use Kontor\Shop\Core\Edition;
use Kontor\Shop\Modules\Agents\AgentsModule;
use Kontor\Shop\Modules\Agents\OfferPage;

/**
 * KI-Assistenten (MCP): oeffentlichen Shop-Zugang ein-/ausschalten, Adresse
 * zum Weitergeben. Pro haengt hier den Zugang fuer das Shop-Team an
 * (Aktionen kontor_shop/agents/settings_rows, Filter
 * kontor_shop/agents/settings_values).
 */
final class AgentsSection extends Section
{
    public function key(): string
    {
        return 'agents';
    }

    public function label(): string
    {
        return __('AI assistants', 'kontor-shop');
    }

    public function group(): string
    {
        return self::GROUP_TECH;
    }

    public function description(): string
    {
        return __('Let AI assistants such as ChatGPT, Claude or Gemini work with your shop (Model Context Protocol, MCP).', 'kontor-shop');
    }

    public function save(string $tab): ?string
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
        $values = (array) apply_filters('kontor_shop/agents/settings_values', [
            'shop_enabled' => !empty($_POST['shop_enabled']),
        ]);
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $this->settings->save(['agents' => $values]);

        return __('Settings saved.', 'kontor-shop');
    }

    public function render(): void
    {
        $enabled = AgentsModule::shop_enabled($this->settings);
        $url     = AgentsModule::shop_url();

        $this->form_open('agents');

        $this->intro(__('With this, a customer can ask their AI assistant things like “Find me a navy hoodie in size L from the club shop” – the assistant searches your shop directly and gets correct prices and availability, instead of guessing from the website.', 'kontor-shop'));

        $this->group_title(__('Shop access for customers', 'kontor-shop'));

        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">' . esc_html__('Shop access', 'kontor-shop') . '</th><td>'
            . '<label><input type="checkbox" name="shop_enabled" value="1" ' . checked($enabled, true, false) . ' /> '
            . esc_html__('AI assistants may search the shop (public data only: products, regular prices, in stock yes/no)', 'kontor-shop') . '</label>'
            . '<p class="description">' . esc_html__('No login needed – the assistant sees exactly what any visitor sees on the website. No customer, order or stock figures.', 'kontor-shop') . '</p></td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Address (MCP server URL)', 'kontor-shop') . '</th><td>'
            . '<code class="ks-agents-url">' . esc_html($url) . '</code> '
            . '<button type="button" class="button button-small" data-ks-copy="' . esc_attr($url) . '">' . esc_html__('Copy', 'kontor-shop') . '</button>'
            . '<p class="description">' . esc_html(
                $enabled
                    ? __('Pass this address on, e.g. on your shop page: “Shop with your AI assistant: add this address as a connector.”', 'kontor-shop')
                    : __('Switched off – the address answers only after you enable shop access and save.', 'kontor-shop')
            ) . '</p></td></tr>';

        echo '<tr><th scope="row">' . esc_html__('How customers connect', 'kontor-shop') . '</th><td><ol class="ks-agents-steps">'
            . '<li>' . esc_html__('Claude (claude.ai or app): Settings → Connectors → “Add custom connector”, paste the address, no further fields.', 'kontor-shop') . '</li>'
            . '<li>' . esc_html__('ChatGPT: Settings → Apps & Connectors → Advanced → turn on developer mode, then “Create”, paste the address, authentication “No authentication”.', 'kontor-shop') . '</li>'
            . '<li>' . esc_html__('Gemini: where your plan offers it, under connected apps → add a custom app with the address.', 'kontor-shop') . '</li>'
            . '</ol><p class="description">' . esc_html__('Menu names change from time to time; the providers’ help pages under “custom connector” or “MCP” have the current steps.', 'kontor-shop') . '</p></td></tr>';

        echo '</tbody></table>';

        do_action('kontor_shop/agents/settings_rows', $this->settings);

        if (!Edition::is_pro()) {
            echo '<div class="ks-help-box"><p>' . esc_html__('Kontor Shop Pro adds more: the assistant fills the cart and hands the customer a ready checkout link, and your team manages products, images, stock and orders by chat – with each person’s own permissions.', 'kontor-shop') . ' <a href="' . esc_url(OfferPage::url()) . '">' . esc_html__('See what it can do', 'kontor-shop') . '</a></p></div>';
        }

        $this->savebar();
        echo '</form>';
    }
}
