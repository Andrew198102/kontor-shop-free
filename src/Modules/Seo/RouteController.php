<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Seo;

use Kontor\Shop\Domain\Seo\CurrentRoute;
use Kontor\Shop\Domain\Seo\ProductRouteResolver;
use Kontor\Shop\Domain\Seo\PublicProduct;
use Kontor\Shop\Domain\Seo\RouteDecision;
use Kontor\Shop\Domain\Seo\SeoText;
use Kontor\Shop\Domain\Seo\SourceUnavailable;
use Kontor\Shop\Domain\Seo\UrlScheme;
use Kontor\Shop\Infrastructure\Logging\Logger;
use Kontor\Shop\Infrastructure\Seo\PublicCatalog;
use Kontor\Shop\Settings\Repository;

/**
 * Virtuelle Adressen ohne WordPress-Post:
 *
 *   /shop/artikel/{slug}-{id}/           Artikelseite
 *
 * Weitere virtuelle Seiten bringen Erweiterungen mit (Rewrite-Regel ueber
 * kontor_shop/seo/rewrite_rules, Inhalt ueber kontor_shop/seo/virtual_page),
 * z. B. Pro: /shop/kategorie/{slug}/[seite/{n}/].
 *
 * Die Rewrite-Regel setzt nur Query-Variablen. Beim Aufbau der Hauptabfrage
 * (Filter the_posts) wird der Statuscode entschieden und bei 200 ein
 * virtueller Beitrag eingesetzt, damit das Theme die Seite wie eine normale
 * Seite ausgibt (Kopf, Fuss, Menue, Titel als H1). Alles ist serverseitig
 * gerendert; kein JavaScript noetig.
 *
 * Statuscodes: siehe Domain\Seo\ProductRouteResolver (200/301/410/404/503).
 */
final class RouteController
{
    /** Kuenstliche Beitrags-ID des virtuellen Beitrags (kein echter Beitrag, kollisionsarm). */
    public const FAKE_POST_ID = 2147483000;

    private ?RouteDecision $decision = null;

    private string $canonical = '';

    private string $heading = '';

    public function __construct(
        private readonly Repository $settings,
        private readonly Links $links,
        private readonly PublicCatalog $catalog,
        private readonly PageRenderer $renderer,
    ) {
    }

    public function register(): void
    {
        add_filter('query_vars', [$this, 'query_vars']);
        add_filter('rewrite_rules_array', [$this, 'rewrite_rules']);
        add_filter('the_posts', [$this, 'inject_post'], 10, 2);
        add_action('template_redirect', [$this, 'apply_status'], 1);
        add_filter('redirect_canonical', [$this, 'stop_canonical_redirect']);
        add_filter('page_link', [$this, 'fake_permalink'], 10, 2);
    }

    /**
     * @param array<int, string> $vars
     * @return array<int, string>
     */
    public function query_vars(array $vars): array
    {
        return array_merge($vars, ['kontor_shop_route', 'kontor_shop_ref', 'kontor_shop_page']);
    }

    /**
     * @param array<string, string> $rules
     * @return array<string, string>
     */
    public function rewrite_rules(array $rules): array
    {
        if (SeoModule::$rules_disabled) {
            return $rules;
        }

        $urls = $this->links->urls();

        /**
         * Filter kontor_shop/seo/rewrite_rules: Rewrite-Regeln der virtuellen
         * Shop-Seiten (Regex => Query-String mit kontor_shop_route,
         * kontor_shop_ref, kontor_shop_page), z. B. Pro: die Kategorieseiten.
         * Den Inhalt weiterer Routen liefert kontor_shop/seo/virtual_page.
         * Aendert sich die Liste (Lizenzwechsel), leert Lifecycle die Regeln
         * einmal. Standard: nur die Artikelseiten.
         *
         * @param array<string, string> $rules
         * @param UrlScheme             $urls
         */
        $own = (array) apply_filters('kontor_shop/seo/rewrite_rules', $urls->product_rewrite_rules(), $urls);

        return $own + $rules;
    }

