<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Stock;

/**
 * Bestand als Ledger, nicht als reiner Zaehler (Abschnitt 5.1): der aktuelle
 * Bestand eines Artikels ist die Summe aller quantity_delta-Werte seiner
 * stock_movements. Reine Rechenlogik ohne WordPress-Abhaengigkeit, damit sie
 * per Unit-Test abgesichert werden kann (Abschnitt 12).
 */
final class StockLedger
{
    /**
     * @param array<int, array{quantity_delta: int|string}> $movements
     */
    public static function balance(array $movements): int
    {
        $balance = 0;

        foreach ($movements as $movement) {
            $balance += (int) $movement['quantity_delta'];
        }

        return $balance;
    }

    /**
     * Vorzeichenbehaftetes Delta fuer eine manuell erfasste Bewegung
     * (StockPage, Abschnitt 7): Zukauf/Retoure sind immer positiv, Offline-Verkauf,
     * Defekt, Verlust/Diebstahl und Freiware immer negativ, Korrektur je nach gewaehlter Richtung. $quantity wird
     * als Betrag (>= 0) uebergeben.
     */
    public static function signed_delta(string $type, int $quantity, bool $is_increase = true): int
    {
        $quantity = abs($quantity);

        return match ($type) {
            MovementType::OFFLINE_SALE,
            MovementType::DEFECTIVE,
            MovementType::STOLEN,
            MovementType::GIVEAWAY   => -$quantity,
            MovementType::CORRECTION => $is_increase ? $quantity : -$quantity,
            default                    => $quantity,
        };
    }

    /**
     * Wechselt der Bestand durch eine Buchung von "nichts da" auf "wieder da"?
     * (Ausloeser fuer kontor_shop/stock/restocked.)
     */
    public static function is_restock(int $balance_before, int $delta): bool
    {
        return $balance_before <= 0 && $balance_before + $delta > 0;
    }
}
