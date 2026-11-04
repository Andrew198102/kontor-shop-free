<?php

declare(strict_types=1);

namespace Kontor\Shop\Support;

/**
 * Baut den Text einer Rechts-Checkbox (AGB, Datenschutz ...) aus einer im
 * Admin frei bearbeitbaren Vorlage. Platzhalter wie `{agb}` werden zu Links
 * auf die dort gewaehlten Seiten; ohne gewaehlte Seite bleibt nur der
 * Bezeichner als Klartext stehen. Alles ausser den Links wird escaped.
 */
final class LegalText
{
    /**
     * @param array<string, array{label: string, url: string}> $links Platzhalter (ohne Klammern) => Link
     */
    public static function render(string $template, array $links): string
    {
        $parts = preg_split('/(\{[a-z_]+\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        if ($parts === false) {
            return esc_html($template);
        }

        $html = '';

        foreach ($parts as $part) {
            $key = trim($part, '{}');

            if ($part !== '' && $part[0] === '{' && isset($links[$key])) {
                $link = $links[$key];

                $html .= $link['url'] !== ''
                    ? '<a href="' . esc_url($link['url']) . '" target="_blank" rel="noopener">' . esc_html($link['label']) . '</a>'
                    : esc_html($link['label']);

                continue;
            }

            $html .= esc_html($part);
        }

        return $html;
    }
}
