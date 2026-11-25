<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Shipping;

use Kontor\Shop\Settings\Repository;

/**
 * Versandkosten-Regelwerk (Abschnitt 6.4), ausgewertet in dieser
 * Reihenfolge: Selbstabholung -> Freiversand einer Erweiterung ->
 * Freiversand ab Bestellwert -> Preis fuer Inland bzw. Ausland (Pro:
 * Mitgliederpreis, Filter kontor_shop/shipping/rate). Inland/Ausland siehe
 * ShippingRates; keine weitere Zonen-/Gewichtsstaffelung (Abschnitt 4).
 */
final class ShippingCalculator
{
    public function __construct(private readonly Repository $settings)
    {
    }

    /**
     * @param bool $abroad Lieferland ist nicht das Inland (ShippingRates::is_abroad)
     */
    public function calculate(bool $self_pickup, bool $is_member, float $items_total, bool $abroad = false): float
    {
        if ($self_pickup) {
            return 0.0;
        }

        /**
         * Filter kontor_shop/shipping/free: Versand entfaellt fuer diesen
         * Kaeufer (vor "Freiversand ab" und dem Versandpreis). Standard: false.
         * (Mitgliederpreise: Filter kontor_shop/shipping/rate.)
         *
         * @param bool  $free
         * @param bool  $is_member   Kaeufer gilt als Mitglied (kontor_shop/membership/is_member)
         * @param float $items_total Warenwert (dieser Teil-Bestellung)
         */
        if ((bool) apply_filters('kontor_shop/shipping/free', false, $is_member, $items_total)) {
            return 0.0;
        }

        $threshold = (float) $this->settings->get('shipping.free_from_amount', 0);

        // "Versandkostenfrei ab" gilt im Ausland nur mit "gilt auch fuers Ausland" (Standard an).
        if ($threshold > 0 && $items_total >= $threshold && (!$abroad || (bool) $this->settings->get('shipping.free_abroad', true))) {
            return 0.0;
        }

        $rate = $abroad ? ShippingRates::abroad($this->settings) : ShippingRates::domestic($this->settings);

        /**
         * Filter kontor_shop/shipping/rate: Versandpreis fuer diesen Kaeufer,
         * wenn keine Freiversand-Regel greift, z. B. Pro: eigener Preis fuer
         * Mitglieder (Inland/Ausland). Standard: Preis fuer Inland bzw. Ausland.
         *
         * @param float $rate
         * @param bool  $is_member   Kaeufer gilt als Mitglied
         * @param bool  $abroad      Lieferung ins Ausland
         * @param float $items_total Warenwert (dieser Teil-Bestellung)
         */
        $rate = (float) apply_filters('kontor_shop/shipping/rate', $rate, $is_member, $abroad, $items_total);

        return round(max(0.0, $rate), 2);
    }

    /**
     * Versandkosten je Teil-Bestellung, wenn ein Warenkorb aufgeteilt wird
     * (siehe SplitShipping und Einstellung shipping.split_mode).
     *
     * @param array<string|int, float> $group_totals Warenwert je Teil-Bestellung
     * @param bool                     $abroad       Lieferung ins Ausland
     * @return array<string|int, float>
     */
    public function calculate_for_groups(bool $self_pickup, bool $is_member, array $group_totals, bool $abroad = false): array
    {
        $per_group = [];

        foreach ($group_totals as $key => $total) {
            $per_group[$key] = $this->calculate($self_pickup, $is_member, round($total, 2), $abroad);
        }

        return SplitShipping::allocate(
            (string) $this->settings->get('shipping.split_mode', SplitShipping::SEPARATE),
            $per_group,
            $this->calculate($self_pickup, $is_member, round(array_sum($group_totals), 2), $abroad),
            (float) $this->settings->get('shipping.split_flat_rate', 0)
        );
    }
}
