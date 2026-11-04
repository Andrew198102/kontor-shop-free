<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Seo;

use Kontor\Shop\Domain\Seo\CurrentRoute;

/**
 * Kleiner eigener Kopf der Shop-Seiten: Titel, Beschreibung und Canonical
 * der virtuellen Seiten, noindex der Kassenseiten und deren Ausschluss aus
 * der WordPress-Sitemap; weitere Kopfdaten ueber kontor_shop/seo/head
 * (z. B. Pro: Product-JSON-LD). Wird nicht registriert, wenn eine
 * Erweiterung die Kopfdaten liefert (kontor_shop/seo/fallback_head, z. B.
 * Pro mit Meridian SEO) - sonst gaebe es doppelte Tags.
 *
 * Sitemap-/llms.txt-Endpunkte gibt es hier bewusst nicht.
 */
final class FallbackHead
{
    public function __construct(private readonly Links $links, private readonly PrivatePages $private)
    {
    }

    public function register(): void
    {
        // Spaet (99): andere SEO-Plugins kennen die virtuellen Seiten nicht und setzten sonst den Seitennamen.
        add_filter('pre_get_document_title', [$this, 'title'], 99);
        add_action('wp_head', [$this, 'head'], 2);
        add_filter('wp_robots', [$this, 'robots']);
        add_filter('get_canonical_url', [$this, 'canonical'], 10, 2);
        add_filter('wp_sitemaps_posts_query_args', [$this, 'sitemap_args']);
    }

    public function title(mixed $title): mixed
    {
        $route = CurrentRoute::get();

        return $route !== null ? $route['title'] : $title;
    }

    public function head(): void
    {
        $route = CurrentRoute::get();

        if ($route === null) {
            return;
        }

        if ($route['description'] !== '') {
            echo '<meta name="description" content="' . esc_attr($route['description']) . '" />' . "\n";
        }

        /**
         * Aktion kontor_shop/seo/head: weitere Kopfdaten einer virtuellen
         * Shop-Seite, z. B. Pro: Product-JSON-LD der Artikelseite. Laeuft nur
         * mit dem eigenen Fallback (kontor_shop/seo/fallback_head), nie
         * zusaetzlich zu einem SEO-Plugin. Ausgabe selbst escapen.
         *
         * @param array{type: string, canonical: string, title: string, description: string, breadcrumbs: array<int, array{name: string, url: string}>, indexable: bool, product: ?\Kontor\Shop\Domain\Seo\PublicProduct} $route Domain\Seo\CurrentRoute::get()
         */
        do_action('kontor_shop/seo/head', $route);
    }

    /**
     * @param array<string, mixed> $robots
     * @return array<string, mixed>
     */
    public function robots(array $robots): array
    {
        if ($this->private->is_current_private()) {
            unset($robots['max-image-preview']);
            $robots['noindex']  = true;
            $robots['nofollow'] = true;
        }

        return $robots;
    }

    public function canonical(mixed $url, mixed $post): mixed
    {
        if (!is_string($url) || $url === '' || !$post instanceof \WP_Post || $post->ID === RouteController::FAKE_POST_ID) {
            return $url;
        }

        return has_shortcode($post->post_content, 'kontor_shop_catalog') ? $this->links->catalog_canonical($url) : $url;
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function sitemap_args(array $args): array
    {
        $args['post__not_in'] = array_merge((array) ($args['post__not_in'] ?? []), $this->private->page_ids());

        return $args;
    }
}