    /**
     * @param array<int, \WP_Post> $posts
     * @return array<int, \WP_Post>
     */
    public function inject_post(array $posts, \WP_Query $query): array
    {
        $route = (string) $query->get('kontor_shop_route');

        if ($route === '' || !$query->is_main_query() || $this->decision !== null) {
            return $posts;
        }

        $ref  = (string) $query->get('kontor_shop_ref');
        $page = max(1, (int) $query->get('kontor_shop_page'));
        $html = null;

        try {
            if ($route === 'product') {
                $this->decision = (new ProductRouteResolver($this->catalog, $this->links->urls()))->resolve($ref);
                $html           = $this->decision->status === RouteDecision::OK ? $this->prepare_product($this->decision->product) : null;
            } else {
                $html           = $this->prepare_other($route, $ref, $page);
                $this->decision = new RouteDecision($html === null ? RouteDecision::NOT_FOUND : RouteDecision::OK);
            }
        } catch (SourceUnavailable) {
            $this->decision = new RouteDecision(RouteDecision::UNAVAILABLE);
        }

        if ($this->decision->status === RouteDecision::UNAVAILABLE) {
            // Nur die Art des Fehlers, nie Kunden-/Bestelldaten (es gibt hier keine).
            Logger::warning('seo', 'Katalog fuer virtuelle Shop-Seite nicht verfuegbar (503)', ['route' => $route]);
        }

        if ($this->decision->status !== RouteDecision::OK || $html === null) {
            CurrentRoute::clear();

            return [];
        }

        $state = CurrentRoute::get();

        // Falscher Pfad (fehlender Slash, andere Gross-/Kleinschreibung) -> 301 auf die Adresse mit Slash.
        if ($state !== null && $this->path_differs($state['canonical'])) {
            $this->decision = new RouteDecision(RouteDecision::MOVED, $state['canonical']);
            CurrentRoute::clear();

            return [];
        }

        remove_filter('the_content', 'wpautop');

        $post = new \WP_Post((object) [
            'ID'             => self::FAKE_POST_ID,
            'post_author'    => 0,
            'post_date'      => '',
            'post_date_gmt'  => '',
            'post_content'   => $html,
            'post_title'     => $this->heading,
            'post_excerpt'   => '',
            'post_status'    => 'publish',
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
            'post_name'      => $ref,
            'post_type'      => 'page',
            'filter'         => 'raw',
        ]);

        $query->is_404             = false;
        $query->is_page            = true;
        $query->is_singular        = true;
        $query->is_single          = false;
        $query->is_home            = false;
        $query->is_archive         = false;
        $query->queried_object     = $post;
        $query->queried_object_id  = self::FAKE_POST_ID;
        $query->found_posts        = 1;
        $query->post_count         = 1;
        $query->max_num_pages      = 1;

        return [$post];
    }

    public function apply_status(): void
    {
        if ($this->decision === null) {
            return;
        }

        switch ($this->decision->status) {
            case RouteDecision::MOVED:
                $location = (string) $this->decision->location;

                wp_safe_redirect(str_starts_with($location, '/') ? home_url($location) : $location, 301, 'Kontor Shop');
                exit;

            case RouteDecision::GONE:
                status_header(410);
                nocache_headers();
                header('X-Robots-Tag: noindex');

                break;

            case RouteDecision::UNAVAILABLE:
                status_header(503);
                header('Retry-After: 120');
                nocache_headers();
                wp_die(
                    esc_html__('The shop is currently unreachable. Please try again in a few minutes.', 'kontor-shop'),
                    esc_html__('Temporarily unavailable', 'kontor-shop'),
                    ['response' => 503]
                );
        }
    }

    /**
     * WordPress wuerde /shop/artikel/x-1 auf eine Variante mit/ohne Slash
     * umleiten und dabei den virtuellen Beitrag nicht kennen - die
     * Umleitung uebernehmen wir selbst (path_differs).
     */
    public function stop_canonical_redirect(mixed $redirect): mixed
    {
        return (string) get_query_var('kontor_shop_route') !== '' ? false : $redirect;
    }

    public function fake_permalink(string $link, int $post_id): string
    {
        return $post_id === self::FAKE_POST_ID && $this->canonical !== '' ? $this->canonical : $link;
    }

    /** Baut Inhalt und SEO-Zustand einer Artikelseite. */
    private function prepare_product(?PublicProduct $product): ?string
    {
        if ($product === null) {
            return null;
        }

        $this->canonical = $this->links->product($product);
        $this->heading   = $product->name;

        $crumbs = $this->links->breadcrumbs($product->category_trail);
        $crumbs[] = ['name' => $product->name, 'url' => $this->canonical];

        CurrentRoute::set(
            'product',
            $this->canonical,
            (string) apply_filters('kontor_shop/seo/title', $product->name . ' – ' . $this->links->shop_name(), $product),
            SeoText::meta_description($product),
            $crumbs,
            true,
            $product
        );

        return $this->renderer->product($product, $crumbs);
    }

    /**
     * Weitere virtuelle Seiten von Erweiterungen (z. B. Pro: Kategorieseiten).
     */
    private function prepare_other(string $route, string $ref, int $page): ?string
    {
        /**
         * Filter kontor_shop/seo/virtual_page: Inhalt einer weiteren
         * virtuellen Shop-Seite (Route aus kontor_shop/seo/rewrite_rules),
         * z. B. Pro: Kategorieseiten. Die Erweiterung setzt den SEO-Zustand
         * selbst (Domain\Seo\CurrentRoute::set). Darf
         * Domain\Seo\SourceUnavailable werfen (-> 503).
         * Standard: null (404).
         *
         * @param array{html: string, heading: string, canonical: string}|null $page    html ohne H1, heading = Seitentitel (H1 des Themes)
         * @param string                                                         $route   kontor_shop_route
         * @param string                                                         $ref     kontor_shop_ref (z. B. Slug)
         * @param int                                                            $page_no kontor_shop_page (ab 1)
         */
        $result = apply_filters('kontor_shop/seo/virtual_page', null, $route, $ref, $page);

        if (!is_array($result) || !isset($result['html'], $result['heading'], $result['canonical'])) {
            return null;
        }

        $this->canonical = (string) $result['canonical'];
        $this->heading   = (string) $result['heading'];

        return (string) $result['html'];
    }

    private function path_differs(string $canonical): bool
    {
        $wanted = (string) wp_parse_url($canonical, PHP_URL_PATH);
        $actual = rawurldecode((string) wp_parse_url(esc_url_raw(wp_unslash((string) ($_SERVER['REQUEST_URI'] ?? ''))), PHP_URL_PATH));

        return $wanted !== '' && $actual !== $wanted;
    }
}
