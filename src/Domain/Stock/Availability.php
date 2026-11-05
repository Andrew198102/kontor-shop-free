<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Stock;

/**
 * Was ein Besucher kaufen kann: Bestand abzueglich der Reservierungen
 * ANDERER Besucher (die eigene Reservierung zaehlt fuer ihn nicht ab).
 * Reine Rechenlogik, damit sie ohne WordPress testbar ist.
 */
final class Availability
{
    public const MIN_MINUTES     = 5;
    public const MAX_MINUTES     = 60;
    public const DEFAULT_MINUTES = 15;

    public static function free(int $balance, int $reserved_by_others = 0): int
    {
        return max(0, $balance - max(0, $reserved_by_others));
    }

    /**
     * Menge im Warenkorb an das anpassen, was frei ist.
     *
     * @return array{quantity: int, status: string} status: ok | reduced | removed
     */
    public static function clamp(int $wanted, int $free): array
    {
        if ($free <= 0) {
            return ['quantity' => 0, 'status' => 'removed'];
        }

        return $wanted > $free ? ['quantity' => $free, 'status' => 'reduced'] : ['quantity' => max(1, $wanted), 'status' => 'ok'];
    }

    /** Reservierungsdauer in Sekunden aus einer Minutenangabe (5-60, sonst 15). */
    public static function ttl_seconds(mixed $minutes): int
    {
        $m = (int) $minutes;

        return ($m >= self::MIN_MINUTES && $m <= self::MAX_MINUTES ? $m : self::DEFAULT_MINUTES) * 60;
    }

    /**
     * Frei verfuegbare Menge nach Erweiterungen, z. B. Pro-Sammelbestellung:
     * bis zum Bestellschluss bestellbar, auch ohne Bestand.
     *
     * @param int|null $variant_id null = alle Varianten zusammen, 0 = ohne Varianten
     */
    public static function filtered(int $free, int $product_id, ?int $variant_id): int
    {
        if (!function_exists('apply_filters')) {
            return $free;
        }

        /**
         * Filter kontor_shop/stock/free: frei verfuegbare Menge eines Artikels
         * bzw. einer Variante (Katalog, Warenkorb, Kasse, KI-Shopzugang).
         * Standard: Bestand minus Reservierungen anderer.
         *
         * @param int      $free
         * @param int      $product_id
         * @param int|null $variant_id
         */
        return max(0, (int) apply_filters('kontor_shop/stock/free', $free, $product_id, $variant_id));
    }
}
