<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin;

use Kontor\Shop\Admin\Ui\Badge;
use Kontor\Shop\Admin\Ui\EditHeader;
use Kontor\Shop\Admin\Ui\Flash;
use Kontor\Shop\Admin\Ui\PageHeader;
use Kontor\Shop\Admin\Ui\Form;
use Kontor\Shop\Admin\Ui\ListParams;
use Kontor\Shop\Domain\Catalog\Category;
use Kontor\Shop\Domain\Seo\CategoryTree;
use Kontor\Shop\Infrastructure\Repositories\CategoryRepository;
use Kontor\Shop\Infrastructure\Repositories\ProductRepository;
use Kontor\Shop\Infrastructure\Repositories\ShopTypeRepository;
use Kontor\Shop\Support\Color;

/**
 * Kategorienverwaltung (frei vom Shop-Admin angelegt, keine feste Liste im
 * Code). Die Kategorien dienen im Frontend als Filter. Reihenfolge per Drag &
 * Drop; eine Kategorie mit Artikeln kann nicht geloescht werden (stattdessen
 * inaktiv schalten).
 *
 * Jede Kategorie gehoert zu einem Shoptyp (categories.shop_type_id). Ohne Pro
 * gibt es nur den Standard-Shoptyp: neue Kategorien bekommen ihn (oder den
 * der Eltern-Kategorie), beim Bearbeiten bleibt der gespeicherte Wert. Die
 * Auswahl "Shoptyp" samt Pruefung und Spalte kommt mit Pro ueber
 * kontor_shop/admin/category_form/fields, kontor_shop/admin/category/save_data,
 * kontor_shop/admin/category/validate und kontor_shop/admin/categories_list/columns.
 */
final class CategoriesPage
{
    public const SLUG          = 'kontor-shop-categories';
    private const NONCE_ACTION = 'kontor_shop_category_save';

    private CategoryRepository $categories;
    private ShopTypeRepository $shop_types;
    private ProductRepository $products;

    public function __construct()
    {
        $this->categories = new CategoryRepository();
        $this->shop_types = new ShopTypeRepository();
        $this->products   = new ProductRepository();
    }

