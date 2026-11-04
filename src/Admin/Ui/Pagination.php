<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin\Ui;

/**
 * Seitennavigation im WordPress-Stil ("25 Eintraege", Blaetterlinks) plus
 * Auswahl "Eintraege pro Seite".
 */
final class Pagination
{
    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $keep
     */
    public static function render(int $total, int $page, int $per_page, array $base, array $keep): void
    {
        $pages = (int) max(1, ceil($total / max(1, $per_page)));

        echo '<div class="tablenav-pages' . ($pages <= 1 ? ' one-page' : '') . '">';
        echo '<span class="displaying-num">' . esc_html(sprintf(
            /* translators: %s: number of entries */
            _n('%s entry', '%s entries', $total, 'kontor-shop'),
            number_format_i18n($total)
        )) . '</span>';

        if ($pages > 1) {
            $links = paginate_links([
                'base'      => add_query_arg('paged', '%#%', ListParams::url($base, $keep, ['paged' => 0])),
                'format'    => '',
                'current'   => $page,
                'total'     => $pages,
                'prev_text' => '&lsaquo;',
                'next_text' => '&rsaquo;',
                'type'      => 'plain',
            ]);

            echo ' <span class="pagination-links">' . wp_kses_post((string) $links) . '</span>';
        }

        echo '</div>';
    }

    /**
     * Kleines "Eintraege pro Seite"-Formular (GET, behaelt alle Filter bei).
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $keep
     */
    public static function per_page_form(int $current, array $base, array $keep): void
    {
        echo '<form method="get" class="ks-per-page">';

        foreach (array_merge($base, $keep) as $name => $value) {
            if ($value !== '' && $value !== null) {
                echo '<input type="hidden" name="' . esc_attr((string) $name) . '" value="' . esc_attr((string) $value) . '" />';
            }
        }

        echo '<label>' . esc_html__('per page', 'kontor-shop') . ' <select name="per_page">';

        foreach (ListParams::PER_PAGE_OPTIONS as $option) {
            echo '<option value="' . esc_attr((string) $option) . '"' . selected($current, $option, false) . '>' . esc_html((string) $option) . '</option>';
        }

        echo '</select></label></form>';
    }
}
