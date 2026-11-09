<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Agents\Mcp;

use Kontor\Shop\Infrastructure\Logging\Logger;
use Kontor\Shop\Support\Arr;

/**
 * Model Context Protocol (JSON-RPC 2.0) fuer einen Satz Werkzeuge: initialize,
 * ping, tools/list, tools/call. Kein Zustand zwischen den Anfragen (keine
 * Sitzungs-ID, kein Server-Push) - jede POST-Anfrage steht fuer sich, das
 * passt zu WordPress und reicht fuer Werkzeuge.
 *
 * Ressourcen und Prompts gibt es nicht; die Listen kommen leer zurueck, damit
 * Clients, die danach fragen, nicht mit einem Fehler abbrechen.
 */
final class McpServer
{
    /** Neueste zuerst; ein Client mit unbekannter Version bekommt die neueste. */
    public const PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    public const PARSE_ERROR      = -32700;
    public const INVALID_REQUEST  = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS   = -32602;
    public const INTERNAL_ERROR   = -32603;

    /** @var array<string, Tool> */
    private array $tools = [];

    /**
     * @param array<int, Tool> $tools
     * @param array<string, mixed> $about weitere Angaben fuer serverInfo (description, icons, websiteUrl - MCP 2025-11-25)
     */
    public function __construct(
        private readonly string $name,
        private readonly string $title,
        private readonly string $version,
        private readonly string $instructions,
        array $tools,
        private readonly array $about = [],
    ) {
        foreach ($tools as $tool) {
            $this->tools[$tool->name] = $tool;
        }
    }

    /**
     * Eine JSON-RPC-Nachricht oder ein Batch. null = nur Benachrichtigungen,
     * es gibt nichts zu antworten (HTTP 202).
     *
     * @return array<int|string, mixed>|null
     */
    public function handle(mixed $message): ?array
    {
        if (is_array($message) && $message !== [] && Arr::is_list($message)) {
            $responses = array_values(array_filter(array_map(fn ($single): ?array => $this->handle_one($single), $message)));

            return $responses !== [] ? $responses : null;
        }

        return $this->handle_one($message);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function handle_one(mixed $message): ?array
    {
        if (!is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0' || !is_string($message['method'] ?? null)) {
            return self::error(is_array($message) ? ($message['id'] ?? null) : null, self::INVALID_REQUEST, 'Invalid Request');
        }

        $id     = $message['id'] ?? null;
        $method = $message['method'];
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        // Benachrichtigungen (ohne id) bekommen nie eine Antwort.
        if (!array_key_exists('id', $message)) {
            return null;
        }

        return match ($method) {
            'initialize'     => self::result($id, $this->initialize($params)),
            'ping'           => self::result($id, new \stdClass()),
            'tools/list'     => self::result($id, ['tools' => $this->list_tools()]),
            'tools/call'     => $this->call_tool($id, $params),
            'resources/list' => self::result($id, ['resources' => []]),
            'resources/templates/list' => self::result($id, ['resourceTemplates' => []]),
            'prompts/list'   => self::result($id, ['prompts' => []]),
            'logging/setLevel' => self::result($id, new \stdClass()),
            default          => self::error($id, self::METHOD_NOT_FOUND, 'Method not found: ' . $method),
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $requested = (string) ($params['protocolVersion'] ?? '');

        return [
            'protocolVersion' => in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : self::PROTOCOL_VERSIONS[0],
            'capabilities'    => ['tools' => ['listChanged' => false]],
            'serverInfo'      => ['name' => $this->name, 'title' => $this->title, 'version' => $this->version] + $this->about,
            'instructions'    => $this->instructions,
        ];
    }

    /**
     * Nur Werkzeuge, die der aktuelle Zugang auch benutzen darf - der Assistent
     * soll nichts angeboten bekommen, was dann abgelehnt wird.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list_tools(): array
    {
        $out = [];

        foreach ($this->tools as $tool) {
            if ($tool->allowed()) {
                $out[] = $tool->definition();
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function call_tool(mixed $id, array $params): array
    {
        $name = (string) ($params['name'] ?? '');
        $tool = $this->tools[$name] ?? null;

        if ($tool === null || !$tool->allowed()) {
            return self::error($id, self::INVALID_PARAMS, 'Unknown tool: ' . $name);
        }

        try {
            return self::result($id, self::content($tool->call($params['arguments'] ?? []), false));
        } catch (ToolError $e) {
            return self::result($id, self::content(['error' => $e->getMessage()] + $e->data, true));
        } catch (\Throwable $e) {
            $reference = Logger::exception($e, 'agents', 'KI-Werkzeug fehlgeschlagen: ' . $name);

            return self::result($id, self::content([
                /* translators: %s: error reference number */
                'error' => sprintf(__('Technical error (error code %s). Nothing more can be said here; the shop log has the details.', 'kontor-shop'), $reference),
            ], true));
        }
    }

    /**
     * Ergebnis als Text (fuer jeden Client lesbar) und strukturiert. Der Text
     * ist kompaktes JSON: Das Modell liest jedes Zeichen als Token,
     * Einrueckungen machen die Antwort rund 40 % groesser, ohne etwas zu sagen.
     *
     * @param array<int|string, mixed> $data
     * @return array<string, mixed>
     */
    private static function content(array $data, bool $is_error): array
    {
        $structured = $data !== [] && Arr::is_list($data) ? ['items' => $data] : $data;

        return [
            'content'           => [['type' => 'text', 'text' => (string) wp_json_encode($structured, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
            'structuredContent' => $structured === [] ? new \stdClass() : $structured,
            'isError'           => $is_error,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function result(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @return array<string, mixed>
     */
    public static function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
