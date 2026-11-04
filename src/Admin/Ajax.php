<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin;

use Kontor\Shop\Core\Registerable;
use Kontor\Shop\Infrastructure\Repositories\CategoryRepository;
use Kontor\Shop\Infrastructure\Repositories\ProductRepository;
use Kontor\Shop\Infrastructure\Repositories\WarehouseRepository;

/**
 * Kleine AJAX-Endpunkte der Admin-Oberflaeche: Ein/Aus-Schalter in Listen
 * (Sichtbarkeit/Aktiv), Umsortieren per Drag & Drop und die Schnellbearbeitung
 * von Artikeln. Alle Endpunkte pruefen Nonce und Berechtigung; die Bestands-
 * Schnellbuchung liegt bei StockPage, weil sie dessen Rollenregeln braucht.
 */
final class Ajax implements Registerable
{
    public const NONCE = 'kontor_shop_admin';

    public function __construct(private readonly ProductsList $products_list, private readonly ProductRepository $products)
    {
    }

    public function register(): void
    {
        add_action('wp_ajax_kontor_shop_toggle_active', [$this, 'toggle_active']);
        add_action('wp_ajax_kontor_shop_reorder', [$this, 'reorder']);
        add_action('wp_ajax_kontor_shop_product_quick_save', [$this, 'product_quick_save']);
    }

    /**
     * Listen-Eintraege fuer Ein/Aus-Schalter und Umsortieren: Schluessel =
     * data-entity im HTML. repository braucht set_active(int, bool), bei
     * sortable zusaetzlich set_sort_order(int, int). Optional editable:
     * callable(int $id): bool - false sperrt den Eintrag (z. B. nur lesbare Lager).
     *
     * @return array<string, array{repository: object, capability: string, sortable: bool, editable?: callable}>
     */
    private function entities(): array
    {
        $warehouses = new WarehouseRepository();
        $entities   = [
            'product'   => ['repository' => $this->products, 'capability' => Menu::CAPABILITY, 'sortable' => false],
            'warehouse' => ['repository' => $warehouses, 'capability' => Menu::CAPABILITY, 'sortable' => false, 'editable' => static function (int $id) use ($warehouses): bool {
                $warehouse = $warehouses->find($id);

                return $warehouse !== null && $warehouses->is_editable($warehouse);
            }],
            'category'  => ['repository' => new CategoryRepository(), 'capability' => Menu::CAPABILITY, 'sortable' => true],
        ];

        /**
         * Filter kontor_shop/admin/entities: weitere Listen-Eintraege fuer den
         * Ein/Aus-Schalter (kontor_shop_toggle_active) und das Umsortieren per
         * Drag & Drop (kontor_shop_reorder), z. B. Rabattcodes und Shoptypen (Pro). Standard:
         * die Kern-Eintraege oben. Unvollstaendige Eintraege werden ignoriert.
         *
         * @param array<string, array{repository: object, capability: string, sortable: bool}> $entities
         *        repository: Objekt mit set_active(int $id, bool $active), bei sortable
         *        auch set_sort_order(int $id, int $order); capability: noetige Berechtigung;
         *        editable (optional): callable(int $id): bool, false = Eintrag gesperrt.
         */
        $entities = (array) apply_filters('kontor_shop/admin/entities', $entities);

        return array_filter($entities, static fn ($entry): bool => is_array($entry)
            && isset($entry['repository'], $entry['capability'])
            && is_object($entry['repository'])
            && is_string($entry['capability']));
    }

    /**
     * Nonce und Berechtigung pruefen; liefert den Listen-Eintrag zu
     * $_POST['entity'] (oder null, wenn es keinen gibt).
     *
     * @return array{repository: object, capability: string, sortable: bool}|null
     */
    private function guard(): ?array
    {
        check_ajax_referer(self::NONCE, 'nonce');

        $entity = $this->entities()[sanitize_key((string) ($_POST['entity'] ?? ''))] ?? null;

        if (!current_user_can($entity['capability'] ?? Menu::CAPABILITY)) {
            wp_send_json_error(['message' => __('No permission.', 'kontor-shop')], 403);
        }

        return $entity;
    }

    public function toggle_active(): void
    {
        $entity = $this->guard();
        $id     = absint($_POST['id'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in Ajax::guard()
        $active = !empty($_POST['active']) && $_POST['active'] !== 'false' && $_POST['active'] !== '0'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in Ajax::guard()

        if ($id <= 0) {
            wp_send_json_error(['message' => __('Entry not found.', 'kontor-shop')], 400);
        }

        if ($entity === null || !method_exists($entity['repository'], 'set_active')) {
            wp_send_json_error(['message' => __('Unknown entry.', 'kontor-shop')], 400);
        }

        if (isset($entity['editable']) && is_callable($entity['editable']) && !($entity['editable'])($id)) {
            wp_send_json_error(['message' => __('This entry can only be viewed.', 'kontor-shop')], 403);
        }

        $entity['repository']->set_active($id, $active);

        wp_send_json_success(['active' => $active]);
    }

    public function reorder(): void
    {
        $entity = $this->guard();
        $ids    = array_values(array_filter(array_map('absint', (array) ($_POST['ids'] ?? [])))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in Ajax::guard()

        if ($entity === null || empty($entity['sortable']) || !method_exists($entity['repository'], 'set_sort_order') || $ids === []) {
            wp_send_json_error(['message' => __('Sorting isn\'t possible.', 'kontor-shop')], 400);
        }

        foreach ($ids as $position => $id) {
            if (isset($entity['editable']) && is_callable($entity['editable']) && !($entity['editable'])($id)) {
                continue;
            }

            $entity['repository']->set_sort_order($id, ($position + 1) * 10);
        }

        wp_send_json_success();
    }

    public function product_quick_save(): void
    {
        $this->guard();

        $id      = absint($_POST['id'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in Ajax::guard()
        $product = $id > 0 ? $this->products->find($id) : null;

        if ($product === null) {
            wp_send_json_error(['message' => __('Product not found.', 'kontor-shop')], 404);
        }

        $name      = sanitize_text_field(wp_unslash((string) ($_POST['name'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in Ajax::guard()
        $price_raw = sanitize_text_field(wp_unslash((string) ($_POST['price'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in Ajax::guard()
        $price     = (float) str_replace(',', '.', $price_raw);

        if ($name === '') {
            wp_send_json_error(['message' => __('The name can\'t be empty.', 'kontor-shop')], 422);
        }

        if ($price < 0 || !is_numeric(str_replace(',', '.', $price_raw))) {
            wp_send_json_error(['message' => __('Please enter a valid price.', 'kontor-shop')], 422);
        }

        $min = trim(sanitize_text_field(wp_unslash((string) ($_POST['min_stock'] ?? '')))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in Ajax::guard()

        $this->products->quick_update($id, [
            'name'        => $name,
            'sku'         => sanitize_text_field(wp_unslash((string) ($_POST['sku'] ?? ''))), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in Ajax::guard()
            'price'       => $price,
            'category_id' => absint($_POST['category_id'] ?? 0), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in Ajax::guard()
            'min_stock'   => $min !== '' ? max(0, (int) $min) : null,
            'active'      => !empty($_POST['active']) && $_POST['active'] !== 'false' && $_POST['active'] !== '0', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in Ajax::guard()
        ]);

        wp_send_json_success(['row' => $this->products_list->row_html($id)]);
    }
}
