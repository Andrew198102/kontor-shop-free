<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Agents;

use Kontor\Shop\Modules\Agents\Mcp\Tool;
use Kontor\Shop\Settings\Repository;

/**
 * Welche Werkzeuge ein Server anbietet. "shop" ist der oeffentliche Zugang
 * fuer Kunden-Assistenten, "admin" der angemeldete fuer das Shop-Team.
 * Erweiterungen (Pro) haengen eigene Werkzeuge ueber den Filter
 * kontor_shop/agents/tools an.
 */
final class ToolRegistry
{
    public const SHOP  = 'shop';
    public const ADMIN = 'admin';

    /**
     * @return array<int, Tool>
     */
    public static function for(string $server, Repository $settings): array
    {
        $tools = $server === self::SHOP ? (new ShopTools($settings))->tools() : [];
        $tools = (array) apply_filters('kontor_shop/agents/tools', $tools, $server, $settings);

        return array_values(array_filter($tools, static fn ($tool): bool => $tool instanceof Tool));
    }
}