    /**
     * @param array<string, mixed> $args
     */
    public static function url(array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('admin.php'));
    }

    /** @var array{error: string, input: array<string, mixed>}|null */
    private ?array $failed = null;

    /**
     * Aktionen ausfuehren; laeuft auf load-{hook} (vor jeder Ausgabe), damit
     * nach dem Speichern/Loeschen umgeleitet werden kann.
     */
    public function handle_request(): void
    {
        if (!current_user_can(Menu::CAPABILITY)) {
            wp_die(esc_html__('No permission.', 'kontor-shop'));
        }

        if (ListParams::key('do') === 'delete') {
            $this->handle_delete(ListParams::int('id'));
        }

        $this->failed = $this->handle_submit();
    }

    public function render(): void
    {
        if (!current_user_can(Menu::CAPABILITY)) {
            wp_die(esc_html__('No permission.', 'kontor-shop'));
        }

        $do    = ListParams::key('do');
        $error = $this->failed;

        echo '<div class="wrap ks-wrap">';
        Flash::render();

        if ($error !== null) {
            echo '<div class="notice notice-error"><p>' . esc_html($error['error']) . '</p></div>';
        }

        if ($do === 'new' || $do === 'edit') {
            $this->render_form($do === 'edit' ? $this->categories->find(ListParams::int('id')) : null, $error['input'] ?? []);
        } else {
            $this->render_list();
        }

        echo '</div>';
    }

    /**
     * @return array{error: string, input: array<string, mixed>}|null
     */
    private function handle_submit(): ?array
    {
        if (!isset($_SERVER['REQUEST_METHOD']) || sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) !== 'POST' || !isset($_POST['kontor_shop_category_nonce'])) {
            return null;
        }

        check_admin_referer(self::NONCE_ACTION, 'kontor_shop_category_nonce');

        if (!current_user_can(Menu::CAPABILITY)) {
            wp_die(esc_html__('No permission.', 'kontor-shop'));
        }

        $name         = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $accent_color = !empty($_POST['accent_default']) ? '' : Color::sanitize_hex(sanitize_text_field(wp_unslash($_POST['accent_color'] ?? '')));
        $parent_id    = absint($_POST['parent_id'] ?? 0);
        $input        = ['name' => $name, 'accent_color' => $accent_color, 'parent_id' => $parent_id, 'active' => !empty($_POST['active'])];
        $id           = absint($_POST['category_id'] ?? 0);
        $existing     = $id > 0 ? $this->categories->find($id) : null;
        $parent       = $parent_id > 0 ? $this->categories->find($parent_id) : null;

        // Shoptyp: der der Eltern-Kategorie, sonst der gespeicherte, bei neuen der Standard-Shoptyp.
        $shop_type_id = $parent?->shop_type_id ?? $existing?->shop_type_id ?? ($existing === null ? $this->shop_types->default_id() : null);

        $data = [
            'shop_type_id' => $shop_type_id,
            'parent_id'    => $parent_id > 0 ? $parent_id : null,
            'name'         => $name,
            'slug'         => $existing !== null ? $existing->slug : '',
            'accent_color' => $accent_color,
            'sort_order'   => $existing !== null ? $existing->sort_order : 0,
            'active'       => !empty($_POST['active']),
        ];

        /**
         * Filter kontor_shop/admin/category/save_data: Felder der Kategorie vor
         * dem Speichern ergaenzen, z. B. Pro: shop_type_id aus der Auswahl
         * "Shoptyp" (Werte aus $_POST selbst bereinigen; Nonce und Recht sind
         * geprueft). Standard: wie oben - Shoptyp der Eltern-Kategorie, sonst
         * der gespeicherte, bei neuen der Standard-Shoptyp.
         *
         * @param array<string, mixed> $data Spalten der Kategorie
         * @param int                  $id   Kategorie-ID, 0 = neu
         */
        $data  = (array) apply_filters('kontor_shop/admin/category/save_data', $data, $id);
        $input = array_merge($input, ['shop_type_id' => $data['shop_type_id'] ?? null]);

        if ($name === '') {
            return ['error' => __('Please enter a name.', 'kontor-shop'), 'input' => $input];
        }

        if ($parent_id > 0) {
            if ($parent === null) {
                return ['error' => __('Category not found.', 'kontor-shop'), 'input' => $input];
            }

            if ($id > 0 && CategoryTree::would_cycle($id, $parent_id, $this->categories->parent_map())) {
                return ['error' => __('A category can\'t be placed under itself or one of its subcategories.', 'kontor-shop'), 'input' => $input];
            }
        }

        /**
         * Filter kontor_shop/admin/category/validate: Kategorie vor dem
         * Speichern pruefen, z. B. Pro: Shoptyp gewaehlt und die
         * Eltern-Kategorie im selben Shoptyp. Fehlermeldung (reiner Text) oder
         * null. Standard: null.
         *
         * @param string|null          $error
         * @param array<string, mixed> $data   Spalten nach kontor_shop/admin/category/save_data
         * @param Category|null        $parent Eltern-Kategorie oder null
         */
        $error = apply_filters('kontor_shop/admin/category/validate', null, $data, $parent);

        if (is_string($error) && $error !== '') {
            return ['error' => $error, 'input' => $input];
        }

        if ($existing === null) {
            $data['slug']       = $this->unique_slug(sanitize_title($name));
            $data['sort_order'] = $this->next_sort_order();
        }

        if ($existing !== null) {
            $this->categories->update((int) $existing->id, $data);

            Flash::redirect(self::url(), sprintf(/* translators: %s: category name */ __('Category “%s” saved.', 'kontor-shop'), $name));
        }

        $this->categories->create($data);

        Flash::redirect(self::url(), sprintf(/* translators: %s: category name */ __('Category “%s” created.', 'kontor-shop'), $name));
    }

    private function next_sort_order(): int
    {
        $max = 0;

        foreach ($this->categories->all() as $category) {
            $max = max($max, $category->sort_order);
        }

        return $max + 10;
    }

    private function unique_slug(string $base): string
    {
        $base = $base !== '' ? $base : 'kategorie';
        $slug = $base;
        $i    = 2;

        while ($this->categories->find_by_slug($slug) !== null) {
            $slug = $base . '-' . $i;
            $i++;
        }

        return $slug;
    }

    private function handle_delete(int $id): void
    {
        check_admin_referer('kontor_shop_category_delete_' . $id);

        $category = $id > 0 ? $this->categories->find($id) : null;

        if ($category === null) {
            Flash::redirect(self::url(), __('Category not found.', 'kontor-shop'), 'error');
        }

        $used = $this->products->count_using('category_id', $id);

        if ($used > 0) {
            Flash::redirect(self::url(), sprintf(
                /* translators: 1: category name, 2: number of products */
                _n(
                    '“%1$s” can\'t be deleted: %2$d product (including archive) is still assigned to it. First assign it to another category (product list → bulk action “Assign category”) or set the category to inactive.',
                    '“%1$s” can\'t be deleted: %2$d products (including archive) are still assigned to it. First assign them to another category (product list → bulk action “Assign category”) or set the category to inactive.',
                    $used,
                    'kontor-shop'
                ),
                $category->name,
                $used
            ), 'error');
        }

        $this->categories->delete($id);

        Flash::redirect(self::url(), sprintf(/* translators: %s: category name */ __('Category “%s” deleted.', 'kontor-shop'), $category->name));
    }

    private function render_list(): void
    {
        $categories = $this->categories->all();
        $counts     = $this->products->count_by('category_id');

        /**
         * Filter kontor_shop/admin/categories_list/columns: weitere Spalten der
         * Kategorienliste nach "Name", z. B. Pro: "Shoptyp". Je Spalte
         * ['label' => Kopf, 'cell' => callable(Category): string (fertiges,
         * escaptes HTML)]. Standard: [].
         *
         * @param array<string, array{label: string, cell: callable}> $columns
         */
        $columns = array_filter(
            (array) apply_filters('kontor_shop/admin/categories_list/columns', []),
            static fn ($column): bool => is_array($column) && isset($column['label'], $column['cell']) && is_callable($column['cell'])
        );

        PageHeader::render(
            __('Categories', 'kontor-shop'),
            [['label' => __('Add new category', 'kontor-shop'), 'url' => self::url(['do' => 'new']), 'primary' => true]],
            __('Categories organize products and serve as filters in the shop. You set the order by dragging or with the arrows – it also applies in the shop.', 'kontor-shop')
        );

        if ($categories === []) {
            echo '<div class="ks-empty"><p><strong>' . esc_html__('No categories yet.', 'kontor-shop') . '</strong></p><p><a class="button button-primary" href="'
                . esc_url(self::url(['do' => 'new'])) . '">' . esc_html__('Add your first category', 'kontor-shop') . '</a></p></div>';

            return;
        }

        echo '<div class="ks-table-scroll"><table class="wp-list-table widefat fixed striped ks-table" data-ks-sortable="category"><thead><tr>';
        echo '<th class="ks-col-handle"><span class="screen-reader-text">' . esc_html__('Sort order', 'kontor-shop') . '</span></th>';
        echo '<th class="column-primary">' . esc_html__('Name', 'kontor-shop') . '</th>';

        foreach ($columns as $column) {
            echo '<th>' . esc_html((string) $column['label']) . '</th>';
        }

        echo '<th>' . esc_html__('Accent color', 'kontor-shop') . '</th><th class="ks-col-num">' . esc_html__('Product', 'kontor-shop') . '</th>';
        echo '<th class="ks-col-toggle">' . esc_html__('Active', 'kontor-shop') . '</th></tr></thead><tbody>';

        foreach ($categories as $category) {
            $id     = (int) $category->id;
            $count  = $counts[$id] ?? 0;
            $delete = wp_nonce_url(self::url(['do' => 'delete', 'id' => $id]), 'kontor_shop_category_delete_' . $id);

            echo '<tr data-id="' . esc_attr((string) $id) . '" draggable="true">';
            echo '<td class="ks-col-handle"><span class="ks-move"><button type="button" data-ks-move="up" aria-label="' . esc_attr__('Move up', 'kontor-shop') . '">▲</button>'
                . '<button type="button" data-ks-move="down" aria-label="' . esc_attr__('Move down', 'kontor-shop') . '">▼</button></span></td>';
            echo '<td class="column-primary has-row-actions"><strong><a class="row-title" href="' . esc_url(self::url(['do' => 'edit', 'id' => $id])) . '">' . esc_html($category->name) . '</a></strong>';
            echo '<div class="row-actions"><span class="edit"><a href="' . esc_url(self::url(['do' => 'edit', 'id' => $id])) . '">' . esc_html__('Edit', 'kontor-shop') . '</a> | </span>';
            echo '<span class="delete"><a href="' . esc_url($delete) . '" class="submitdelete" data-ks-confirm="' . esc_attr__('Really delete this category?', 'kontor-shop') . '">' . esc_html__('Delete', 'kontor-shop') . '</a></span></div></td>';

            foreach ($columns as $column) {
                echo '<td>' . (string) ($column['cell'])($category) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Spalten aus kontor_shop/admin/categories_list/columns liefern escaptes HTML
            }

            echo '<td>' . ($category->accent_color !== null && $category->accent_color !== '' ? Form::color_dot($category->accent_color) : '<span class="ks-muted">' . esc_html__('default', 'kontor-shop') . '</span>') . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form::color_dot liefert escaptes HTML
            echo '<td class="ks-col-num">' . ($count > 0 ? '<a href="' . esc_url(ProductsPage::url(['category_id' => $id])) . '">' . (int) $count . '</a>' : '0') . '</td>';
            echo '<td class="ks-col-toggle">' . ProductsList::toggle('category', $id, $category->active, __('Category active', 'kontor-shop')) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ProductsList::toggle liefert escaptes HTML
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    /**
     * @param array<string, mixed> $input
     */
    private function render_form(?Category $editing, array $input): void
    {
        $get = static fn (string $key, mixed $default): mixed => array_key_exists($key, $input) ? $input[$key] : $default;

        echo '<form method="post" action="' . esc_url(self::url($editing ? ['do' => 'edit', 'id' => (int) $editing->id] : ['do' => 'new'])) . '" data-ks-dirty class="ks-edit">';
        wp_nonce_field(self::NONCE_ACTION, 'kontor_shop_category_nonce');
        EditHeader::render(
            self::url(),
            __('Categories', 'kontor-shop'),
            $editing ? $editing->name : __('New category', 'kontor-shop'),
            $editing ? __('Save', 'kontor-shop') : __('Add category', 'kontor-shop'),
            $editing !== null && !$editing->active ? Badge::html(__('inactive', 'kontor-shop'), Badge::MUTED) : ''
        );

        if ($editing) {
            echo '<input type="hidden" name="category_id" value="' . esc_attr((string) $editing->id) . '" />';
        }

        Form::card_open(__('Category', 'kontor-shop'));

        /**
         * Aktion kontor_shop/admin/category_form/fields: eigene Felder oben in
         * der Karte "Kategorie" (Form::field()), z. B. Pro: Auswahl "Shoptyp".
         * Gespeichert wird ueber kontor_shop/admin/category/save_data.
         *
         * @param Category|null                $editing bearbeitete Kategorie, null = neu
         * @param callable(string, mixed): mixed $get   Eingabe nach einem Fehler, sonst der Standardwert
         */
        do_action('kontor_shop/admin/category_form/fields', $editing, $get);
        $parent_current = (int) $get('parent_id', $editing->parent_id ?? 0);
        $parent_select  = '<select id="ks-cat-parent" name="parent_id"><option value="0">' . esc_html__('– top level –', 'kontor-shop') . '</option>';
        $blocked        = $editing !== null
            ? array_merge([(int) $editing->id], CategoryTree::descendants((int) $editing->id, $this->categories->parent_map()))
            : [];

        foreach ($this->categories->all() as $candidate) {
            if (in_array((int) $candidate->id, $blocked, true)) {
                continue;
            }

            $parent_select .= '<option value="' . esc_attr((string) $candidate->id) . '" ' . selected($parent_current, (int) $candidate->id, false) . '>' . esc_html($candidate->name) . '</option>';
        }

        Form::field(__('Parent category', 'kontor-shop'), 'ks-cat-parent', $parent_select . '</select>', __('Optional. Determines the breadcrumbs (Shop › Category › Subcategory › Product) and which products a category page shows.', 'kontor-shop'));
        Form::field(__('Name', 'kontor-shop'), 'ks-cat-name', '<input type="text" id="ks-cat-name" name="name" class="regular-text" required value="' . esc_attr((string) $get('name', $editing->name ?? '')) . '" />', '', true);
        $color = (string) ($get('accent_color', $editing->accent_color ?? '') ?? '');
        Form::field(
            __('Accent color', 'kontor-shop'),
            'ks-cat-color',
            '<span class="ks-color-pick"><input type="color" id="ks-cat-color" name="accent_color" value="' . esc_attr($color !== '' ? $color : '#2c5f3d') . '" />'
                . '<label class="ks-check"><input type="checkbox" name="accent_default" value="1" ' . checked($color === '', true, false) . ' /> ' . esc_html__('Use default color', 'kontor-shop') . '</label></span>',
            __('For buttons and accents on pages of this category.', 'kontor-shop')
        );
        Form::field(
            __('Status', 'kontor-shop'),
            '',
            '<label><input type="checkbox" name="active" value="1" ' . checked((bool) $get('active', $editing === null || $editing->active), true, false) . ' /> ' . esc_html__('Category can be selected in the catalog', 'kontor-shop') . '</label>'
        );
        Form::card_close();

        echo '</form>';
    }
}
