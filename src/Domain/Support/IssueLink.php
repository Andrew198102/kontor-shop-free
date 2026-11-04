<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Support;

/**
 * Link auf ein vorausgefuelltes GitHub-Issue (Issue-Formular mit Feld-IDs als
 * Abfrageparameter, siehe .github/ISSUE_TEMPLATE im Support-Repo). GitHub
 * lehnt sehr lange Adressen ab; passt der Bericht nicht hinein, wird er
 * gekuerzt und der Hinweis ergaenzt, den vollstaendigen Bericht einzufuegen.
 */
final class IssueLink
{
    /** Oeffentliches Repo fuer Meldungen (der Code liegt in einem privaten Repo). */
    public const REPOSITORY = 'Andrew198102/kontor-shop-support';

    /** Sichere Obergrenze fuer die ganze Adresse. */
    public const MAX_URL = 7000;

    /**
     * @param array<string, string> $fields Feld-ID => Wert; das Feld $long wird bei Bedarf gekuerzt
     */
    public static function build(string $repository, string $template, string $title, array $fields, string $long = '', string $cut_note = ''): string
    {
        $base  = 'https://github.com/' . $repository . '/issues/new';
        $query = static fn (array $f): string => http_build_query(['template' => $template, 'title' => $title] + $f, '', '&', PHP_QUERY_RFC3986);

        $url = $base . '?' . $query($fields);

        if (strlen($url) <= self::MAX_URL || $long === '' || !isset($fields[$long])) {
            return $url;
        }

        $text = $fields[$long];
        $note = "\n\n" . $cut_note;

        // Zeilenweise kuerzen, bis die Adresse passt.
        $lines = explode("\n", $text);

        while ($lines !== []) {
            array_pop($lines);
            $fields[$long] = implode("\n", $lines) . $note;
            $url = $base . '?' . $query($fields);

            if (strlen($url) <= self::MAX_URL) {
                return $url;
            }
        }

        $fields[$long] = trim($cut_note);

        return $base . '?' . $query($fields);
    }
}
