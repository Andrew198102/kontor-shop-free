<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Storefront;

use Kontor\Shop\Core\Registerable;
use Kontor\Shop\Settings\Repository;
use Kontor\Shop\Support\Color;

/**
 * Gibt die Hauptfarbe des Shops (design.primary_color, Admin\SettingsPage
 * Tab "Design") als CSS-Custom-Property aus - eigener, plugin-neutraler
 * Variablenname (--kontor-shop-primary), keine Abhaengigkeit von
 * Theme-spezifischen Variablen. Ist die Einstellung leer, wird nichts
 * ausgegeben; das Frontend-CSS (assets/frontend/shop.css) hat dafuer einen
 * eingebauten, neutralen Fallback (var(--kontor-shop-primary, #2271b1)).
 */
final class DesignModule implements Registerable
{
    public function __construct(private readonly Repository $settings)
    {
    }

    private const CARD_EFFECTS = ['spotlight', 'lift', 'none'];

    public function register(): void
    {
        add_action('wp_head', [$this, 'print_css_vars'], 5);
        add_filter('body_class', [$this, 'add_body_class']);
    }

    public static function sanitize_card_effect(string $value): string
    {
        return in_array($value, self::CARD_EFFECTS, true) ? $value : 'spotlight';
    }

    /**
     * Karten-Effekt (design.card_effect) als Klasse am <body>, damit
     * shop.css und catalog.js ihn ohne Inline-Styles/Markup-Aenderung lesen.
     * "lift" ist der CSS-Grundzustand und braucht keine eigene Klasse.
     *
     * @param array<int, string> $classes
     * @return array<int, string>
     */
    public function add_body_class(array $classes): array
    {
        $effect = self::sanitize_card_effect((string) $this->settings->get('design.card_effect', 'spotlight'));

        if ($effect !== 'lift') {
            $classes[] = 'kontor-shop-fx-' . $effect;
        }

        return $classes;
    }

    public function print_css_vars(): void
    {
        $primary   = Color::sanitize_hex((string) $this->settings->get('design.primary_color', ''));
        $cart_icon = Color::sanitize_hex((string) $this->settings->get('design.cart_icon_color', ''));

        $vars = '';

        if ($primary !== '') {
            $vars .= '--kontor-shop-primary:' . esc_attr($primary) . ';';
        }

        if ($cart_icon !== '') {
            $vars .= '--kontor-shop-cart-icon-color:' . esc_attr($cart_icon) . ';';
        }

        if ($vars === '') {
            return;
        }

        echo '<style id="kontor-shop-vars">:root{' . $vars . '}</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $vars nur aus Color::sanitize_hex + esc_attr
    }
}
