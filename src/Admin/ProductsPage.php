<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin;

use Kontor\Shop\Admin\Ui\Flash;
use Kontor\Shop\Admin\Ui\ListParams;
use Kontor\Shop\Domain\Catalog\UnitPrice;
use Kontor\Shop\Domain\Catalog\VariantMatrix;
use Kontor\Shop\Infrastructure\Audit\AuditLog;
use Kontor\Shop\Infrastructure\Repositories\VariantRepository;
use Kontor\Shop\Domain\Seo\Gtin;
use Kontor\Shop\Domain\Stock\MovementType;
use Kontor\Shop\Infrastructure\Media\ManagedImages;
use Kontor\Shop\Infrastructure\Repositories\ProductRepository;
use Kontor\Shop\Infrastructure\Repositories\StockRepository;
use Kontor\Shop\Infrastructure\Repositories\WarehouseRepository;
use Kontor\Shop\Settings\Repository;

/**
 * Artikelverwaltung (Abschnitt 10). Diese Klasse ist der Controller: sie
 * nimmt Aktionen entgegen (Speichern, Zeilenaktionen, Sammelaktionen, Bilder
 * aufraeumen) und leitet danach immer um (Post/Redirect/Get); dargestellt
 * wird die Liste ueber ProductsList und das Formular ueber ProductForm.
 * Das Artikelbild kommt aus der WordPress-Mediathek (optional, ohne Bild
 * greift im Frontend ein Platzhalter).
 */
final class ProductsPage
{
    public const SLUG         = 'kontor-shop-products';
    public const NONCE_ACTION = 'kontor_shop_product_save';
    public const BULK_NONCE   = 'kontor_shop_product_bulk';

    private ProductRepository $products;
    private StockRepository $stock;
    private Repository $settings;
    private ProductsList $list;

    public function __construct(Repository $settings)
    {
        $this->settings = $settings;
        $this->products = new ProductRepository();
        $this->stock    = new StockRepository();
        $this->list     = new ProductsList($this->products, $settings);
    }

