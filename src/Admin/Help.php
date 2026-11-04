<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin;

/**
 * Kontexthilfe: der Reiter "Hilfe" oben rechts in jeder Kontor-Shop-Seite
 * erklaert in wenigen Saetzen, wofuer die Seite da ist und was Aktionen bewirken.
 */
final class Help
{
    /**
     * @return array<int, array{id: string, title: string, content: string}>
     */
    private static function tabs(string $page): array
    {
        /**
         * Filter kontor_shop/admin/help_tabs: Hilfe-Reiter einer Seite
         * ergaenzen oder ersetzen - auch fuer Seiten von Erweiterungen, die
         * Menu::add_page() mit eigenem Hilfe-Schluessel anlegen. Standard:
         * die eingebauten Reiter der Seite (unbekannte Seite: keine).
         *
         * @param array<int, array{id: string, title: string, content: string}> $tabs    content ist fertiges HTML (escaped)
         * @param string                                                          $page    Hilfe-Schluessel, z. B. 'orders', 'stock'
         */
        $tabs = apply_filters('kontor_shop/admin/help_tabs', self::builtin_tabs($page), $page);

        return array_values(array_filter((array) $tabs, static fn ($tab): bool => is_array($tab) && isset($tab['id'], $tab['title'], $tab['content'])));
    }

    /**
     * Absaetze fuer content (jede Zeile einzeln escaped) - auch fuer Erweiterungen.
     */
    public static function paragraphs(string ...$lines): string
    {
        return '<p>' . implode('</p><p>', array_map('esc_html', $lines)) . '</p>';
    }

    /**
     * @return array<int, array{id: string, title: string, content: string}>
     */
    private static function builtin_tabs(string $page): array
    {
        $p = static fn (string ...$lines): string => self::paragraphs(...$lines);

        return match ($page) {
            'dashboard' => [
                ['id' => 'ks-dash', 'title' => __('Dashboard', 'kontor-shop'), 'content' => $p(
                    __('The bar at the top shows whether the shop is running: checkout, emails and background tasks. Click it for the individual checks.', 'kontor-shop'),
                    __('Here you see what needs doing now: open orders and products with low stock. Click a tile to open the matching list.', 'kontor-shop'),
                    __('The “Set up your shop” checklist disappears as soon as everything required is done.', 'kontor-shop')
                )],
            ],
            'orders' => [
                ['id' => 'ks-orders', 'title' => __('Orders', 'kontor-shop'), 'content' => $p(
                    __('The usual flow: Open → Paid (check that the payment arrived) → Shipped or Picked up. When you cancel, you decide per product whether the goods go back to the warehouse.', 'kontor-shop'),
                    __('“To do” shows everything waiting for you: payment open, to ship, pickup, refund. The ▸ before the number expands the quick view (items, address, history, internal note); “3 products ▸” expands the products.', 'kontor-shop'),
                    __('“Paid ✓” asks for the payment method and the date received. Under ⋯ you’ll find payment reminder, resend confirmation and cancel.', 'kontor-shop'),
                    __('Use the checkboxes on the left to select several orders and apply a bulk action. Customers are emailed automatically when an order is “paid” and “shipped”.', 'kontor-shop'),
                    __('You can only delete an order permanently from its detail view – order data is usually subject to a retention requirement.', 'kontor-shop')
                )],
            ],
            'products' => [
                ['id' => 'ks-products', 'title' => __('Product', 'kontor-shop'), 'content' => $p(
                    __('Visible/hidden: use the switch in the list to take a product out of the shop right away – it is kept. “Archive” also removes it from the working list.', 'kontor-shop'),
                    __('Quick edit changes name, price, category and visibility right in the row. “Duplicate” creates a hidden copy (stock 0).', 'kontor-shop'),
                    __('Bulk actions apply to all checked products. Permanent deletion is only possible from the archive.', 'kontor-shop')
                )],
            ],
            'stock' => [
                ['id' => 'ks-stock', 'title' => __('Stock', 'kontor-shop'), 'content' => $p(
                    __('Stock is the sum of all bookings. Book in increases it, book out decreases it – every booking stays traceable in the history.', 'kontor-shop'),
                    __('“Low” means: stock is at or below the minimum stock (click the number in the list to change it). “Sold out” means: 0 or less – for products with sizes, a single empty size is enough.', 'kontor-shop'),
                    __('“On the shelf” also counts goods that are already sold but not yet shipped or picked up.', 'kontor-shop'),
                    __('Record delivery books in several products at once. During a stock count, only differences are booked as a correction.', 'kontor-shop')
                )],
            ],
            'warehouses' => [
                ['id' => 'ks-wh', 'title' => __('Warehouse', 'kontor-shop'), 'content' => $p(
                    __('Every product belongs to exactly one warehouse. The warehouse is the sender of its packages.', 'kontor-shop'),
                    __('If products from warehouses with different bank accounts end up in one cart, the order is split automatically.', 'kontor-shop')
                )],
            ],
            'categories' => [
                ['id' => 'ks-cat', 'title' => __('Categories', 'kontor-shop'), 'content' => $p(
                    __('Categories are the filters in the shop. Drag a row by the arrows to change the order – it applies in the shop right away.', 'kontor-shop')
                )],
            ],
            'settings' => [
                ['id' => 'ks-settings', 'title' => __('Settings', 'kontor-shop'), 'content' => $p(
                    __('On the left, the sections are in four groups: Sales, Communication, Shop appearance and Technical. “open” marks sections where something is still missing for setup. On your phone, choose the section from the list at the top.', 'kontor-shop'),
                    __('Each form has its own save bar at the bottom; it shows when there are unsaved changes. Changes take effect in the shop right away.', 'kontor-shop'),
                    __('Recommended setup order: Payment & VAT → Shipping & returns → Legal → Email sending → Design.', 'kontor-shop')
                )],
            ],
            default => [],
        };
    }

    public static function register(string $hook, string $page): void
    {
        add_action('load-' . $hook, static function () use ($page): void {
            $screen = get_current_screen();

            if ($screen === null) {
                return;
            }

            foreach (self::tabs($page) as $tab) {
                $screen->add_help_tab($tab);
            }
        });
    }
}
