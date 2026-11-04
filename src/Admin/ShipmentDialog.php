<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin;

use Kontor\Shop\Admin\Ui\Css;
use Kontor\Shop\Domain\Order\Carrier;
use Kontor\Shop\Domain\Order\Fulfilment;
use Kontor\Shop\Domain\Order\Order;
use Kontor\Shop\Domain\Order\OrderItem;
use Kontor\Shop\Domain\Order\Shipment;
use Kontor\Shop\Infrastructure\Orders\ShipmentRecorder;

/**
 * Dialog "Versand erfassen" (Bestellliste und Detailansicht): Positionen je
 * Lager ankreuzen, pro Lager Versanddienst + Trackingnummer. Nicht
 * angekreuzte Positionen bleiben offen (Teilversand); Positionen fremder
 * Lager sieht ein Lagerverwalter nur zur Information. Ohne Teilversand
 * (ShipmentRecorder::allows_partial()) gehen alle offenen Positionen
 * zusammen raus: keine Haekchen je Artikel, den Lager-Schritt gibt es nur
 * bei mehreren Lagern (dann nur zur Information).
 */
final class ShipmentDialog
{
    public const USER_META_CARRIER = 'kontor_shop_last_carrier';

    public static function dialog_id(Order $order): string
    {
        return 'ks-ship-' . (int) $order->id;
    }

    public static function open_button(Order $order, bool $partial): string
    {
        $label = $order->self_pickup
            ? __('Record pickup …', 'kontor-shop')
            : ($partial ? __('Record remaining shipment …', 'kontor-shop') : __('Record shipment …', 'kontor-shop'));

        return '<button type="button" class="button button-primary" data-ks-dialog-open="' . esc_attr(self::dialog_id($order)) . '">' . esc_html($label) . '</button>';
    }

    /**
     * Assistent in drei Schritten, damit auch Gelegenheits-Nutzer nichts
     * versehentlich falsch markieren: 1. Lager ankreuzen (farbige Karten,
     * meist eines, bei Bedarf mehrere), 2. Artikel je Lager + Versanddienst/
     * Tracking, 3. Zusammenfassung pruefen. Gespeichert werden nur die
     * angekreuzten Lager (ship_warehouses).
     *
     * @param array<int, OrderItem> $items
     * @param array<int, Shipment> $shipments
     * @param array<int, int>|null $allowed_warehouses null = alle Lager (Shop-Admin)
     * @param array<int, string> $warehouse_names
     */
    public static function render(Order $order, array $items, array $shipments, ?array $allowed_warehouses, array $warehouse_names, string $action_url): void
    {
        $fulfilment = new Fulfilment($order, $items);
        $by_id      = [];

        foreach ($shipments as $shipment) {
            $by_id[(int) $shipment->id] = $shipment;
        }

        $groups = [];

        foreach ($items as $item) {
            $groups[$item->warehouse_id][] = $item;
        }

        ksort($groups);

        $pickup       = $order->self_pickup;
        $last_carrier = (string) get_user_meta(get_current_user_id(), self::USER_META_CARRIER, true);
        $last_carrier = Carrier::sanitize($last_carrier) ?? 'post_at';
        $title        = $pickup ? __('Record pickup', 'kontor-shop') : __('Record shipment', 'kontor-shop');
        $dialog_id    = self::dialog_id($order);
        $done_word    = $pickup ? __('picked up', 'kontor-shop') : __('shipped', 'kontor-shop');

        // Welche Lager kann dieser Benutzer jetzt erfassen?
        $selectable = [];

        foreach ($groups as $warehouse_id => $group_items) {
            $may   = $allowed_warehouses === null || in_array($warehouse_id, $allowed_warehouses, true);
            $open  = array_values(array_filter($group_items, static fn (OrderItem $item): bool => !$item->is_cancelled() && !$fulfilment->is_shipped($item)));

            if ($may && $open !== []) {
                $selectable[$warehouse_id] = $open;
            }
        }

        $partial = ShipmentRecorder::allows_partial($order);
        // Ohne Teilversand: ein Lager -> gleich zu den Artikeln; mehrere -> alle zusammen (Schritt 1 nur zur Information).
        $skip    = !$partial && count($selectable) <= 1;
        $preset  = count($selectable) === 1 ? (string) array_key_first($selectable) : ($partial ? '0' : 'all');
        $name    = static fn (int $warehouse_id): string => $warehouse_names[$warehouse_id] ?? '#' . $warehouse_id;

        echo '<dialog class="ks-dialog ks-dialog--wizard" id="' . esc_attr($dialog_id) . '" aria-labelledby="' . esc_attr($dialog_id) . '-title">';
        echo '<form method="post" action="' . esc_url($action_url) . '" class="ks-dialog__form" data-ks-ship-wizard data-preset="' . esc_attr($preset) . '"'
            . ($skip ? ' data-first-step="2"' : '')
            . ' data-done-word="' . esc_attr($done_word) . '" data-pickup="' . ($pickup ? '1' : '0') . '">';
        wp_nonce_field(OrdersPage::NONCE_ACTION, 'kontor_shop_order_nonce');
        echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $order->id) . '" />';
        echo '<input type="hidden" name="order_action" value="ship" />';
        echo '<input type="hidden" name="ship_warehouses" value="" data-ks-ship-warehouse />';

