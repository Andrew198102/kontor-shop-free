<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Seo;

/**
 * Kategorie-Hierarchie (categories.parent_id). Karten sind
 * id => parent_id (null = Wurzel); zyklenfest, damit kaputte Daten die
 * Seite nie in eine Endlosschleife schicken.
 */
final class CategoryTree
{
    /**
     * Vorfahren von unten nach oben (Eltern zuerst), ohne die Kategorie selbst.
     *
     * @param array<int, int|null> $parents
     * @return array<int, int>
     */
    public static function ancestors(int $id, array $parents): array
    {
        $chain = [];
        $seen  = [$id => true];
        $cur   = $parents[$id] ?? null;

        while ($cur !== null && $cur > 0 && !isset($seen[$cur]) && array_key_exists($cur, $parents)) {
            $chain[]    = $cur;
            $seen[$cur] = true;
            $cur        = $parents[$cur];
        }

        return $chain;
    }

    /**
     * @param array<int, int|null> $parents
     * @return array<int, int>
     */
    public static function descendants(int $id, array $parents): array
    {
        $found = [];
        $queue = [$id];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($parents as $child => $parent) {
                if ($parent === $current && $child !== $id && !in_array($child, $found, true)) {
                    $found[] = $child;
                    $queue[] = $child;
                }
            }
        }

        return $found;
    }

    /**
     * Wuerde $parent_id als Elternteil von $id einen Kreis erzeugen?
     *
     * @param array<int, int|null> $parents
     */
    public static function would_cycle(int $id, int $parent_id, array $parents): bool
    {
        return $parent_id === $id || in_array($parent_id, self::descendants($id, $parents), true);
    }
}
