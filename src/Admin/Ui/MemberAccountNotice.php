<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin\Ui;

use Kontor\Shop\Domain\Membership\MemberAccountCheck;
use Kontor\Shop\Domain\Order\Order;
use Kontor\Shop\Support\Countries;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Anzeige "Mit welchem Mitgliedskonto bestellt, und was weicht ab?" fuer
 * Bestelldetail, Bestellliste und Packansicht (Daten: Order::$member_account,
 * Domain\Membership\MemberAccountCheck).
 *
 * Reiner Hinweis: Mit dem eigenen Konto fuer jemand anderen einzukaufen ist
 * erlaubt - der Verein soll es nur sehen und bei Bedarf nachfragen koennen.
 * Bestellungen ohne Mitglieds-Login (oder von vor dieser Funktion) zeigen
 * nichts an.
 */
final class MemberAccountNotice
{
    /** "Hubert Maier (Nr. A-1234)" */
    public static function account_label(MemberAccountCheck $check): string
    {
        $name = $check->name !== '' ? $check->name : $check->email;

        if ($check->member_number === null) {
            return $name;
        }

        return sprintf(
            /* translators: 1: member name, 2: membership number */
            __('%1$s (no. %2$s)', 'kontor-shop'),
            $name,
            $check->member_number
        );
    }

    public static function field_label(string $field): string
    {
        return match ($field) {
            'name'        => __('Name', 'kontor-shop'),
            'email'       => __('Email', 'kontor-shop'),
            'phone'       => __('Phone', 'kontor-shop'),
            'street'      => __('Street and house number', 'kontor-shop'),
            'postal_code' => __('Postal code', 'kontor-shop'),
            'city'        => __('City', 'kontor-shop'),
            'country'     => __('Country', 'kontor-shop'),
            default       => $field,
        };
    }

    /**
     * Kurzer Satz fuer Liste, Packansicht und Schnittstellen.
     */
    public static function summary(MemberAccountCheck $check): string
    {
        if ($check->other_person()) {
            return sprintf(
                /* translators: %s: member name with membership number */
                __('Ordered via the member account of %s – probably for someone else.', 'kontor-shop'),
                self::account_label($check)
            );
        }

        if ($check->has_deviations()) {
            return sprintf(
                /* translators: %s: member name with membership number */
                __('Ordered via the member account of %s – some details differ from the member profile.', 'kontor-shop'),
                self::account_label($check)
            );
        }

        return sprintf(
            /* translators: %s: member name with membership number */
            __('Ordered via the member account of %s.', 'kontor-shop'),
            self::account_label($check)
        );
    }

    /**
     * Inhalt der Zeile "Mitglied" im Bestelldetail: ja/nein, darunter das Konto und - bei Abweichungen - der
     * Hinweis mit aufklappbarer Liste "Feld | Mitgliedsprofil | Bestellung".
     */
    public static function detail_html(Order $order): string
    {
        $answer = $order->is_member ? esc_html__('yes', 'kontor-shop') : esc_html__('no', 'kontor-shop');
        $check  = $order->member_account;

        if ($check === null) {
            return $answer;
        }

        $html = $answer . '<br /><small>' . esc_html(sprintf(
            /* translators: %s: member name with membership number */
            __('Member account: %s', 'kontor-shop'),
            self::account_label($check)
        )) . '</small>';

        if (!$check->has_deviations()) {
            return $html;
        }

        $html .= '<div class="ks-member-check ks-member-check--' . ($check->other_person() ? 'person' : 'minor') . '">';
        $html .= '<p><strong>' . esc_html($check->other_person()
            ? sprintf(
                /* translators: %s: customer name on the order */
                __('The order is in the name of %s – probably bought for someone else.', 'kontor-shop'),
                $check->order_value('name') ?? $order->customer_name
            )
            : __('Some details differ from the member profile.', 'kontor-shop')) . '</strong><br />'
            . esc_html__('That\'s allowed – just for your information, follow up if needed.', 'kontor-shop') . '</p>';
        $html .= '<details><summary>' . esc_html(sprintf(
            /* translators: %d: number of differing fields */
            _n('Show difference (%d)', 'Show differences (%d)', count($check->deviations), 'kontor-shop'),
            count($check->deviations)
        )) . '</summary>';
        $html .= '<table class="ks-member-check__table"><thead><tr><th scope="col">' . esc_html__('Field', 'kontor-shop') . '</th><th scope="col">'
            . esc_html__('Member profile', 'kontor-shop') . '</th><th scope="col">' . esc_html__('Order', 'kontor-shop') . '</th></tr></thead><tbody>';

        foreach ($check->deviations as $deviation) {
            $html .= '<tr><th scope="row">' . esc_html(self::field_label($deviation['field'])) . '</th><td>'
                . esc_html(self::value($deviation['field'], $deviation['profile'])) . '</td><td>'
                . esc_html(self::value($deviation['field'], $deviation['order'])) . '</td></tr>';
        }

        return $html . '</tbody></table></details></div>';
    }

    /**
     * Kennzeichen in der Bestellliste: nur bei Abweichungen (sonst genuegt das Kennzeichen "Mitglied").
     */
    public static function list_badge(Order $order): string
    {
        $check = $order->member_account;

        if ($check === null || !$check->has_deviations()) {
            return '';
        }

        $label = $check->other_person() ? __('Bought for someone else?', 'kontor-shop') : __('Differs from profile', 'kontor-shop');

        return ' <span class="ks-badge ks-badge--' . ($check->other_person() ? 'warn' : 'muted') . '" title="' . esc_attr(self::summary($check)) . '">'
            . esc_html($label) . '</span>';
    }

    /**
     * Gespeicherte Daten fuer Schnittstellen (Kassen-Schnittstelle, KI-Assistenten), null = ohne Mitglieds-Login.
     *
     * @return array{member_id: string|null, member_number: string|null, name: string, email: string, other_person: bool, summary: string, deviations: array<int, array{field: string, label: string, profile: string, order: string}>}|null
     */
    public static function to_api(?MemberAccountCheck $check): ?array
    {
        if ($check === null) {
            return null;
        }

        return [
            'member_id'     => $check->member_id,
            'member_number' => $check->member_number,
            'name'          => $check->name,
            'email'         => $check->email,
            'other_person'  => $check->other_person(),
            'summary'       => self::summary($check),
            'deviations'    => array_map(static fn (array $d): array => [
                'field'   => $d['field'],
                'label'   => self::field_label($d['field']),
                'profile' => $d['profile'],
                'order'   => $d['order'],
            ], $check->deviations),
        ];
    }

    private static function value(string $field, string $value): string
    {
        if ($field === 'country' && strlen($value) === 2 && Countries::is_known(strtoupper($value))) {
            return Countries::name(strtoupper($value));
        }

        return $value;
    }
}
