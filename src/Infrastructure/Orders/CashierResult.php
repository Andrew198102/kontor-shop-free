<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Orders;

/**
 * Ergebnis einer Kassen-Aktion (Cashier). error ist ein stabiler Code fuer
 * die Kassen-Schnittstelle (siehe docs/API_Kassen-Schnittstelle.md), message
 * der Text fuer Menschen.
 */
final class CashierResult
{
    public const INVALID_STATE     = 'invalid_state';
    public const CONFLICT          = 'conflict';
    public const NO_ITEMS          = 'no_items';
    public const NO_REFUND_PENDING = 'no_refund_pending';
    public const AMOUNT_INVALID    = 'amount_invalid';
    public const FAILED            = 'failed';

    /**
     * @param array<string, mixed> $data
     */
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $error,
        public readonly string $message,
        public readonly array $data,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function ok(array $data = []): self
    {
        return new self(true, null, '', $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fail(string $error, string $message, array $data = []): self
    {
        return new self(false, $error, $message, $data);
    }
}
