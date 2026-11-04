<?php

declare(strict_types=1);

namespace Kontor\Shop\Core;

final class I18n implements Registerable
{
    public function register(): void
    {
        add_action('init', [$this, 'load_textdomain']);
    }

    public function load_textdomain(): void
    {
        load_plugin_textdomain(
            'kontor-shop',
            false,
            dirname(KONTOR_SHOP_BASENAME) . '/languages'
        );
    }

    /**
     * Nie aufgerufen - nur damit bin/i18n.php die Texte aus dem Plugin-Kopf findet.
     * WordPress uebersetzt Name und Beschreibung in der Plugin-Liste selbst; die
     * Pro-Version heisst wie Free plus " Pro" (Freemius haengt das an).
     *
     * @return array<int, string>
     */
    private static function plugin_header(): array
    {
        return [
            __('Kontor Shop – Club Shop with Bank Transfer & Pickup', 'kontor-shop'),
            __('Kontor Shop – Club Shop with Bank Transfer & Pickup Pro', 'kontor-shop'),
            __('Club shop for WordPress: products with variants, stock, bank-transfer checkout, pickup and a cashier workflow.', 'kontor-shop'),
        ];
    }
}
