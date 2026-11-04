<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Notifications;

/**
 * Empfaengerlisten fuer interne Mails ("Neue Bestellung",
 * "Versandauftrag ans Lager"): Standard-Empfaenger, ausgewaehlte Benutzer und
 * frei eingetragene Adressen - gueltig, ohne Doppelte (Gross-/Kleinschreibung
 * egal). Reine Logik ohne WordPress (die Ereignisliste kommt ueber
 * DefaultTemplates auch von Erweiterungen).
 */
final class Recipients
{
    /**
     * Mail-Ereignisse, deren Empfaenger im Editor einstellbar sind (auch die
     * von Erweiterungen, DefaultTemplates::definitions()).
     *
     * @return array<int, string>
     */
    public static function configurable(): array
    {
        return array_keys(array_filter(DefaultTemplates::definitions(), static fn (array $definition): bool => $definition['configurable_recipients']));
    }

    /**
     * "a@b.at, c@d.at; e@f.at" -> gueltige Adressen.
     *
     * @return array<int, string>
     */
    public static function parse_list(string $list): array
    {
        return self::merge(preg_split('/[\s,;]+/', $list) ?: []);
    }

    /**
     * @param array<int, string> ...$lists
     * @return array<int, string>
     */
    public static function merge(array ...$lists): array
    {
        $out = [];

        foreach ($lists as $list) {
            foreach ($list as $email) {
                $email = trim((string) $email);

                if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    continue;
                }

                $out[strtolower($email)] ??= $email;
            }
        }

        return array_values($out);
    }

    /**
     * $list ohne die Adressen aus $remove (z. B. Kopie ohne den Hauptempfaenger).
     *
     * @param array<int, string> $list
     * @param array<int, string> $remove
     * @return array<int, string>
     */
    public static function without(array $list, array $remove): array
    {
        $blocked = array_map('strtolower', $remove);

        return array_values(array_filter($list, static fn (string $email): bool => !in_array(strtolower($email), $blocked, true)));
    }
}
