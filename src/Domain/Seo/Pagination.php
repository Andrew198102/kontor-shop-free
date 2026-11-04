<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Seo;

final class Pagination
{
    public static function total_pages(int $total, int $per_page): int
    {
        return max(1, (int) ceil($total / max(1, $per_page)));
    }

    /**
     * Seitenzahlen fuer die Navigation; null steht fuer "…".
     *
     * @return array<int, int|null>
     */
    public static function window(int $current, int $last, int $radius = 2): array
    {
        $pages = [];

        for ($i = 1; $i <= $last; $i++) {
            if ($i === 1 || $i === $last || abs($i - $current) <= $radius) {
                $pages[] = $i;
            } elseif ($pages !== [] && end($pages) !== null) {
                $pages[] = null;
            }
        }

        return $pages;
    }
}
