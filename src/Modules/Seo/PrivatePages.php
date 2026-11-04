<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Seo;

use Kontor\Shop\Domain\Seo\NoIndexPolicy;
use Kontor\Shop\Settings\Repository;

/**
 * Warenkorb, Kasse (inkl. Bestellbestaetigung/-status), Konto: nie in
 * Suchmaschinen. Setzt den X-Robots-Tag-Header (immer, unabhaengig von
 * einem SEO-Plugin); das Meta-Robots-Tag kommt von Meridian (Adapter) oder
 * vom Fallback (FallbackHead). Es gibt bewusst keinen robots.txt-Eintrag:
 * ein Disallow wuerde verhindern, dass der Bot das noindex je sieht.
 */
final class PrivatePages
{
    public function __construct(private readonly Repository $settings)
    {
    }

    public function register(): void
    {
        add_action('template_redirect', [$this, 'send_header'], 0);
    }

    /**
     * @return array<int, int>
     */
    public function page_ids(): array
    {
        $ids = [
            (int) $this->settings->get('general.cart_page_id', 0),
            (int) $this->settings->get('general.checkout_page_id', 0),
        ];

        /** @var array<int, int> $ids */
        $ids = apply_filters('kontor_shop/seo/private_page_ids', array_values(array_filter($ids)));

        return array_values(array_filter(array_map('intval', $ids)));
    }

    /**
     * @param bool $with_request true = auch die Query-Parameter der aktuellen Anfrage
     *                           (Bestelltoken) beruecksichtigen; false z. B. fuer Sitemap-Aufbau
     */
    public function is_private_post(?\WP_Post $post, bool $with_request = true): bool
    {
        // Der Inhalt der virtuellen Artikelseite ist Shop-Text, nie ein Shortcode.
        $virtual = $post !== null && (int) $post->ID === RouteController::FAKE_POST_ID;

        return NoIndexPolicy::is_private_page(
            $post !== null ? (int) $post->ID : null,
            $post !== null && !$virtual ? (string) $post->post_content : '',
            $this->page_ids(),
            $with_request && is_array($_GET) ? map_deep(wp_unslash($_GET), 'sanitize_text_field') : [] // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige-Parameter (prueft nur, ob Parameter gesetzt sind)
        );
    }

    /** Ist die aktuelle Anfrage eine private Seite? */
    public function is_current_private(): bool
    {
        $post = is_singular() ? get_post() : null;

        return $this->is_private_post($post instanceof \WP_Post ? $post : null);
    }

    public function send_header(): void
    {
        if (!$this->is_current_private() || headers_sent()) {
            return;
        }

        nocache_headers();
        header('X-Robots-Tag: ' . NoIndexPolicy::HEADER);
    }
}