        echo '<header class="ks-dialog__head"><h2 id="' . esc_attr($dialog_id) . '-title">' . esc_html($title . ' – ' . $order->order_number) . '</h2>'
            . '<button type="button" class="ks-dialog__close" data-ks-dialog-close aria-label="' . esc_attr__('Close', 'kontor-shop') . '">&times;</button></header>';

        echo '<div class="ks-dialog__body">';
        echo '<ol class="ks-wizard-steps" aria-hidden="true">'
            . '<li data-step-pill="1"' . ($skip ? ' hidden' : '') . '>' . esc_html__('Warehouse', 'kontor-shop') . '</li>'
            . '<li data-step-pill="2">' . esc_html__('Product', 'kontor-shop') . '</li>'
            . '<li data-step-pill="3">' . esc_html__('Review', 'kontor-shop') . '</li></ol>';

        // ---- Schritt 1: Lager
        echo '<section class="ks-wizard-step" data-step="1">';
        echo '<h3>' . esc_html($pickup
            ? __('Step 1 of 3: Which warehouse was the order picked up from?', 'kontor-shop')
            : __('Step 1 of 3: Which warehouse did the package ship from?', 'kontor-shop')) . '</h3>';
        echo '<p class="description">' . esc_html($partial
            ? __('Check the warehouse that has just shipped. If several warehouses shipped at the same time, check all of them – each gets its own package with tracking in the next step. Warehouses you don’t check stay unchanged.', 'kontor-shop')
            : __('All open products go out now – each warehouse gets its own package with tracking in the next step.', 'kontor-shop')) . '</p><div class="ks-wh-cards">';

        foreach ($groups as $warehouse_id => $group_items) {
            $open_items = $selectable[$warehouse_id] ?? null;
            $style      = ' ' . Css::wh((int) $warehouse_id);

            if ($open_items !== null) {
                echo '<label class="ks-wh-card' . esc_attr($style) . '"><input type="checkbox" name="ks_wh_choice" value="' . esc_attr((string) $warehouse_id) . '" data-ks-wh-name="'
                    . esc_attr($name((int) $warehouse_id)) . '" data-ks-wh-class="' . esc_attr(Css::wh((int) $warehouse_id)) . '"' . checked($preset === 'all' || $preset === (string) $warehouse_id, true, false)
                    . ($partial ? '' : ' disabled') . ' />'
                    . '<span class="ks-wh-card__name">' . esc_html(sprintf(
                        /* translators: %s: warehouse name */
                        __('Warehouse %s', 'kontor-shop'),
                        $name((int) $warehouse_id)
                    )) . '</span>'
                    . '<span class="ks-wh-card__items">' . esc_html(sprintf(
                        /* translators: %d: number of open products */
                        _n('%d product open:', '%d products open:', count($open_items), 'kontor-shop'),
                        count($open_items)
                    )) . ' '
                    . esc_html(implode(', ', array_map(static fn (OrderItem $item): string => $item->quantity . '× ' . $item->product_name, $open_items))) . '</span></label>';

                continue;
            }

            $all_done = array_filter($group_items, static fn (OrderItem $item): bool => !$item->is_cancelled() && !$fulfilment->is_shipped($item)) === [];

            echo '<div class="ks-wh-card is-disabled' . esc_attr($style) . '"><span class="ks-wh-card__name">' . esc_html(sprintf(
                /* translators: %s: warehouse name */
                __('Warehouse %s', 'kontor-shop'),
                $name((int) $warehouse_id)
            )) . '</span>'
                . '<span class="ks-wh-card__items">' . esc_html($all_done
                    ? sprintf(
                        /* translators: %s: "shipped" or "picked up" */
                        __('✓ already %s – nothing left to do', 'kontor-shop'),
                        $done_word
                    )
                    : __('is recorded by the responsible warehouse itself', 'kontor-shop')) . '</span></div>';
        }

