<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Agents;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Aehnliche Artikelnamen, wenn die Suche nichts findet: Tippfehler
 * ("Kusheltier"), andere Wortform ("Schneidbrett" fuer "Schneidebrett") oder
 * nur ein Teil eines zusammengesetzten Worts ("Brett" in "Holzbrett").
 * Der Assistent zeigt die Vorschlaege und fragt nach, statt zu raten oder die
 * ganze Artikelliste zu holen.
 *
 * Je Suchwort zaehlt das aehnlichste Wort im Namen (Teilwort, Editierabstand
 * oder gemeinsame Dreiergruppen - der beste Wert), der Name bekommt den
 * Durchschnitt ueber alle Suchwoerter.
 */
final class SimilarNames
{
    /** Ab hier gilt ein Name als aehnlich (0..1). */
    public const MIN_SCORE = 0.5;

    public const LIMIT = 5;

    /**
     * @param array<int, string> $names Artikel-ID => Name
     * @return array<int, int> Artikel-IDs, aehnlichste zuerst
     */
    public static function rank(string $query, array $names, int $limit = self::LIMIT): array
    {
        $words = self::words($query);

        if ($words === []) {
            return [];
        }

        $scored = [];

        foreach ($names as $id => $name) {
            $tokens = self::words($name);

            if ($tokens === []) {
                continue;
            }

            $sum = 0.0;

            foreach ($words as $word) {
                $best = 0.0;

                foreach ($tokens as $token) {
                    $best = max($best, self::similarity($word, $token));
                }

                $sum += $best;
            }

            $score = $sum / count($words);

            if ($score >= self::MIN_SCORE) {
                $scored[] = [(int) $id, $score, (string) $name];
            }
        }

        usort($scored, static fn (array $a, array $b): int => $b[1] <=> $a[1] ?: strnatcasecmp($a[2], $b[2]));

        return array_slice(array_column($scored, 0), 0, max(1, $limit));
    }

    /**
     * Kleingeschrieben, ohne Umlaute/Akzente; Woerter unter 3 Zeichen
     * (Groessen, "mit") zaehlen nicht.
     *
     * @return array<int, string>
     */
    private static function words(string $text): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(remove_accents($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($parts, static fn (string $w): bool => mb_strlen($w) >= 3)));
    }

    private static function similarity(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }

        // Teil eines zusammengesetzten Worts: "brett" in "holzbrett" und umgekehrt.
        if ((strlen($a) >= 4 && str_contains($b, $a)) || (strlen($b) >= 4 && str_contains($a, $b))) {
            return 0.9;
        }

        $length = max(strlen($a), strlen($b));
        $edit   = 1 - levenshtein($a, $b) / $length;

        return max($edit, self::trigrams($a, $b));
    }

    /** Dice-Koeffizient der Dreiergruppen - robust bei verschobenen Wortteilen. */
    private static function trigrams(string $a, string $b): float
    {
        $grams = static function (string $word): array {
            $padded = ' ' . $word . ' ';
            $out    = [];

            for ($i = 0, $n = strlen($padded) - 2; $i < $n; $i++) {
                $out[substr($padded, $i, 3)] = true;
            }

            return $out;
        };

        $ga = $grams($a);
        $gb = $grams($b);

        return 2 * count(array_intersect_key($ga, $gb)) / (count($ga) + count($gb));
    }
}
