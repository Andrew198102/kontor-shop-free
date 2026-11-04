<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Shipping;

/**
 * Verteilt die Versandkosten, wenn ein Warenkorb in mehrere Bestellungen
 * aufgeteilt wird (verschiedene Bankkonten je Lager). Reine Rechenlogik.
 *
 * Modi (Einstellung shipping.split_mode):
 *  - separate: jede Teil-Bestellung berechnet ihre Versandkosten selbst
 *              (jedes Paket wird einzeln versendet),
 *  - unified:  Versand wird einmal auf Basis des gesamten Warenkorbs
 *              berechnet (normales Regelwerk, z. B. Freiversand ab Betrag),
 *  - custom:   ein eigener Pauschalpreis fuer aufgeteilte Bestellungen;
 *              greift ein Freiversand-Fall des Regelwerks, bleibt es bei 0.
 * Bei unified/custom wird der Betrag einmal auf die erste Teil-Bestellung
 * gebucht, alle weiteren bekommen 0.
 */
final class SplitShipping
{
    public const SEPARATE = 'separate';
    public const UNIFIED  = 'unified';
    public const CUSTOM   = 'custom';

    /**
     * @return array<int, string>
     */
    public static function modes(): array
    {
        return [self::SEPARATE, self::UNIFIED, self::CUSTOM];
    }

    /**
     * @param array<string|int, float> $per_group  Versandkosten je Teil-Bestellung nach normalem Regelwerk (Reihenfolge = Bestellreihenfolge)
     * @param float                    $cart_level Versandkosten nach Regelwerk fuer den gesamten Warenkorb
     * @param float                    $custom_rate Pauschale fuer den Modus custom
     * @return array<string|int, float> gleiche Schluessel wie $per_group
     */
    public static function allocate(string $mode, array $per_group, float $cart_level, float $custom_rate): array
    {
        if (count($per_group) <= 1 || !in_array($mode, [self::UNIFIED, self::CUSTOM], true)) {
            return $per_group;
        }

        $total = $mode === self::UNIFIED
            ? $cart_level
            : ($cart_level > 0 ? $custom_rate : 0.0);

        $result = [];
        $first  = true;

        foreach (array_keys($per_group) as $key) {
            $result[$key] = $first ? round(max(0.0, $total), 2) : 0.0;
            $first        = false;
        }

        return $result;
    }
}
