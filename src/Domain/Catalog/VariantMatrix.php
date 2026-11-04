<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Catalog;

/**
 * Merkmale eines Artikels (z. B. "Größe": S, M, L und "Farbe": Rot, Blau) und
 * die daraus entstehenden Kombinationen. Jede Kombination ist eine Variante
 * mit eigenem Bestand (product_variants). Reine Logik ohne WordPress, damit
 * sie per Unit-Test abgesichert werden kann.
 *
 * Gespeichert werden die Merkmale als JSON an products.variant_options:
 * [{"name":"Größe","values":["S","M"]},{"name":"Farbe","values":["Rot"]}]
 */
final class VariantMatrix
{
    public const MAX_OPTIONS      = 3;
    public const MAX_VALUES       = 30;
    public const MAX_COMBINATIONS = 200;
    public const MAX_LENGTH       = 100;

    /** Trenner in label() - auch in Bestellpositionen ("Pullover – M / Rot"). */
    public const LABEL_GLUE = ' / ';

    /**
     * Bereinigt Merkmale aus Formular oder Datenbank: Namen und Werte
     * getrimmt, leere und doppelte Werte (Gross/Klein egal) entfernt,
     * hoechstens MAX_OPTIONS Merkmale mit je MAX_VALUES Werten. Merkmale
     * ohne Werte fallen weg; ein Merkmal ohne Namen heisst "Variante".
     *
     * @param mixed $raw Liste von ['name' => string, 'values' => string|array<int, string>]
     * @return array<int, array{name: string, values: array<int, string>}>
     */
    public static function normalize(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $options = [];
        $names   = [];

        foreach ($raw as $option) {
            if (!is_array($option)) {
                continue;
            }

            $values = $option['values'] ?? [];
            $values = is_array($values) ? self::unique_values($values) : self::parse_values((string) $values);

            if ($values === []) {
                continue;
            }

            $name = self::clean((string) ($option['name'] ?? ''));
            $name = $name !== '' ? $name : 'Variante';
            $key  = self::fold($name);

            if (isset($names[$key])) {
                continue; // dasselbe Merkmal zweimal: nur das erste zaehlt
            }

            $names[$key] = true;
            $options[]   = ['name' => $name, 'values' => array_slice($values, 0, self::MAX_VALUES)];

            if (count($options) >= self::MAX_OPTIONS) {
                break;
            }
        }

        return $options;
    }

    /**
     * Werte aus einem Textfeld: getrennt durch Komma, Semikolon oder
     * Zeilenumbruch. Ein Komma zwischen zwei Ziffern ist ein Dezimalkomma
     * ("0,5 l, 1 l" = zwei Werte); eine reine Zahlenliste ("38,39,40") wird
     * trotzdem getrennt.
     *
     * @return array<int, string>
     */
    public static function parse_values(string $text): array
    {
        $values = [];

        foreach (preg_split('/[;\r\n]+|(?<!\d),|,(?!\d)/u', $text) ?: [] as $value) {
            if (preg_match('/^\s*\d+(?:,\d+){2,}\s*$/', $value) === 1) {
                array_push($values, ...explode(',', $value));
            } else {
                $values[] = $value;
            }
        }

        return self::unique_values($values);
    }

    /**
     * Alle Kombinationen in der Reihenfolge der Merkmale/Werte
     * (S/Rot, S/Blau, M/Rot, ...). Ohne Merkmale: keine Kombination.
     *
     * @param array<int, array{name: string, values: array<int, string>}> $options
     * @return array<int, array<int, string>>
     */
    public static function combinations(array $options): array
    {
        if ($options === []) {
            return [];
        }

        $result = [[]];

        foreach ($options as $option) {
            $next = [];

            foreach ($result as $prefix) {
                foreach ($option['values'] as $value) {
                    $next[] = array_merge($prefix, [$value]);

                    if (count($next) > self::MAX_COMBINATIONS) {
                        return array_slice($next, 0, self::MAX_COMBINATIONS);
                    }
                }
            }

            $result = $next;
        }

        return $result;
    }

    /** Anzahl der Kombinationen (ohne sie zu erzeugen). */
    public static function count(array $options): int
    {
        if ($options === []) {
            return 0;
        }

        $count = 1;

        foreach ($options as $option) {
            $count *= count($option['values']);
        }

        return $count;
    }

    /**
     * Vergleichsschluessel einer Kombination (Gross/Klein und Leerraum egal),
     * damit Bestand und Preise beim Umbenennen der Schreibweise erhalten bleiben.
     *
     * @param array<int, string> $values
     */
    public static function key(array $values): string
    {
        return implode("\x1F", array_map([self::class, 'fold'], array_values(array_filter(
            array_map([self::class, 'clean'], $values),
            static fn (string $value): bool => $value !== ''
        ))));
    }

    /**
     * @param array<int, string> $values
     */
    public static function label(array $values): string
    {
        return implode(self::LABEL_GLUE, array_values(array_filter(
            array_map([self::class, 'clean'], $values),
            static fn (string $value): bool => $value !== ''
        )));
    }

    /**
     * @return array<int, array{name: string, values: array<int, string>}>
     */
    public static function decode(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        return self::normalize(json_decode($json, true));
    }

    /**
     * @param array<int, array{name: string, values: array<int, string>}> $options
     */
    public static function encode(array $options): ?string
    {
        $options = self::normalize($options);

        return $options === [] ? null : (string) json_encode($options, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<int, mixed> $values
     * @return array<int, string>
     */
    private static function unique_values(array $values): array
    {
        $out  = [];
        $seen = [];

        foreach ($values as $value) {
            $value = self::clean((string) $value);
            $key   = self::fold($value);

            if ($value === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[]      = $value;
        }

        return $out;
    }

    private static function clean(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return function_exists('mb_substr') ? mb_substr($value, 0, self::MAX_LENGTH) : substr($value, 0, self::MAX_LENGTH);
    }

    private static function fold(string $value): string
    {
        $value = self::clean($value);

        return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    }
}
