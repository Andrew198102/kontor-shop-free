<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Shipping;

use Kontor\Shop\Settings\Repository;

/**
 * Versandkosten-Regelwerk (Abschnitt 6.4), ausgewertet in dieser
 * Reihenfolge: Selbstabholung -> Freiversand einer Erweiterung (Pro:
 * Mitglieder-Freiversand) -> Freiversand ab Bestellwert -> Pauschalbetrag.
 * Keine Zonen-/Gewichtsstaffelung (Abschnitt 4).
 */
final class ShippingCalculator
{
    public function __construct(private readonly Repository $settings)
    {
    }

    public function calculate(bool $self_pickup, bool $is_member, float $items_total): float
    {
        if ($self_pickup) {
            return 0.0;
        }

        /**
         * Filter kontor_shop/shipping/free: Versand entfaellt fuer diesen
         * Kaeufer (vor "Freiversand ab" und der Pauschale), z. B. Pro:
         * "Mitglieder zahlen keinen Versand". Standard: false.
         *
         * @param bool  $free
         * @param bool  $is_member   Kaeufer gilt als Mitglied (kontor_shop/membership/is_member)
         * @param float $items_total Warenwert (dieser Teil-Bestellung)
         */
        if ((bool) apply_filters('kontor_shop/shipping/free', false, $is_member, $items_total)) {
            return 0.0;
        }

        $threshold = (float) $this->settings->get('shipping.free_from_amount', 0);

        if ($threshold > 0 && $items_total >= $threshold) {
            return 0.0;
        }

        return round((float) $this->settings->get('shipping.flat_rate', 0), 2);
    }

    /**
     * Versandkosten je Teil-Bestellung, wenn ein Warenkorb aufgeteilt wird
     * (siehe SplitShipping und Einstellung shipping.split_mode).
     *
     * @param array<string|int, float> $group_totals Warenwert je Teil-Bestellung
     * @return array<string|int, float>
     */
    public function calculate_for_groups(bool $self_pickup, bool $is_member, array $group_totals): array
    {
        $per_group = [];

        foreach ($group_totals as $key => $total) {
            $per_group[$key] = $this->calculate($self_pickup, $is_member, round($total, 2));
        }

        return SplitShipping::allocate(
            (string) $this->settings->get('shipping.split_mode', SplitShipping::SEPARATE),
            $per_group,
            $this->calculate($self_pickup, $is_member, round(array_sum($group_totals), 2)),
            (float) $this->settings->get('shipping.split_flat_rate', 0)
        );
    }
}
