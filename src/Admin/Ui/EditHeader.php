<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin\Ui;

/**
 * Kopfleiste fuer Bearbeiten-Formulare (wie im Artikel-Editor): Zurueck-Link,
 * Titel, Marken, Hinweis auf ungespeicherte Aenderungen und der
 * Speichern-Knopf - klebt beim Scrollen oben, damit Speichern immer
 * erreichbar ist. Muss innerhalb des <form data-ks-dirty> stehen.
 */
final class EditHeader
{
    /**
     * @param string $badges_html  fertiges, bereits escaptes HTML
     * @param string $actions_html weitere Knoepfe/Links vor "Speichern" (escaptes HTML)
     */
    public static function render(string $back_url, string $back_label, string $title, string $submit_label, string $badges_html = '', string $actions_html = ''): void
    {
        echo '<div class="ks-ed-head"><div class="ks-ed-head__title">';
        echo '<a class="ks-ed-head__back" href="' . esc_url($back_url) . '">&larr; ' . esc_html($back_label) . '</a>';
        echo '<h1 class="wp-heading-inline">' . esc_html($title) . '</h1>';

        if ($badges_html !== '') {
            echo '<span class="ks-ed-head__badges">' . $badges_html . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- Aufrufer escapen
        }

        echo '</div><div class="ks-ed-head__actions">';
        echo '<span class="ks-dirty-flag" data-ks-dirty-flag hidden>' . esc_html__('Unsaved changes', 'kontor-shop') . '</span>';
        echo $actions_html; // phpcs:ignore WordPress.Security.EscapeOutput -- Aufrufer escapen
        echo '<button type="submit" class="button button-primary button-large">' . esc_html($submit_label) . '</button>';
        echo '</div></div><hr class="wp-header-end" />';
    }
}
