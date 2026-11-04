<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Stock;

/**
 * Bestands-Ampel fuer Listen und Uebersicht: ausverkauft (<= 0), knapp
 * (unter oder auf dem Mindestbestand des Artikels) oder in Ordnung. Ohne
 * gesetzten Mindestbestand gibt es nur "ausverkauft" und "in Ordnung".
 */
final class StockStatus
{
    public const OUT = 'out';
    public const LOW = 'low';
    public const OK  = 'ok';

    public static function classify(int $balance, ?int $min_stock): string
    {
        if ($balance <= 0) {
            return self::OUT;
        }

        if ($min_stock !== null && $min_stock > 0 && $balance <= $min_stock) {
            return self::LOW;
        }

        return self::OK;
    }
}
