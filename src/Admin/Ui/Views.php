<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin\Ui;

/**
 * Ansichten ueber einer Liste im WordPress-Stil ("Alle (12) | Offen (3)").
 * Ersetzt die frueheren Reiter (nav-tab) auf Listen-Seiten.
 */
final class Views
{
    /**
     * @param array<string, array{label: string, url: string, count?: int|null, hot?: bool}> $views
     */
    public static function render(array $views, string $current): void
    {
        $links = [];

        foreach ($views as $key => $view) {
            $count = $view['count'] ?? null;

            $links[] = '<li><a href="' . esc_url($view['url']) . '"' . ($key === $current ? ' class="current" aria-current="page"' : '') . '>' . esc_html($view['label'])
                . ($count !== null ? ' <span class="count' . (!empty($view['hot']) && $count > 0 ? ' ks-count-hot' : '') . '">(' . number_format_i18n($count) . ')</span>' : '')
                . '</a>';
        }

        echo '<ul class="subsubsub ks-views">' . implode(' | </li>', $links) . '</li></ul><br class="clear" />'; // phpcs:ignore WordPress.Security.EscapeOutput -- oben escapt
    }
}
