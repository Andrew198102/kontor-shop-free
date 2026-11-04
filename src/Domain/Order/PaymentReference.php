<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Order;

/**
 * Eindeutige Zahlungsreferenz fuer den Bankabgleich (Abschnitt 3, Ziel 11).
 * Ebenfalls deterministisch aus der DB-ID abgeleitet, unabhaengig von
 * OrderNumber (getrennte Spalte, Abschnitt 5.3).
 */
final class PaymentReference
{
    public static function for_id(int $id): string
    {
        return sprintf('KS-%06d', $id);
    }
}