    /**
     * @param array<string, mixed> $args
     */
    public static function url(array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('admin.php'));
    }

    /**
     * Link auf eine Zeilenaktion mit Nonce (archive, restore, delete, duplicate).
     */
    public static function action_url(string $do, int $id): string
    {
        return wp_nonce_url(self::url(['do' => $do, 'id' => $id]), 'kontor_shop_product_' . $do . '_' . $id);
    }

    private static function media_url(string $do, int $id = 0): string
    {
        return wp_nonce_url(
            self::url($id > 0 ? ['do' => $do, 'id' => $id] : ['do' => $do]),
            $id > 0 ? 'kontor_shop_' . $do . '_' . $id : 'kontor_shop_' . $do
        );
    }

    /** @var array<int, array{id: int, name: string}> Bilder, die in dieser Anfrage frei geworden sind */
    private array $image_offers = [];

    /** @var array{error: string, input: array<string, mixed>}|null Fehler des letzten Speicherversuchs (Formular wird damit neu gezeigt) */
    private ?array $failed = null;

    /**
     * Aktionen ausfuehren (Speichern, Zeilen-/Sammelaktionen, Bilder). Laeuft
     * auf load-{hook}, also vor jeder Ausgabe - nur so sind Umleitungen
     * (Post/Redirect/Get) moeglich.
     */
    public function handle_request(): void
    {
        if (!current_user_can(Menu::CAPABILITY)) {
            wp_die(esc_html__('No permission.', 'kontor-shop'));
        }

        $do = ListParams::key('do');

        $this->handle_media_action($do);
        $this->handle_row_action($do);
        $this->handle_bulk();

        $this->failed = $this->handle_submit();
    }

    public function render(): void
    {
        if (!current_user_can(Menu::CAPABILITY)) {
            wp_die(esc_html__('No permission.', 'kontor-shop'));
        }

        $do     = ListParams::key('do');
        $failed = $this->failed;

        echo '<div class="wrap ks-wrap">';

        Flash::render();
        $this->render_image_offer();

        if ($do === 'new' || $do === 'edit') {
            $product = $do === 'edit' ? $this->products->find(ListParams::int('id')) : null;

            if ($do === 'edit' && $product === null) {
                Flash::add(__('Product not found.', 'kontor-shop'), 'error');
                Flash::render();
                $this->list->render();
            } else {
                (new ProductForm($this->settings, $this->stock))->render($product, $failed['input'] ?? [], $failed['error'] ?? null);
            }
        } elseif (ProductsList::state()['view'] === 'images') {
            $this->render_unused_images();
        } else {
            $this->list->render();
        }

        echo '</div>';
    }

    /**
     * Ziel nach einer Zeilen-/Sammelaktion: die Seite, von der der Klick kam
     * (mit Filtern), sonst die Standardliste.
     */
    private function back_url(): string
    {
        $referer = wp_get_referer();

        if (is_string($referer) && str_contains($referer, 'page=' . self::SLUG) && !preg_match('/[?&]do=(edit|new)\b/', $referer)) {
            return remove_query_arg(['do', 'id', '_wpnonce'], $referer);
        }

        return ProductsList::list_url();
    }

    /**
     * Lager eines Artikels: ein bearbeitbares Lager oder das, in dem der
     * Artikel schon ist (auch wenn es nur lesbar ist).
     */
    private function warehouse_allowed(int $warehouse_id, int $product_id): bool
    {
        $repo      = new WarehouseRepository();
        $warehouse = $warehouse_id > 0 ? $repo->find($warehouse_id) : null;

        if ($warehouse === null) {
            return false;
        }

        return $repo->is_editable($warehouse) || ($product_id > 0 && $this->products->find($product_id)?->warehouse_id === $warehouse_id);
    }

    /**
     * @return array{error: string, input: array<string, mixed>}|null null = nichts zu speichern oder erfolgreich (dann Umleitung)
     */
    private function handle_submit(): ?array
    {
        if (sanitize_text_field(wp_unslash((string) ($_SERVER['REQUEST_METHOD'] ?? ''))) !== 'POST' || !isset($_POST['kontor_shop_product_nonce'])) {
            return null;
        }

        check_admin_referer(self::NONCE_ACTION, 'kontor_shop_product_nonce');

        if (!current_user_can(Menu::CAPABILITY)) {
            wp_die(esc_html__('No permission.', 'kontor-shop'));
        }

        $min_raw    = trim(sanitize_text_field(wp_unslash((string) ($_POST['min_stock'] ?? ''))));
        $price_raw  = sanitize_text_field(wp_unslash((string) ($_POST['price'] ?? '0')));
        $weight_raw = sanitize_text_field(wp_unslash((string) ($_POST['weight'] ?? '')));
        $amount_raw = sanitize_text_field(wp_unslash((string) ($_POST['unit_amount'] ?? '')));
        $data       = [
            'warehouse_id'          => absint($_POST['warehouse_id'] ?? 0),
            'category_id'           => absint($_POST['category_id'] ?? 0),
            'image_id'              => $this->sanitize_image_id(absint($_POST['image_id'] ?? 0)),
            'tax_rate'              => $this->sanitize_tax_rate(sanitize_text_field(wp_unslash((string) ($_POST['tax_rate'] ?? '')))),
            'image_alt'             => sanitize_text_field(wp_unslash($_POST['image_alt'] ?? '')),
            'sku'                   => sanitize_text_field(wp_unslash($_POST['sku'] ?? '')),
            'brand'                 => sanitize_text_field(wp_unslash($_POST['brand'] ?? '')),
            'gtin'                  => Gtin::normalize(sanitize_text_field(wp_unslash($_POST['gtin'] ?? ''))),
            'slug'                  => sanitize_text_field(wp_unslash($_POST['slug'] ?? '')),
            'category_ids'          => array_map('absint', (array) ($_POST['category_ids'] ?? [])),
            'name'                  => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
            'description'           => sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')),
            'price'                 => $this->parse_decimal($price_raw),
            'active'                => !empty($_POST['active']),
            'discontinued'          => !empty($_POST['discontinued']),
            'weight'                => $weight_raw !== '' ? $this->parse_decimal($weight_raw) : null,
            'min_stock'             => $min_raw !== '' ? max(0, (int) $min_raw) : null,
            'unit_amount'           => $amount_raw !== '' ? max(0.0, $this->parse_decimal($amount_raw)) : null,
            'unit_type'             => UnitPrice::is_unit(sanitize_key((string) ($_POST['unit_type'] ?? ''))) ? sanitize_key((string) $_POST['unit_type']) : '',
        ];

        $id = absint($_POST['product_id'] ?? 0);

        /**
         * Filter kontor_shop/admin/product/save_data: Felder des Artikels vor
         * dem Speichern ergaenzen, z. B. Pro: member_discount_type/-value aus
         * kontor_shop/admin/product_form/price_fields (Werte aus $_POST selbst
         * bereinigen; Nonce und Recht sind geprueft). Fehlt ein Feld, bleibt
         * der gespeicherte Wert (ProductRepository). Standard: die
         * Kern-Felder.
         *
         * @param array<string, mixed> $data Felder des Artikels
         * @param int                  $id   Artikel-ID, 0 = neu
         */
        $data = (array) apply_filters('kontor_shop/admin/product/save_data', $data, $id);

        $has_variants            = !empty($_POST['has_variants']);
        $raw_options             = $this->sanitize_options(map_deep(wp_unslash($_POST['variant_options'] ?? []), 'sanitize_text_field'));
        $data['variant_options'] = $has_variants ? VariantMatrix::normalize($raw_options) : [];
        $variant_rows            = $this->sanitize_variant_rows(map_deep(wp_unslash($_POST['variants'] ?? []), 'sanitize_text_field'));
        $gallery_ids             = isset($_POST['gallery_present'])
            ? array_values(array_filter(array_map([$this, 'sanitize_image_id'], array_map('absint', (array) ($_POST['gallery_ids'] ?? [])))))
            : null;

        $initial = max(0, intval($_POST['initial_stock'] ?? 0));
        $problem = null;
        $bad_gtin = array_filter($variant_rows, static fn (array $row): bool => $row['gtin'] !== '' && !Gtin::valid($row['gtin']));

        if ($data['name'] === '') {
            $problem = __('Please enter a name.', 'kontor-shop');
        } elseif (!$this->warehouse_allowed((int) $data['warehouse_id'], $id)) {
            $problem = __('Please select a warehouse.', 'kontor-shop');
        } elseif ($data['price'] < 0) {
            $problem = __('The price can’t be negative.', 'kontor-shop');
        } elseif ($data['gtin'] !== '' && !Gtin::valid($data['gtin'])) {
            $problem = __('The GTIN/EAN is invalid (8, 12, 13, or 14 digits with a correct check digit) – please check it or leave it empty.', 'kontor-shop');
        } elseif ($has_variants && $data['variant_options'] === []) {
            $problem = __('For variants, please enter at least one attribute with values (e.g. Size: S, M, L) – or uncheck “This product has variants”.', 'kontor-shop');
        } elseif (VariantMatrix::count($data['variant_options']) > VariantMatrix::MAX_COMBINATIONS) {
            $problem = sprintf(
                /* translators: 1: number of combinations, 2: maximum */
                __('The attributes result in %1$d combinations – the maximum is %2$d. Please reduce the values.', 'kontor-shop'),
                VariantMatrix::count($data['variant_options']),
                VariantMatrix::MAX_COMBINATIONS
            );
        } elseif ($bad_gtin !== []) {
            $problem = sprintf(
                /* translators: %s: variant */
                __('The EAN of the variant “%s” is invalid – please check it or leave it empty.', 'kontor-shop'),
                VariantMatrix::label(reset($bad_gtin)['values'])
            );
        }

        if ($problem === null) {
            /**
             * Filter kontor_shop/admin/product/validate: Artikel vor dem
             * Speichern pruefen, z. B. Pro: Mitgliedsrabatt in % zwischen 0
             * und 100. Fehlermeldung (reiner Text) oder null; laeuft nur, wenn
             * die Kern-Pruefungen bestanden sind. Standard: null.
             *
             * @param string|null          $error
             * @param array<string, mixed> $data Felder nach kontor_shop/admin/product/save_data
             */
            $error   = apply_filters('kontor_shop/admin/product/validate', null, $data);
            $problem = is_string($error) && $error !== '' ? $error : null;
        }

        if ($problem !== null) {
            return [
                'error' => $problem,
                'input' => array_merge($data, [
                    'tax_rate'        => (string) ($data['tax_rate'] ?? ''),
                    'price'           => isset($_POST['price']) ? $price_raw : '',
                    'weight'          => $weight_raw,
                    'unit_amount'     => $amount_raw,
                    'min_stock'       => $min_raw,
                    'initial_stock'   => (string) $initial,
                    'has_variants'    => $has_variants,
                    'variant_options' => $raw_options,
                    'variants'        => $variant_rows,
                ], $gallery_ids !== null ? ['gallery_ids' => $gallery_ids] : []),
            ];
        }

        $before = $id > 0 ? $this->image_ids_of($id) : [];
        $is_new = $id <= 0;

        if ($id > 0) {
            $this->products->update($id, $data);
        } else {
            $id = $this->products->create($data);

            if ($id <= 0) {
                return ['error' => __('The product couldn’t be saved. Please try again.', 'kontor-shop'), 'input' => $data];
            }

            if ($initial > 0 && $data['variant_options'] === []) {
                $this->stock->record($id, MovementType::PURCHASE, $initial, null, __('Initial stock', 'kontor-shop'), get_current_user_id() ?: null);
            }
        }

        $this->save_gallery_and_variants($id, $data, $gallery_ids, $variant_rows);
        $this->settle_images($id, $before);

        /**
         * Aktion kontor_shop/admin/product/saved: Artikel im Backend
         * gespeichert (vor der Weiterleitung) - z. B. fuer Hinweise per
         * Admin\Ui\Flash::add() wie "Kunden warten auf diesen Auslaufartikel".
         *
         * @param int                  $id     Artikel-ID
         * @param array<string, mixed> $data   gespeicherte Felder (z. B. discontinued)
         * @param bool                 $is_new neu angelegt
         */
        do_action('kontor_shop/admin/product/saved', $id, $data, $is_new);

        if (absint($_POST['product_id'] ?? 0) > 0) {
            /* translators: %s: product name */
            Flash::redirect(ProductsList::list_url(), sprintf(__('Product “%s” saved.', 'kontor-shop'), $data['name']));
        }

        /* translators: %s: product name */
        $message = sprintf(__('Product “%s” created.', 'kontor-shop'), $data['name']);

        Flash::redirect(isset($_POST['save_and_new']) ? self::url(['do' => 'new']) : ProductsList::list_url(), $message);
    }

    /**
     * Galerie und Varianten schreiben, Anfangsbestand neuer Varianten buchen,
     * Aenderungen protokollieren und - wenn sie im Shop sichtbar sind - die
     * Artikelseite als geaendert melden (lastmod, Cache).
     *
     * @param array<string, mixed> $data
     * @param array<int, int>|null $gallery_ids null = Feld nicht abgeschickt (unveraendert lassen)
     * @param array<int, array<string, mixed>> $variant_rows
     */
    private function save_gallery_and_variants(int $id, array $data, ?array $gallery_ids, array $variant_rows): void
    {
        $changes = [];

        if ($gallery_ids !== null) {
            $gallery = $this->products->set_gallery($id, $gallery_ids);

            if ($gallery['before'] !== $gallery['after']) {
                $changes['gallery'] = [implode(',', $gallery['before']), implode(',', $gallery['after'])];
            }
        }

        $sync = (new VariantRepository())->sync($id, $data['variant_options'], $variant_rows);

        if ($sync['before'] !== $sync['after']) {
            $changes['variants'] = [$sync['before'], $sync['after']];
        }

        foreach ($sync['created'] as $created) {
            if ($created['initial_stock'] > 0) {
                $this->stock->record($id, MovementType::PURCHASE, $created['initial_stock'], null, __('Initial stock', 'kontor-shop'), get_current_user_id() ?: null, $created['id']);
            }
        }

        if ($changes === []) {
            return;
        }

        AuditLog::record('product.update', 'product', $id, (string) $data['name'], $changes);

        if ($this->products->find($id)?->is_sellable()) {
            $this->products->touch_content($id);
        }
    }

    /**
     * Alle Bilder eines Artikels: Hauptbild, Galerie und Variantenbilder.
     *
     * @return array<int, int>
     */
    private function image_ids_of(int $product_id): array
    {
        $ids = array_merge(
            [(int) ($this->products->find($product_id)?->image_id ?? 0)],
            $this->products->gallery_ids($product_id),
            array_map(static fn ($variant): int => (int) $variant->image_id, (new VariantRepository())->for_product($product_id))
        );

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * Nach dem Speichern: jetzt genutzte Bilder gelten als zugeordnet; Bilder,
     * die der Artikel nicht mehr verwendet, werden zur Entscheidung angeboten.
     *
     * @param array<int, int> $before
     */
    private function settle_images(int $product_id, array $before): void
    {
        $after = $this->image_ids_of($product_id);

        foreach ($after as $attachment_id) {
            ManagedImages::claim($attachment_id);
        }

        foreach (array_diff($before, $after) as $attachment_id) {
            $this->offer_image_decision((int) $attachment_id, $product_id);
        }
    }

    /**
     * @return array<int, array{name: string, values: string}>
     */
    private function sanitize_options(mixed $raw): array
    {
        $options = [];

        foreach (is_array($raw) ? array_slice($raw, 0, VariantMatrix::MAX_OPTIONS) : [] as $option) {
            if (!is_array($option)) {
                continue;
            }

            $options[] = [
                'name'   => sanitize_text_field((string) ($option['name'] ?? '')),
                'values' => sanitize_text_field((string) ($option['values'] ?? '')),
            ];
        }

        return $options;
    }

    /**
     * Zeilen der Variantentabelle (Werte, Artikelnummer, EAN, Preis, Inhalt, Bild, Anfangsbestand, aktiv).
     *
     * @return array<int, array{id: int, values: array<int, string>, sku: string, gtin: string, price: float|null, unit_amount: float|null, image_id: int, active: bool, initial_stock: int}>
     */
    private function sanitize_variant_rows(mixed $raw): array
    {
        $rows = [];

        foreach (is_array($raw) ? array_slice($raw, 0, VariantMatrix::MAX_COMBINATIONS) : [] as $row) {
            if (!is_array($row) || !is_array($row['values'] ?? null)) {
                continue;
            }

            $price  = trim((string) ($row['price'] ?? ''));
            $amount = trim((string) ($row['unit_amount'] ?? ''));

            $rows[] = [
                'id'            => absint($row['id'] ?? 0),
                'values'        => array_map(static fn ($value): string => sanitize_text_field((string) $value), array_slice($row['values'], 0, VariantMatrix::MAX_OPTIONS)),
                'sku'           => sanitize_text_field((string) ($row['sku'] ?? '')),
                'gtin'          => Gtin::normalize(sanitize_text_field((string) ($row['gtin'] ?? ''))),
                'price'         => $price !== '' ? max(0.0, $this->parse_decimal($price)) : null,
                'unit_amount'   => $amount !== '' && $this->parse_decimal($amount) > 0 ? $this->parse_decimal($amount) : null,
                'image_id'      => $this->sanitize_image_id($row['image_id'] ?? 0),
                'active'        => !empty($row['active']),
                'initial_stock' => max(0, (int) ($row['initial_stock'] ?? 0)),
            ];
        }

        return $rows;
    }

    /**
     * Zeilenaktionen per GET mit Nonce: archivieren, wiederherstellen,
     * loeschen (nur aus dem Archiv), duplizieren.
     */
    private function handle_row_action(string $do): void
    {
        if (!in_array($do, ['archive', 'restore', 'delete', 'duplicate'], true)) {
            return;
        }

        $id = absint($_GET['id'] ?? 0);

        check_admin_referer('kontor_shop_product_' . $do . '_' . $id);

        if ($id <= 0 || $this->products->find($id) === null) {
            Flash::redirect($this->back_url(), __('Product not found.', 'kontor-shop'), 'error');
        }

        $product = $this->products->find($id);
        $name    = $product?->name ?? '';

        switch ($do) {
            case 'archive':
                $this->products->set_archived($id, true);

                Flash::redirect(
                    $this->back_url(),
                    /* translators: %s: product name */
                    sprintf(__('“%s” archived – no longer visible in the shop but still saved (“Archive” view).', 'kontor-shop'), $name)
                );

                // no break
            case 'restore':
                $this->products->set_archived($id, false);

                /* translators: %s: product name */
                Flash::redirect($this->back_url(), sprintf(__('“%s” restored.', 'kontor-shop'), $name));

                // no break
            case 'duplicate':
                $copy = $this->products->duplicate($id);

                if ($copy === null) {
                    Flash::redirect($this->back_url(), __('Duplicating failed.', 'kontor-shop'), 'error');
                }

                Flash::redirect(
                    self::url(['do' => 'edit', 'id' => $copy]),
                    __('Copy created – it’s still hidden and has stock 0. Please review and save.', 'kontor-shop')
                );

                // no break
            default:
                $old_images = $this->image_ids_of($id);

                $this->products->delete($id);

                foreach ($old_images as $old_image) {
                    $this->offer_image_decision($old_image, null);
                }

                /* translators: %s: product name */
                Flash::redirect($this->back_url(), sprintf(__('“%s” permanently deleted.', 'kontor-shop'), $name));
        }
    }

    /**
     * Sammelaktionen (POST von der Liste).
     */
    private function handle_bulk(): void
    {
        if (sanitize_text_field(wp_unslash((string) ($_SERVER['REQUEST_METHOD'] ?? ''))) !== 'POST' || !isset($_POST['kontor_shop_bulk_nonce'])) {
            return;
        }

        check_admin_referer(self::BULK_NONCE, 'kontor_shop_bulk_nonce');

        if (!current_user_can(Menu::CAPABILITY)) {
            wp_die(esc_html__('No permission.', 'kontor-shop'));
        }

        $action = sanitize_key((string) ($_POST['bulk_action'] ?? ''));
        $action = $action !== '' ? $action : sanitize_key((string) ($_POST['bulk_action2'] ?? ''));
        $ids    = array_values(array_filter(array_map('absint', (array) ($_POST['ids'] ?? []))));
        $back   = ProductsList::list_url();

        if ($action === '') {
            Flash::redirect($back, __('Please select a bulk action.', 'kontor-shop'), 'warning');
        }

        if ($ids === []) {
            Flash::redirect($back, __('Please check some products in the list first.', 'kontor-shop'), 'warning');
        }

        $count = count($ids);

        switch ($action) {
            case 'show':
            case 'hide':
                $this->products->update_many($ids, ['active' => $action === 'show' ? 1 : 0]);
                /* translators: %d: number of products */
                $message = sprintf(_n('%d product changed.', '%d products changed.', $count, 'kontor-shop'), $count)
                    . ' ' . ($action === 'show' ? __('Visible in shop.', 'kontor-shop') : __('Hidden in shop.', 'kontor-shop'));

                break;

            case 'archive':
                $this->products->update_many($ids, ['archived' => 1]);
                /* translators: %d: number of products */
                $message = sprintf(_n('%d product archived.', '%d products archived.', $count, 'kontor-shop'), $count);

                break;

            case 'restore':
                $this->products->update_many($ids, ['archived' => 0]);
                /* translators: %d: number of products */
                $message = sprintf(_n('%d product restored.', '%d products restored.', $count, 'kontor-shop'), $count);

                break;

            case 'duplicate':
                foreach ($ids as $id) {
                    $this->products->duplicate($id);
                }

                /* translators: %d: number of products */
                $message = sprintf(_n('%d product duplicated (copies are hidden, stock 0).', '%d products duplicated (copies are hidden, stock 0).', $count, 'kontor-shop'), $count);

                break;

            case 'category':
                $category = absint($_POST['bulk_category'] ?? 0);
                $this->products->update_many($ids, ['category_id' => $category > 0 ? $category : null]);
                /* translators: %d: number of products */
                $message = sprintf(_n('Category set for %d product.', 'Category set for %d products.', $count, 'kontor-shop'), $count);

                break;

            case 'price':
                $percent = $this->parse_decimal(sanitize_text_field(wp_unslash((string) ($_POST['bulk_percent'] ?? '0'))));

                if ($percent === 0.0 || $percent < -99 || $percent > 500) {
                    Flash::redirect($back, __('Please enter a percentage other than 0 (e.g. 10 or -5).', 'kontor-shop'), 'warning');
                }

                $this->products->adjust_prices($ids, $percent);
                $message = sprintf(
                    /* translators: 1: number, 2: percent */
                    __('Price of %1$d products changed by %2$s %%.', 'kontor-shop'),
                    $count,
                    number_format_i18n($percent, 1)
                );

                break;

            case 'delete':
                foreach ($ids as $id) {
                    $product = $this->products->find($id);

                    if ($product === null || !$product->archived) {
                        continue; // nur archivierte Artikel duerfen endgueltig geloescht werden
                    }

                    $old_images = $this->image_ids_of($id);

                    $this->products->delete($id);

                    foreach ($old_images as $old_image) {
                        $this->offer_image_decision($old_image, null);
                    }
                }

                /* translators: %d: number of products */
                $message = sprintf(_n('%d product permanently deleted.', '%d products permanently deleted.', $count, 'kontor-shop'), $count);

                break;

            default:
                Flash::redirect($back, __('Unknown bulk action.', 'kontor-shop'), 'error');
        }

        Flash::redirect($back, $message);
    }

    /**
     * Ein Bild wurde von einem Artikel geloest: gehoert es zum Shop-Upload und
     * wird sonst nirgends verwendet, fragt die naechste Seite, ob es aus der
     * Mediathek geloescht oder behalten werden soll.
     */
    private function offer_image_decision(int $attachment_id, ?int $except_product_id): void
    {
        if (ManagedImages::release($attachment_id, $this->settings, $except_product_id)) {
            $this->image_offers[] = [
                'id'   => $attachment_id,
                'name' => (string) (get_the_title($attachment_id) ?: basename((string) get_attached_file($attachment_id))),
            ];

            Flash::set('image_offer', $this->image_offers);
        }
    }

    /**
     * Loeschen/Behalten fuer ungenutzte Upload-Bilder (GET mit Nonce).
     */
    private function handle_media_action(string $do): void
    {
        if (!in_array($do, ['media_delete_all', 'media_delete', 'media_keep'], true)) {
            return;
        }

        $id = absint($_GET['id'] ?? 0);

        check_admin_referer($do === 'media_delete_all' ? 'kontor_shop_media_delete_all' : 'kontor_shop_' . $do . '_' . $id);

        if (!current_user_can(Menu::CAPABILITY)) {
            wp_die(esc_html__('No permission.', 'kontor-shop'));
        }

        $target = self::url(['view' => 'images']);

        if ($do === 'media_delete_all') {
            $deleted = 0;

            foreach (ManagedImages::pending_unused($this->settings) as $unused_id) {
                $deleted += ManagedImages::delete($unused_id, $this->settings) ? 1 : 0;
            }

            /* translators: %d: number of images */
            Flash::redirect($target, sprintf(_n('%d unused image was deleted from the media library.', '%d unused images were deleted from the media library.', $deleted, 'kontor-shop'), $deleted));
        }

        if ($id <= 0) {
            Flash::redirect($target, __('Image not found.', 'kontor-shop'), 'error');
        }

        if ($do === 'media_keep') {
            ManagedImages::keep($id);

            Flash::redirect($target, __('The image stays in the media library.', 'kontor-shop'));
        }

        ManagedImages::delete($id, $this->settings)
            ? Flash::redirect($target, __('The image was deleted from the media library.', 'kontor-shop'))
            : Flash::redirect($target, __('The image is still in use and was therefore not deleted.', 'kontor-shop'), 'warning');
    }

    /**
     * Frage nach dem Speichern/Loeschen: Bild loeschen oder behalten?
     */
    private function render_image_offer(): void
    {
        $offers = Flash::take('image_offer');

        if (!is_array($offers)) {
            return;
        }

        // Aeltere Form: ein einzelnes Angebot statt einer Liste.
        $offers = isset($offers['id']) ? [$offers] : $offers;

        foreach (array_slice($offers, 0, 5) as $offer) {
            $id = (int) ($offer['id'] ?? 0);

            if ($id <= 0) {
                continue;
            }

            echo '<div class="notice notice-warning"><p><strong>' . esc_html__('Current image', 'kontor-shop') . '</strong> ';
            echo esc_html(sprintf(
                /* translators: %s: image name */
                __('“%s” is no longer assigned to any product and isn’t used anywhere else. Delete from the media library?', 'kontor-shop'),
                (string) ($offer['name'] ?? '')
            )) . '</p><p>';
            echo wp_get_attachment_image($id, [60, 60], false, ['class' => 'ks-thumb']);
            echo ' <a class="button button-primary" href="' . esc_url(self::media_url('media_delete', $id)) . '">' . esc_html__('Delete from media library', 'kontor-shop') . '</a> ';
            echo '<a class="button" href="' . esc_url(self::media_url('media_keep', $id)) . '">' . esc_html__('Keep', 'kontor-shop') . '</a></p></div>';
        }

        if (count($offers) > 5) {
            echo '<div class="notice notice-info"><p>' . wp_kses_post(sprintf(
                /* translators: %s: link to the cleanup list */
                __('You can find more images that are no longer in use under %s.', 'kontor-shop'),
                '<a href="' . esc_url(self::url(['view' => 'images'])) . '">' . esc_html__('Unused images', 'kontor-shop') . '</a>'
            )) . '</p></div>';
        }
    }

    /**
     * Aufraeumansicht: Shop-Uploads ohne Verwendung.
     */
    private function render_unused_images(): void
    {
        $ids = ManagedImages::pending_unused($this->settings);

        echo '<h1 class="wp-heading-inline">' . esc_html__('Unused images', 'kontor-shop') . '</h1> ';
        echo '<a href="' . esc_url(self::url()) . '" class="page-title-action">' . esc_html__('Back to products', 'kontor-shop') . '</a><hr class="wp-header-end" />';
        echo '<p class="description">' . esc_html__(
            'These images were uploaded through the shop but no longer belong to any product and aren’t used anywhere. You decide whether they’re deleted from the media library. Images you picked from the media library never appear here.',
            'kontor-shop'
        ) . '</p>';

        if ($ids === []) {
            echo '<div class="ks-empty"><p><strong>' . esc_html__('All tidy – no unused images.', 'kontor-shop') . '</strong></p></div>';

            return;
        }

        echo '<table class="wp-list-table widefat striped ks-table ks-w-860"><tbody>';

        foreach ($ids as $id) {
            $name = (string) (get_the_title($id) ?: basename((string) get_attached_file($id)));

            echo '<tr><td class="ks-col-image">' . wp_get_attachment_image($id, [60, 60], false, ['class' => 'ks-thumb']) . '</td>';
            echo '<td>' . esc_html($name) . '<br /><small class="ks-muted">' . esc_html(get_the_date('d.m.Y H:i', $id)) . '</small></td><td class="ks-right">';
            echo '<a class="button" href="' . esc_url(self::media_url('media_delete', $id)) . '" data-ks-confirm="' . esc_attr__('Permanently delete image from the media library?', 'kontor-shop') . '">' . esc_html__('Delete', 'kontor-shop') . '</a> ';
            echo '<a class="button-link" href="' . esc_url(self::media_url('media_keep', $id)) . '">' . esc_html__('Keep', 'kontor-shop') . '</a></td></tr>';
        }

        echo '</tbody></table>';

        if (count($ids) > 1) {
            echo '<p><a class="button" href="' . esc_url(self::media_url('media_delete_all')) . '" data-ks-confirm="' . esc_attr__('Permanently delete all unused images from the media library?', 'kontor-shop') . '">'
                . esc_html__('Delete all unused images', 'kontor-shop') . '</a></p>';
        }
    }

    /**
     * Steuersatz nur uebernehmen, wenn er (noch) konfiguriert ist oder dem
     * bereits gespeicherten Wert des Artikels entspricht; sonst steuerfrei.
     */
    private function sanitize_tax_rate(mixed $value): ?float
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $rate = (float) str_replace(',', '.', $value);
        $id   = absint($_POST['product_id'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nur aus handle_submit() nach check_admin_referer() aufgerufen
        $kept = $id > 0 ? $this->products->find($id)?->tax_rate : null;

        $allowed = array_map('floatval', (array) $this->settings->get('tax.rates', []));

        return in_array($rate, $allowed, true) || ($kept !== null && abs($kept - $rate) < 0.001) ? $rate : null;
    }

    private function sanitize_image_id(mixed $value): int
    {
        $id = absint($value);

        return $id > 0 && wp_attachment_is_image($id) ? $id : 0;
    }

    private function parse_decimal(mixed $value): float
    {
        return (float) str_replace(',', '.', (string) $value);
    }
}
