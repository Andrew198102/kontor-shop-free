<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Agents;

use Kontor\Shop\Core\Registerable;
use Kontor\Shop\Modules\Agents\Mcp\HttpEndpoint;
use Kontor\Shop\Modules\Agents\Mcp\McpServer;
use Kontor\Shop\Settings\Repository;

/**
 * KI-Assistenten (MCP): Der oeffentliche Shop-Zugang unter
 * /wp-json/kontor-shop/v1/mcp - ChatGPT, Claude, Gemini & Co. finden darueber
 * Artikel, Preise und Verfuegbarkeit, ohne die Website "lesen" zu muessen.
 * Standardmaessig aus; eingeschaltet wird er unter Einstellungen > KI-Assistenten.
 *
 * Pro haengt Warenkorb/Checkout-Uebergabe und den angemeldeten Zugang fuer
 * das Shop-Team an (kontor_shop/agents/tools, ToolRegistry::ADMIN). In Free
 * erklaert der Menuepunkt "KI-Assistent" (OfferPage), was Pro damit kann.
 */
final class AgentsModule implements Registerable
{
    public const REST_NAMESPACE = 'kontor-shop/v1';
    public const SHOP_ROUTE     = '/mcp';

    /** Anfragen je Absender und 10 Minuten am oeffentlichen Zugang */
    private const SHOP_LIMIT = 600;

    public function __construct(private readonly Repository $settings)
    {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
        HttpEndpoint::register_empty_body();

        if (is_admin()) {
            (new OfferPage($this->settings))->register();
        }

        AbilitiesBridge::register(fn (): array => array_merge(
            self::shop_enabled($this->settings) ? ToolRegistry::for(ToolRegistry::SHOP, $this->settings) : [],
            ToolRegistry::for(ToolRegistry::ADMIN, $this->settings)
        ));
    }

    public static function shop_enabled(Repository $settings): bool
    {
        return (bool) $settings->get('agents.shop_enabled', false);
    }

    public static function shop_url(): string
    {
        return rest_url(self::REST_NAMESPACE . self::SHOP_ROUTE);
    }

    public function register_routes(): void
    {
        register_rest_route(self::REST_NAMESPACE, self::SHOP_ROUTE, [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'serve_shop'],
                'permission_callback' => '__return_true', // oeffentlich: nur Daten, die auch auf der Website stehen
            ],
            [
                'methods'             => 'GET, DELETE',
                'callback'            => [HttpEndpoint::class, 'method_not_allowed'],
                'permission_callback' => '__return_true',
            ],
        ]);
    }

    public function serve_shop(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!self::shop_enabled($this->settings)) {
            return HttpEndpoint::refuse(404, __('The shop’s AI assistant access is switched off.', 'kontor-shop'));
        }

        if (!RateLimit::hit('shop', RateLimit::client_ip(), self::SHOP_LIMIT, 10 * MINUTE_IN_SECONDS)) {
            return HttpEndpoint::refuse(429, __('Too many requests – please try again in a few minutes.', 'kontor-shop'));
        }

        return HttpEndpoint::respond($request, self::shop_server($this->settings));
    }

    public static function shop_server(Repository $settings): McpServer
    {
        return new McpServer(
            'kontor-shop',
            ShopTools::shop_name($settings),
            KONTOR_SHOP_VERSION,
            ShopTools::instructions($settings),
            ToolRegistry::for(ToolRegistry::SHOP, $settings),
            self::about(__('Products, prices and availability of this shop.', 'kontor-shop'))
        );
    }

    /**
     * Beschreibung, Symbol und Website fuer die Anzeige im KI-Assistenten
     * (serverInfo). Symbol: das Website-Symbol der WordPress-Seite (Design >
     * Customizer > Website-Identitaet), sonst das Kontor-Shop-Symbol; der
     * Filter kontor_shop/agents/icon_url setzt ein eigenes.
     *
     * @return array<string, mixed>
     */
    public static function about(string $description): array
    {
        $site_icon = function_exists('get_site_icon_url') ? (string) get_site_icon_url(512) : '';
        $icon      = (string) apply_filters('kontor_shop/agents/icon_url', $site_icon !== '' ? $site_icon : KONTOR_SHOP_URL . 'assets/agent-icon.png');
        $type      = (string) (wp_check_filetype((string) wp_parse_url($icon, PHP_URL_PATH))['type'] ?? '');
        $about     = ['description' => $description, 'websiteUrl' => home_url('/')];

        if ($icon !== '') {
            $about['icons'] = [array_filter(['src' => $icon, 'mimeType' => $type, 'sizes' => [$site_icon !== '' && $icon === $site_icon ? '512x512' : '256x256']])];
        }

        return $about;
    }
}
