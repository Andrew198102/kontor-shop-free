<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Audit;

use Kontor\Shop\Domain\Audit\ChangeSet;

/**
 * Aenderungsprotokoll (Audit): wer hat wann was im Shop geaendert.
 *
 * Gemeldet wird an den Schreibstellen (Repositories, Rechtevergabe,
 * Einstellungen), nicht ueber WordPress-Hooks - nur dort ist der alte Wert
 * fuer den Vergleich bekannt. Frontend-Lesezugriffe und Kundenbestellungen
 * erzeugen keine Eintraege. Geheimnisse kommen nur als "geaendert" an
 * (ChangeSet).
 *
 * Der Kern schreibt selbst nichts: record() reicht jeden Eintrag an die
 * Aktion kontor_shop/audit/record weiter. Ohne Zuhoerer passiert nichts
 * (auch kein Vergleich in record_diff()). Wer zuhoert, darf nie werfen -
 * ein Protokollproblem darf keine Aenderung verhindern.
 */
final class AuditLog
{
    /** Handelnde Stelle ohne WP-Benutzer (z. B. "API: Vereinskassa"), gilt fuer die laufende Anfrage. */
    private static ?string $actor = null;

    public static function act_as(?string $actor): void
    {
        self::$actor = $actor !== null && trim($actor) !== '' ? trim($actor) : null;
    }

    /** Schreibt ueberhaupt jemand mit? */
    public static function enabled(): bool
    {
        return function_exists('has_action') && has_action('kontor_shop/audit/record') !== false;
    }

    /**
     * @param array<string, array{0: string|null, 1: string|null}> $changes Feld => [alt, neu] (siehe ChangeSet)
     */
    public static function record(string $action, string $object_type, int|string|null $object_id = null, ?string $label = null, array $changes = [], ?string $actor = null): void
    {
        if (!self::enabled()) {
            return;
        }

        try {
            /**
             * Aktion kontor_shop/audit/record: ein Eintrag fuers Aenderungsprotokoll.
             * Ohne Zuhoerer wird nichts protokolliert.
             *
             * @param string                $action      z. B. "product.update"
             * @param string                $object_type z. B. "product"
             * @param int|string|null       $object_id
             * @param string|null           $label       lesbarer Name des Objekts
             * @param array<string, array{0: string|null, 1: string|null}> $changes Feld => [alt, neu]
             * @param string|null           $actor       handelnde Stelle ohne WP-Benutzer (act_as())
             */
            do_action('kontor_shop/audit/record', $action, $object_type, $object_id, $label, $changes, $actor ?? self::$actor);
        } catch (\Throwable) {
            // Protokollieren darf die eigentliche Aenderung nie verhindern.
        }
    }

    /**
     * Vergleicht zwei Staende und meldet nur, wenn sich etwas geaendert hat.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param array<int, string>   $ignore
     */
    public static function record_diff(string $action, string $object_type, int|string|null $object_id, ?string $label, array $before, array $after, array $ignore = [], ?string $actor = null): void
    {
        if (!self::enabled()) {
            return;
        }

        $changes = ChangeSet::diff($before, $after, $ignore);

        if ($changes !== []) {
            self::record($action, $object_type, $object_id, $label, $changes, $actor);
        }
    }
}
