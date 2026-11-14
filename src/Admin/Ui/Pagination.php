<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin\Ui;

/**
 * Seitennavigation im WordPress-Stil ("25 Eintraege", Blaetterlinks) plus
 * Auswahl "Eintraege pro Seite".
 */
final class Pagination
{
    /** Platzhalter fuer die Anzahl im Link (wird in admin.js ersetzt). */
    private const PLACEHOLDER = 'KSPERPAGE';

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
     * Auswahl "Eintraege pro Seite": Beim Wechsel ruft admin.js den Link mit
     * dem neuen Wert auf (Filter und Sortierung bleiben, es geht auf Seite 1).
     *
     * Bewusst kein eigenes Formular: In Artikeln und Anfragen steht die
     * Auswahl innerhalb des Formulars fuer Sammelaktionen. Ein Formular darin
     * verwirft der Browser, der Wechsel schickte dann das Sammelaktions-
     * Formular ab und die Auswahl blieb wirkungslos.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $keep
     */
    public static function per_page_form(int $current, array $base, array $keep): void
    {
        $url = ListParams::url($base, $keep, ['paged' => 0, 'per_page' => self::PLACEHOLDER]);

        echo '<div class="ks-per-page"><label>' . esc_html__('per page', 'kontor-shop')
            . ' <select data-ks-per-page="' . esc_url($url) . '" data-ks-per-page-placeholder="' . esc_attr(self::PLACEHOLDER) . '">';

        foreach (ListParams::PER_PAGE_OPTIONS as $option) {
            echo '<option value="' . esc_attr((string) $option) . '"' . selected($current, $option, false) . '>' . esc_html((string) $option) . '</option>';
        }

        echo '</select></label></div>';
    }
}
