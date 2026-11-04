<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin\Ui;

/**
 * Formular-Bausteine: eine beschriftete Zeile mit Hilfetext direkt am Feld
 * (Label links, Feld + Erklaerung rechts) und Farbpunkt fuer Listen.
 */
final class Form
{
    /**
     * @param string $control_html fertiges, bereits escaptes HTML des Feldes
     */
    public static function field(string $label, string $for, string $control_html, string $help = '', bool $required = false): void
    {
        echo '<div class="ks-field"><div class="ks-field__label">';
        echo $for !== '' ? '<label for="' . esc_attr($for) . '">' : '<span>';
        echo esc_html($label) . ($required ? ' <abbr title="' . esc_attr__('Required', 'kontor-shop') . '">*</abbr>' : '');
        echo $for !== '' ? '</label>' : '</span>';
        echo '</div><div class="ks-field__control">' . $control_html; // phpcs:ignore WordPress.Security.EscapeOutput -- Aufrufer escapen jedes Fragment

        if ($help !== '') {
            echo '<p class="description">' . esc_html($help) . '</p>';
        }

        echo '</div></div>';
    }

    /**
     * Feld mit Beschriftung darueber (fuer mehrspaltige Zeilen). Der Hilfetext
     * steht hinter einem ⓘ und wird mit dem Schalter "Hilfetexte" (Klasse
     * ks-show-help am Formular) unter dem Feld eingeblendet.
     *
     * @param string $control_html fertiges, bereits escaptes HTML des Feldes
     */
    public static function stacked(string $label, string $for, string $control_html, string $help = '', bool $required = false, string $class = ''): string
    {
        $html = '<div class="ks-f' . ($class !== '' ? ' ' . esc_attr($class) : '') . '"><div class="ks-f__label">';
        $html .= $for !== '' ? '<label for="' . esc_attr($for) . '">' : '<span>';
        $html .= esc_html($label) . ($required ? ' <abbr title="' . esc_attr__('Required', 'kontor-shop') . '">*</abbr>' : '');
        $html .= $for !== '' ? '</label>' : '</span>';
        $html .= $help !== '' ? self::info($help) : '';
        $html .= '</div>' . $control_html;

        if ($help !== '') {
            $html .= '<p class="ks-help">' . esc_html($help) . '</p>';
        }

        return $html . '</div>';
    }

    /**
     * Mehrere Felder nebeneinander; bricht bei wenig Platz automatisch um.
     */
    public static function row(string ...$fields): string
    {
        return '<div class="ks-f-row">' . implode('', $fields) . '</div>';
    }

    /**
     * ⓘ mit Hilfetext als Tooltip (Zeigen oder Tastaturfokus).
     */
    public static function info(string $help): string
    {
        return ' <span class="ks-info" tabindex="0" data-tip="' . esc_attr($help) . '" aria-label="' . esc_attr(sprintf(
            /* translators: %s: help text */
            __('Note: %s', 'kontor-shop'),
            $help
        )) . '">i</span>';
    }

    public static function color_dot(?string $hex): string
    {
        if ($hex === null || $hex === '') {
            return '—';
        }

        // SVG mit fill-Attribut: beliebige Farbe ohne Inline-Style.
        return '<svg class="ks-dot" viewBox="0 0 14 14" aria-hidden="true"><circle cx="7" cy="7" r="6.5" fill="' . esc_attr($hex) . '" /></svg> ' . esc_html($hex);
    }

    public static function card_open(string $title): void
    {
        echo '<section class="ks-card ks-card--form"><h2>' . esc_html($title) . '</h2><div class="ks-card__body">';
    }

    public static function card_close(): void
    {
        echo '</div></section>';
    }
}
