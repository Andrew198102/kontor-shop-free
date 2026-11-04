<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Order;

/**
 * Fortlaufende interne Bestellreferenz (Abschnitt 5.3: "kein
 * Rechnungsersatz - nur interne Referenz"), deterministisch aus der
 * DB-ID abgeleitet - dadurch garantiert eindeutig, ohne eigenen Zaehler.
 */
final class OrderNumber
{
    public static function for_id(int $id): string
    {
        return sprintf('B-%06d', $id);
    }
}
