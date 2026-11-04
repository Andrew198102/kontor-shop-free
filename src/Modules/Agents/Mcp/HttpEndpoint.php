<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Agents\Mcp;

/**
 * MCP ueber HTTP ("Streamable HTTP" ohne Server-Push): Der Client schickt
 * jede JSON-RPC-Nachricht per POST und bekommt die Antwort als JSON.
 * GET (Ereignis-Strom) bietet der Shop nicht an - laut Protokoll ist die
 * Antwort darauf 405, dann arbeitet der Client nur mit POST.
 */
final class HttpEndpoint
{
    private const EMPTY_HEADER = 'X-Kontor-Mcp-Empty';

    public static function respond(\WP_REST_Request $request, McpServer $server): \WP_REST_Response
    {
        $body    = (string) $request->get_body();
        $message = json_decode($body, true);

        if ($body === '' || json_last_error() !== JSON_ERROR_NONE) {
            return self::json(McpServer::error(null, McpServer::PARSE_ERROR, 'Parse error'), 400);
        }

        $answer = $server->handle($message);

        if ($answer === null) {
            $response = new \WP_REST_Response(null, 202);
            $response->header(self::EMPTY_HEADER, '1');

            return $response;
        }

        return self::json($answer, 200);
    }

    public static function method_not_allowed(): \WP_REST_Response
    {
        $response = self::json(McpServer::error(null, McpServer::INVALID_REQUEST, 'This MCP server only accepts POST requests.'), 405);
        $response->header('Allow', 'POST');

        return $response;
    }

    /**
     * Ablehnung vor dem Protokoll (abgeschaltet, nicht angemeldet, zu viele
     * Anfragen) - als JSON-RPC-Fehler, damit der Client die Meldung anzeigt.
     */
    public static function refuse(int $status, string $message): \WP_REST_Response
    {
        return self::json(McpServer::error(null, McpServer::INVALID_REQUEST, $message), $status);
    }

    /**
     * Antworten auf reine Benachrichtigungen (202) ohne Inhalt ausliefern -
     * WordPress wuerde sonst "null" schreiben.
     */
    public static function register_empty_body(): void
    {
        add_filter('rest_pre_serve_request', static function ($served, $result) {
            if ($result instanceof \WP_HTTP_Response && $result->get_status() === 202 && isset($result->get_headers()[self::EMPTY_HEADER])) {
                return true;
            }

            return $served;
        }, 10, 2);
    }

    /**
     * @param array<int|string, mixed> $data
     */
    private static function json(array $data, int $status): \WP_REST_Response
    {
        $response = new \WP_REST_Response($data, $status);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }
}
