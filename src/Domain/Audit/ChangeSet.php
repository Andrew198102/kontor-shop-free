<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Audit;

/**
 * Ermittelt fuer das Aenderungsprotokoll, welche Felder sich zwischen zwei
 * Staenden geaendert haben (Feld => [alt, neu]). Verschachtelte Werte werden
 * zu Punkt-Pfaden ("payment.accounts.0.iban"), Listen einfacher Werte zu
 * einem Text ("3, 7").
 *
 * Geheimnisse (Tokens, Passwoerter, Schluessel) erscheinen nie im Klartext,
 * nur dass sie geaendert wurden; IBANs nur gekuerzt. Lange Texte werden
 * gekuerzt, damit ein Eintrag klein bleibt.
 */
final class ChangeSet
{
    public const EMPTY  = '(leer)';
    public const HIDDEN = '•••';

    private const SECRET_KEYS = ['token', 'password', 'passwort', 'secret', 'api_key', 'private_key', 'salt', '_hash'];
    private const MAX_VALUE   = 300;

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param array<int, string>   $ignore Pfade (oder Pfad-Anfaenge), die nicht zaehlen
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public static function diff(array $before, array $after, array $ignore = []): array
    {
        $old     = self::flatten($before);
        $new     = self::flatten($after);
        $changes = [];

        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $path) {
            $path = (string) $path;

            if (self::ignored($path, $ignore)) {
                continue;
            }

            $a = $old[$path] ?? null;
            $b = $new[$path] ?? null;

            if (self::same($a, $b)) {
                continue;
            }

            $changes[$path] = self::present($path, $a, $b);
        }

        return $changes;
    }

    /**
     * Stand eines Objekts beim Anlegen/Loeschen als "Aenderung" von/zu nichts.
     *
     * @param array<string, mixed> $state
     * @param array<int, string>   $ignore
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public static function created(array $state, array $ignore = []): array
    {
        return self::diff([], $state, $ignore);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<int, string>   $ignore
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public static function deleted(array $state, array $ignore = []): array
    {
        return self::diff($state, [], $ignore);
    }

    public static function is_secret(string $path): bool
    {
        $leaf = strtolower((string) substr((string) strrchr('.' . $path, '.'), 1));

        foreach (self::SECRET_KEYS as $needle) {
            if (str_contains($leaf, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "AT61 1904 3002 3457 3201" -> "AT61…3201"
     */
    public static function mask_iban(string $iban): string
    {
        $plain = strtoupper((string) preg_replace('/\s+/', '', $iban));

        return strlen($plain) > 8 ? substr($plain, 0, 4) . '…' . substr($plain, -4) : $plain;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, string|null>
     */
    private static function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];

        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value)) {
                if ($value === []) {
                    $flat[$path] = null;
                } elseif (self::scalar_list($value)) {
                    $flat[$path] = implode(', ', array_map([self::class, 'text'], $value));
                } else {
                    $flat += self::flatten($value, $path);
                }

                continue;
            }

            $flat[$path] = self::text($value);
        }

        return $flat;
    }

    /**
     * @param array<mixed> $value
     */
    private static function scalar_list(array $value): bool
    {
        if (array_keys($value) !== range(0, count($value) - 1)) {
            return false;
        }

        foreach ($value as $item) {
            if (is_array($item) || is_object($item)) {
                return false;
            }
        }

        return true;
    }

    private static function text(mixed $value): ?string
    {
        return match (true) {
            $value === null  => null,
            is_bool($value)  => $value ? '1' : '0',
            is_object($value) => get_class($value),
            default          => (string) $value,
        };
    }

    /**
     * null und '' gelten als gleich, ebenso Zahlen gleichen Werts ("12.50" = 12.5).
     */
    private static function same(?string $a, ?string $b): bool
    {
        $a = $a ?? '';
        $b = $b ?? '';

        if ($a === $b) {
            return true;
        }

        return is_numeric($a) && is_numeric($b) && (float) $a === (float) $b;
    }

    /**
     * @param array<int, string> $ignore
     */
    private static function ignored(string $path, array $ignore): bool
    {
        foreach ($ignore as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private static function present(string $path, ?string $a, ?string $b): array
    {
        if (self::is_secret($path)) {
            return [self::blank($a) ? self::EMPTY : self::HIDDEN, self::blank($b) ? self::EMPTY : self::HIDDEN];
        }

        if (str_contains(strtolower($path), 'iban')) {
            return [$a !== null ? self::mask_iban($a) : null, $b !== null ? self::mask_iban($b) : null];
        }

        return [self::shorten($a), self::shorten($b)];
    }

    private static function blank(?string $value): bool
    {
        return $value === null || $value === '';
    }

    private static function shorten(?string $value): ?string
    {
        if ($value === null || mb_strlen($value) <= self::MAX_VALUE) {
            return $value;
        }

        return mb_substr($value, 0, self::MAX_VALUE - 1) . '…';
    }
}
