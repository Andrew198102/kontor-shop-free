<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin\Ui;

/**
 * Kennzahlen-Kacheln ueber Listen (Bestellungen, Bestand, Rabatte): Zahl,
 * Beschriftung, Unterzeile. Eine Kachel ist ein Link - meist auf die
 * passend gefilterte Liste ("Kennzahl = Filter").
 */
final class Kpis
{
    public const BAD     = 'bad';
    public const WARN    = 'warn';
    public const INFO    = 'info';
    public const NEUTRAL = 'neutral';

    /**
     * @param string $value fertig formatierter Wert (wird escapt)
     * @param string $extra Zusatz klein hinter dem Wert, z. B. Betrag (wird escapt)
     */
    public static function tile(string $href, string $tone, string $value, string $label, string $sub = '', bool $current = false, string $extra = ''): string
    {
        return '<a class="ks-kpi ks-kpi--' . esc_attr($tone) . ($current ? ' is-current' : '') . '" href="' . esc_url($href) . '"' . ($current ? ' aria-current="page"' : '') . '>'
            . '<span class="ks-kpi__value">' . esc_html($value) . ($extra !== '' ? ' <small>' . esc_html($extra) . '</small>' : '') . '</span>'
            . '<span class="ks-kpi__label">' . esc_html($label) . '</span>'
            . ($sub !== '' ? '<span class="ks-kpi__sub">' . esc_html($sub) . '</span>' : '') . '</a>';
    }

    /**
     * @param array<int, string> $tiles Ergebnisse von tile()
     */
    public static function render(array $tiles): void
    {
        echo '<div class="ks-kpis">' . implode('', $tiles) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- tile() escapt
    }
}
