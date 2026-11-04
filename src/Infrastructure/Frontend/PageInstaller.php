<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Frontend;

use Kontor\Shop\Settings\Repository;

/**
 * Legt die drei Shop-Seiten (Shop/Warenkorb/Kasse) mit den passenden
 * Shortcodes an, falls sie noch nicht existieren - Teil des
 * Selbstheilungs-Installers (Core\Lifecycle::install_or_upgrade()). Die
 * Seiten-IDs landen unter general.shop_page_id/cart_page_id/checkout_page_id
 * (z. B. fuer den "Zur Kasse"-Link im Warenkorb, Modules\Cart\CartModule).
 * Wird eine Seite spaeter geloescht/umbenannt, greift das Plugin nicht
 * erneut ein (respektiert eine bewusste Entscheidung des Shop-Admins) - nur
 * eine tatsaechlich fehlende ID (Post existiert nicht mehr) wird ersetzt.
 */
final class PageInstaller
{
    /**
     * Seitentitel in der Sprache der Website.
     *
     * @return array<string, array{title: string, content: string}>
     */
    private static function pages(): array
    {
        return [
            'shop_page_id' => [
                'title'   => __('Shop', 'kontor-shop'),
                'content' => '[kontor_shop_catalog]',
            ],
            'cart_page_id' => [
                'title'   => __('Cart', 'kontor-shop'),
                'content' => '[kontor_shop_cart]',
            ],
            'checkout_page_id' => [
                'title'   => __('Checkout', 'kontor-shop'),
                'content' => '[kontor_shop_checkout]',
            ],
        ];
    }

    public static function install(): void
    {
        $settings = get_option(Repository::OPTION_KEY, []);
        $settings = is_array($settings) ? $settings : [];
        $general  = isset($settings['general']) && is_array($settings['general']) ? $settings['general'] : [];

        $changed = false;

        foreach (self::pages() as $key => $definition) {
            $existing_id = (int) ($general[$key] ?? 0);

            if ($existing_id > 0 && get_post_status($existing_id) !== false) {
                continue;
            }

            $page_id = wp_insert_post([
                'post_title'   => $definition['title'],
                'post_content' => $definition['content'],
                'post_status'  => 'publish',
                'post_type'    => 'page',
            ], true);

            if (is_wp_error($page_id)) {
                continue;
            }

            $general[$key] = $page_id;
            $changed       = true;
        }

        if ($changed) {
            $settings['general'] = $general;
            update_option(Repository::OPTION_KEY, $settings, true);
        }
    }
}
