<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin\Ui;

/**
 * Gemeinsame Auswertung der Listen-Parameter (Seite, Sortierung, Suche,
 * Eintraege pro Seite) aus $_GET, samt Aufbau von Listen-Links und
 * sortierbaren Spaltenkoepfen.
 */
final class ListParams
{
    public const PER_PAGE_OPTIONS = [10, 25, 50, 100];
    private const PER_PAGE_META   = 'kontor_shop_per_page';

    public static function text(string $key): string
    {
        return isset($_GET[$key]) ? sanitize_text_field(wp_unslash((string) $_GET[$key])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige-Parameter
    }

    public static function key(string $key): string
    {
        return isset($_GET[$key]) ? sanitize_key((string) $_GET[$key]) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige-Parameter
    }

    public static function int(string $key): int
    {
        return isset($_GET[$key]) ? (int) $_GET[$key] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige-Parameter
    }

    public static function page(): int
    {
        return max(1, self::int('paged'));
    }

    /**
     * @param array<int, string> $allowed
     */
    public static function orderby(array $allowed, string $default): string
    {
        $value = self::key('orderby');

        return in_array($value, $allowed, true) ? $value : $default;
    }

    public static function order(string $default = 'asc'): string
    {
        $value = strtolower(self::key('order'));

        return in_array($value, ['asc', 'desc'], true) ? $value : $default;
    }

    /**
     * Eintraege pro Seite: GET-Wert, sonst die zuletzt gewaehlte Einstellung des Benutzers.
     */
    public static function per_page(int $default = 25): int
    {
        $requested = self::int('per_page');

        if (in_array($requested, self::PER_PAGE_OPTIONS, true)) {
            update_user_meta(get_current_user_id(), self::PER_PAGE_META, $requested);

            return $requested;
        }

        $stored = (int) get_user_meta(get_current_user_id(), self::PER_PAGE_META, true);

        return in_array($stored, self::PER_PAGE_OPTIONS, true) ? $stored : $default;
    }

    /**
     * Link auf eine Seite mit den aktuellen Listen-Parametern, einzelne
     * Werte ueberschrieben (leere Werte fallen weg).
     *
     * @param array<string, mixed> $base   feste Parameter (z. B. page)
     * @param array<string, mixed> $keep   zu erhaltende Parameter (Filter)
     * @param array<string, mixed> $change ueberschreibende Parameter
     */
    public static function url(array $base, array $keep, array $change = []): string
    {
        $args = array_merge($base, $keep, $change);
        $args = array_filter($args, static fn ($value): bool => $value !== '' && $value !== null && $value !== 0 && $value !== '0');
        $args = array_map(static fn ($value): string => rawurlencode((string) $value), $args); // add_query_arg() kodiert selbst nichts

        return add_query_arg($args, admin_url('admin.php'));
    }

    /**
     * Sortierbarer Spaltenkopf im WordPress-Stil (Pfeil, Klick wechselt die Richtung).
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $keep
     */
    public static function sortable_th(string $label, string $column, string $current_orderby, string $current_order, array $base, array $keep, string $class = ''): string
    {
        $is_current = $column === $current_orderby;
        $next       = $is_current && $current_order === 'asc' ? 'desc' : 'asc';
        $url        = self::url($base, $keep, ['orderby' => $column, 'order' => $next, 'paged' => 0]);
        $classes    = trim('manage-column sortable ' . ($is_current ? $current_order : 'asc') . ' ' . $class);
        $aria       = $is_current ? ($current_order === 'asc' ? 'ascending' : 'descending') : 'none';

        return '<th scope="col" class="' . esc_attr($classes) . '" aria-sort="' . esc_attr($aria) . '"><a href="' . esc_url($url) . '"><span>'
            . esc_html($label) . '</span><span class="sorting-indicators"><span class="sorting-indicator asc" aria-hidden="true"></span>'
            . '<span class="sorting-indicator desc" aria-hidden="true"></span></span></a></th>';
    }
}
