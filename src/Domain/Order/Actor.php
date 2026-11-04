<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Order;

/**
 * Wer eine Kassen-Aktion (bezahlt, storniert, Rueckzahlung) ausgeloest hat:
 * ein WP-Benutzer im Shop-Admin oder ein Kassier in einer Gegenstelle
 * (z. B. einer Vereinsverwaltung) ueber die Kassen-Schnittstelle. Letzterer ist kein
 * WP-Benutzer - sein Name und der Zugang landen daher als Text im
 * Statusverlauf (Spalte actor) und an der Bestellung (paid_by_name/paid_via).
 */
final class Actor
{
    private function __construct(
        public readonly ?int $user_id,
        /** Name des Kassiers in der Gegenstelle (nur bei API) */
        public readonly ?string $name,
        /** Kanal: null = Shop-Admin, sonst "api:<Zugangs-ID>" */
        public readonly ?string $channel,
        /** Anzeigename des Zugangs, z. B. "Vereinskassa" (nur bei API) */
        public readonly ?string $channel_label,
    ) {
    }

    public static function user(?int $user_id): self
    {
        return new self($user_id !== null && $user_id > 0 ? $user_id : null, null, null, null);
    }

    public static function api(string $client_id, string $client_label, ?string $cashier_name): self
    {
        $name = $cashier_name !== null ? trim($cashier_name) : '';

        return new self(null, $name !== '' ? mb_substr($name, 0, 120) : null, 'api:' . $client_id, $client_label);
    }

    public function is_api(): bool
    {
        return $this->channel !== null;
    }

    /**
     * Text fuer die Spalte actor im Statusverlauf, z. B. "Maria Muster (Vereinskassa)";
     * null bei WP-Benutzern (die stehen in user_id).
     */
    public function label(): ?string
    {
        if (!$this->is_api()) {
            return null;
        }

        $via = (string) $this->channel_label;

        return $this->name !== null ? $this->name . ' (' . $via . ')' : $via;
    }
}