        echo '</div></section>';

        // ---- Schritt 2: Artikel + Versand
        echo '<section class="ks-wizard-step" data-step="2" hidden>';
        echo '<h3>' . esc_html(match (true) {
            $skip && $pickup => __('Step 1 of 2: Which products were picked up?', 'kontor-shop'),
            $skip            => __('Step 1 of 2: What’s in the package?', 'kontor-shop'),
            $pickup          => __('Step 2 of 3: Which products were picked up?', 'kontor-shop'),
            default          => __('Step 2 of 3: What’s in the package?', 'kontor-shop'),
        }) . '</h3>';

        foreach ($selectable as $warehouse_id => $open_items) {
            $field = 'ks-ship-' . (int) $order->id . '-' . (int) $warehouse_id;

            echo '<div class="ks-ship-group ' . esc_attr(Css::wh((int) $warehouse_id)) . '" data-wh="' . esc_attr((string) $warehouse_id) . '" hidden>';
            echo '<p class="ks-ship-group__title"><span class="ks-wh-chip ' . esc_attr(Css::wh((int) $warehouse_id)) . '">'
                . esc_html(sprintf(
                    /* translators: %s: warehouse name */
                    __('Warehouse %s', 'kontor-shop'),
                    $name((int) $warehouse_id)
                )) . '</span></p>';
            echo '<p class="description">' . esc_html($partial
                ? __('All products from this warehouse are checked. Uncheck products that aren’t included (yet) – they stay open and can be sent later.', 'kontor-shop')
                : __('All open products from this warehouse are included.', 'kontor-shop')) . '</p>';
            echo '<ul class="ks-ship-items">';

            foreach ($open_items as $item) {
                if (!$partial) {
                    echo '<li data-ks-item-label="' . esc_attr($item->quantity . '× ' . $item->product_name) . '">' . esc_html($item->quantity . ' × ' . $item->product_name) . '</li>';

                    continue;
                }

                echo '<li><label><input type="checkbox" name="items[]" value="' . esc_attr((string) $item->id) . '" data-ks-item-label="'
                    . esc_attr($item->quantity . '× ' . $item->product_name) . '" checked disabled /> ' . esc_html($item->quantity . ' × ' . $item->product_name) . '</label></li>';
            }

            echo '</ul>';

            if (!$pickup) {
                echo '<div class="ks-ship-tracking">';
                echo '<p><label for="' . esc_attr($field) . '-carrier">' . esc_html__('Carrier', 'kontor-shop') . '</label><br />';
                echo '<select id="' . esc_attr($field) . '-carrier" name="carrier[' . (int) $warehouse_id . ']" data-ks-carrier disabled>';

                foreach (Carrier::all() as $key => $carrier_label) {
                    echo '<option value="' . esc_attr($key) . '" ' . selected($last_carrier, $key, false) . '>' . esc_html($carrier_label) . '</option>';
                }

                echo '</select></p>';
                echo '<p class="ks-ship-tracking__number"><label for="' . esc_attr($field) . '-tracking">' . esc_html__('Tracking number (optional)', 'kontor-shop') . '</label><br />';
                echo '<input type="text" id="' . esc_attr($field) . '-tracking" name="tracking[' . (int) $warehouse_id . ']" class="large-text" autocomplete="off" spellcheck="false" data-ks-tracking disabled '
                    . 'placeholder="' . esc_attr__('copy from the shipping label', 'kontor-shop') . '" /></p>';
                echo '</div>';
            }

            echo '</div>';
        }

