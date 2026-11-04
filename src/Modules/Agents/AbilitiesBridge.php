<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Agents;

use Kontor\Shop\Infrastructure\Logging\Logger;
use Kontor\Shop\Modules\Agents\Mcp\Tool;
use Kontor\Shop\Modules\Agents\Mcp\ToolError;

/**
 * Meldet die Werkzeuge zusaetzlich bei der Abilities-API von WordPress an
 * (ab WordPress 6.9). Dann finden sie auch der offizielle MCP-Adapter und
 * andere KI-Plugins - mit der WordPress-Anmeldung und den Rechten des
 * jeweiligen Benutzers. Ohne Abilities-API passiert hier nichts; der eigene
 * MCP-Zugang des Shops funktioniert unabhaengig davon.
 */
final class AbilitiesBridge
{
    public const CATEGORY = 'kontor-shop';

    /**
     * @param callable(): array<int, Tool> $tools
     */
    public static function register(callable $tools): void
    {
        if (!function_exists('wp_register_ability')) {
            return;
        }

        add_action('wp_abilities_api_categories_init', static function (): void {
            if (function_exists('wp_register_ability_category')) {
                wp_register_ability_category(self::CATEGORY, [
                    'label'       => __('Kontor Shop', 'kontor-shop'),
                    'description' => __('Find products, prepare orders and manage the club shop.', 'kontor-shop'),
                ]);
            }
        });

        add_action('wp_abilities_api_init', static function () use ($tools): void {
            foreach ($tools() as $tool) {
                try {
                    self::register_tool($tool);
                } catch (\Throwable $e) {
                    Logger::exception($e, 'agents', 'Ability konnte nicht angemeldet werden: ' . $tool->name);
                }
            }
        });
    }

    public static function ability_name(Tool $tool): string
    {
        return self::CATEGORY . '/' . str_replace('_', '-', $tool->name);
    }

    private static function register_tool(Tool $tool): void
    {
        $args = [
            'label'               => $tool->title,
            'description'         => $tool->description,
            'category'            => self::CATEGORY,
            'execute_callback'    => static function ($input = null) use ($tool) {
                try {
                    return $tool->call(is_array($input) ? $input : []);
                } catch (ToolError $e) {
                    return new \WP_Error('kontor_shop_tool', $e->getMessage(), $e->data);
                }
            },
            'permission_callback' => static fn (): bool => $tool->allowed(),
            'meta'                => [
                'show_in_rest' => true,
                'annotations'  => [
                    'readonly'    => $tool->read_only,
                    'destructive' => $tool->destructive,
                    'idempotent'  => $tool->read_only,
                ],
                'mcp'          => ['public' => true],
            ],
        ];

        if ($tool->properties !== []) {
            $args['input_schema'] = $tool->input_schema();
        }

        wp_register_ability(self::ability_name($tool), $args);
    }
}
