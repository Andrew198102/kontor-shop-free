<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin\Ui;

/**
 * Einheitlicher Seitenkopf fuer Listen: Titel (mit optionalen Marken), rechts
 * die Aktionen (Primaeraktion zuletzt), darunter ein kurzer Erklaertext.
 * Bricht am Handy um; WordPress-Hinweise landen wie gewohnt hinter
 * .wp-header-end.
 */
final class PageHeader
{
    /**
     * @param array<int, array{label: string, url: string, primary?: bool, attrs?: string}> $actions
     * @param string $badges_html fertiges, bereits escaptes HTML (z. B. Badge::html())
     */
    public static function render(string $title, array $actions = [], string $description = '', string $badges_html = ''): void
    {
        echo '<div class="ks-page-head"><div class="ks-page-head__title"><h1 class="wp-heading-inline">' . esc_html($title) . '</h1>';

        if ($badges_html !== '') {
            echo '<span class="ks-page-head__badges">' . $badges_html . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- Aufrufer escapen
        }

        echo '</div>';

        if ($actions !== []) {
            echo '<div class="ks-page-head__actions">' . self::actions($actions) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PageHeader::actions() liefert escaptes HTML
        }

        echo '</div><hr class="wp-header-end" />';

        if ($description !== '') {
            echo '<p class="ks-page-head__desc">' . esc_html($description) . '</p>';
        }
    }

    /**
     * @param array<int, array{label: string, url: string, primary?: bool, attrs?: string}> $actions
     */
    public static function actions(array $actions): string
    {
        $html = '';

        foreach ($actions as $action) {
            $html .= '<a class="button' . (!empty($action['primary']) ? ' button-primary' : '') . '" href="' . esc_url($action['url']) . '"'
                . (isset($action['attrs']) ? ' ' . $action['attrs'] : '') . '>' . esc_html($action['label']) . '</a>';
        }

        return $html;
    }
}