        echo '<p><label for="ks-ship-' . (int) $order->id . '-note">' . esc_html__('Internal note (optional)', 'kontor-shop') . '</label><br />';
        echo '<input type="text" id="ks-ship-' . (int) $order->id . '-note" name="shipment_note" class="large-text" data-ks-note placeholder="'
            . esc_attr__('e.g. “Cap to follow, reordered”', 'kontor-shop') . '" /></p>';
        echo '<p><label><input type="checkbox" name="notify_customer" value="1" checked data-ks-notify /> ' . esc_html($pickup
            ? __('Notify customer by email', 'kontor-shop')
            : __('Notify the customer by email (with a tracking link)', 'kontor-shop')) . '</label></p>';
        echo '</section>';

        // ---- Schritt 3: Pruefen
        echo '<section class="ks-wizard-step" data-step="3" hidden>';
        echo '<h3>' . esc_html($skip ? __('Step 2 of 2: Please review', 'kontor-shop') : __('Step 3 of 3: Please review', 'kontor-shop')) . '</h3>';
        echo '<div class="ks-ship-summary" data-ks-summary></div>';
        echo '<p class="description">' . esc_html__('Everything correct? Then save. Otherwise, go “Back” to correct it.', 'kontor-shop') . '</p>';
        echo '</section>';

        echo '<p class="ks-wizard-error" role="alert" data-ks-wizard-error hidden></p>';
        echo '</div>';

        echo '<footer class="ks-dialog__foot">'
            . '<button type="button" class="button" data-ks-dialog-close>' . esc_html__('Cancel', 'kontor-shop') . '</button>'
            . '<span class="ks-dialog__spacer"></span>'
            . '<button type="button" class="button" data-ks-wizard-back hidden>&larr; ' . esc_html__('Back', 'kontor-shop') . '</button> '
            . '<button type="button" class="button button-primary" data-ks-wizard-next>' . esc_html__('Next', 'kontor-shop') . ' &rarr;</button>'
            . '<button type="submit" class="button button-primary" data-ks-wizard-submit hidden>&#10003; '
            . esc_html($pickup ? __('Save as picked up now', 'kontor-shop') : __('Save as shipped now', 'kontor-shop')) . '</button>'
            . '</footer>';

        // Texte fuer die Zusammenfassung (admin.js)
        echo '<template data-ks-i18n>' . wp_json_encode([
            'warehouse'   => __('Warehouse', 'kontor-shop'),
            'items'       => $pickup ? __('Picked up', 'kontor-shop') : __('In package', 'kontor-shop'),
            'open'        => __('Stays open', 'kontor-shop'),
            'carrier'     => __('Carrier', 'kontor-shop'),
            'tracking'    => __('Tracking number', 'kontor-shop'),
            'noTracking'  => __('none – the customer won’t get a tracking link', 'kontor-shop'),
            'note'        => __('Internal note', 'kontor-shop'),
            'mail'        => __('Email to the customer', 'kontor-shop'),
            'yes'         => __('yes', 'kontor-shop'),
            'no'          => __('no', 'kontor-shop'),
            'pickWh'      => __('Please check at least one warehouse first.', 'kontor-shop'),
            /* translators: %s: warehouse name */
            'pickItem'    => __('No product is checked for warehouse %s. Check the products in the package – or go back and uncheck this warehouse.', 'kontor-shop'),
            /* translators: %d: number of warehouses */
            'several'     => __('Careful: you’re recording packages from %d warehouses at the same time.', 'kontor-shop'),
        ]) . '</template>';

        echo '</form></dialog>';
    }

    public static function shipment_summary(?Shipment $shipment, bool $pickup): string
    {
        if ($shipment === null) {
            return $pickup ? __('picked up', 'kontor-shop') : __('shipped', 'kontor-shop');
        }

        $parts = [
            ($shipment->type === Shipment::TYPE_PICKED_UP ? __('picked up on', 'kontor-shop') : __('shipped on', 'kontor-shop'))
                . ' ' . mysql2date('d.m.Y', (string) $shipment->created_at),
        ];

        if ($shipment->carrier !== null && $shipment->type === Shipment::TYPE_SHIPPED) {
            $parts[] = Carrier::label($shipment->carrier);
        }

        if ($shipment->tracking_number !== null) {
            $parts[] = $shipment->tracking_number;
        }

        return implode(' · ', $parts);
    }
}
