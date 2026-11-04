<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Agents\Mcp;

/**
 * Ein Werkzeug, das ein KI-Assistent aufrufen kann (MCP "tool", in WordPress
 * ab 6.9 zusaetzlich als "ability" angemeldet). Name, Beschreibung und
 * Eingabeschema sind fuer den Assistenten geschrieben - er waehlt danach aus,
 * was er aufruft. Der Handler bekommt die gepruefte Eingabe (Args) und gibt
 * ein Array zurueck, das als JSON beim Assistenten ankommt.
 *
 * read_only/destructive landen als Hinweise (annotations) beim Assistenten
 * und entscheiden, ob ein nur-lesender Zugang das Werkzeug nutzen darf.
 */
final class Tool
{
    /** @var \Closure(Args): array<string, mixed> */
    private \Closure $handler;

    /** @var (\Closure(): bool)|null */
    private ?\Closure $permission;

    /**
     * @param array<string, mixed> $properties Eingabefelder (JSON Schema)
     * @param array<int, string> $required Pflichtfelder
     * @param callable(Args): array<string, mixed> $handler
     * @param (callable(): bool)|null $permission null = jeder, der den Server erreicht
     */
    public function __construct(
        public readonly string $name,
        public readonly string $title,
        public readonly string $description,
        public readonly array $properties,
        public readonly array $required,
        callable $handler,
        public readonly bool $read_only = true,
        public readonly bool $destructive = false,
        ?callable $permission = null,
    ) {
        $this->handler    = \Closure::fromCallable($handler);
        $this->permission = $permission !== null ? \Closure::fromCallable($permission) : null;
    }

    public function allowed(): bool
    {
        return $this->permission === null || ($this->permission)();
    }

    /**
     * @return array<string, mixed>
     */
    public function input_schema(): array
    {
        $schema = [
            'type'                 => 'object',
            'properties'           => $this->properties === [] ? new \stdClass() : $this->properties,
            'additionalProperties' => false,
        ];

        if ($this->required !== []) {
            $schema['required'] = $this->required;
        }

        return $schema;
    }

    /**
     * Eintrag fuer tools/list.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name'        => $this->name,
            'title'       => $this->title,
            'description' => $this->description,
            'inputSchema' => $this->input_schema(),
            'annotations' => [
                'title'           => $this->title,
                'readOnlyHint'    => $this->read_only,
                'destructiveHint' => $this->destructive,
                'idempotentHint'  => $this->read_only,
                'openWorldHint'   => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     * @throws ToolError
     */
    public function call(mixed $arguments): array
    {
        $args = Args::check(['properties' => $this->properties, 'required' => $this->required], $arguments);

        return ($this->handler)($args);
    }
}
