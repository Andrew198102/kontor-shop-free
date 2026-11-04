<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Notifications;

/**
 * Platzhalter-Ersetzung fuer Mail-Vorlagen (Abschnitt 9): einfache
 * `{{platzhalter}}`-Syntax, in den Einstellungen editierbar
 * (Admin\TemplatesPage). Vorlagentexte sind HTML (Editor); aeltere,
 * gespeicherte Nur-Text-Vorlagen werden beim Versand automatisch in HTML
 * umgewandelt. Platzhalter-Werte sind immer reiner Text und werden fuer
 * HTML maskiert - Kundeneingaben koennen so kein HTML einschleusen.
 */
final class Template
{
    /**
     * Reine Text-Ersetzung (Betreffzeile).
     *
     * @param array<string, string> $values
     */
    public static function render(string $template, array $values): string
    {
        $search  = [];
        $replace = [];

        foreach ($values as $key => $value) {
            $search[]  = '{{' . $key . '}}';
            $replace[] = $value;
        }

        return str_replace($search, $replace, $template);
    }

    /**
     * HTML-Mailtext: Werte maskiert, Zeilenumbrueche als <br>, Links klickbar.
     *
     * @param array<string, string> $values
     */
    public static function render_html(string $template, array $values): string
    {
        $html = self::is_html($template) ? $template : self::text_to_html($template);

        $escaped = [];

        foreach ($values as $key => $value) {
            $escaped[$key] = self::value_to_html($value);
        }

        // Absaetze, die nur einen leeren Platzhalter enthielten (z. B. kein Tracking), entfernen.
        return (string) preg_replace('/<p>(\s|&nbsp;|<br\s*\/?>)*<\/p>\s*/i', '', self::render($html, $escaped));
    }

    /**
     * Enthaelt der Text HTML-Struktur (Editor) oder ist es eine alte Nur-Text-Vorlage?
     */
    public static function is_html(string $text): bool
    {
        return preg_match('/<(p|br|div|table|h[1-6]|ul|ol|li|strong|em|a|span)\b/i', $text) === 1;
    }

    /**
     * Nur-Text -> einfache Absaetze (Leerzeile = neuer Absatz, Umbruch = <br>).
     */
    public static function text_to_html(string $text): string
    {
        $paragraphs = preg_split('/\R{2,}/', trim($text)) ?: [];

        return implode("\n", array_map(
            static fn (string $paragraph): string => '<p>' . nl2br(htmlspecialchars($paragraph, ENT_QUOTES, 'UTF-8'), false) . '</p>',
            $paragraphs
        ));
    }

    /**
     * HTML -> lesbarer Nur-Text (Alternativteil der Mail, Versandwege aus Add-ons).
     */
    public static function html_to_text(string $html): string
    {
        $text = preg_replace('/<a\s[^>]*href=("|\')(.*?)\1[^>]*>(.*?)<\/a>/is', '$3 ($2)', $html) ?? $html;
        // nl2br() setzt "<br>\n" - der Umbruch dahinter ist derselbe Zeilenwechsel, nicht ein zweiter.
        $text = preg_replace('/<br\s*\/?>\r?\n?/i', "\n", $text) ?? $text;
        $text = preg_replace('/<\/(p|div|h[1-6]|tr|table|ul|ol)>/i', "\n\n", $text) ?? $text;
        $text = preg_replace('/<li[^>]*>/i', '- ', $text) ?? $text;
        $text = preg_replace('/<\/li>/i', "\n", $text) ?? $text;
        $text = html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/[ \t]+\n/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        // "https://x (https://x)" nach der Link-Umwandlung vermeiden.
        $text = preg_replace('/(https?:\/\/\S+) \(\1\)/', '$1', $text) ?? $text;

        return trim($text);
    }

    private static function value_to_html(string $value): string
    {
        $html = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $html = preg_replace('/https?:\/\/[^\s<]+/', '<a href="$0">$0</a>', $html) ?? $html;

        return nl2br($html, false);
    }
}
