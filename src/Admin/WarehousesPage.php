<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin;

use Kontor\Shop\Admin\Ui\Css;
use Kontor\Shop\Admin\Ui\Badge;
use Kontor\Shop\Admin\Ui\EditHeader;
use Kontor\Shop\Admin\Ui\Flash;
use Kontor\Shop\Admin\Ui\PageHeader;
use Kontor\Shop\Admin\Ui\Form;
use Kontor\Shop\Admin\Ui\ListParams;
use Kontor\Shop\Domain\Catalog\Warehouse;
use Kontor\Shop\Infrastructure\Payment\BankAccounts;
use Kontor\Shop\Infrastructure\Repositories\ProductRepository;
use Kontor\Shop\Infrastructure\Repositories\WarehouseRepository;
use Kontor\Shop\Settings\Repository;

/**
 * Lagerverwaltung (Abschnitt 10): Lager anlegen, Absenderadresse, Bankkonto
 * und Farbe pflegen. Wie viele Lager es geben darf und welche sich
 * bearbeiten lassen, sagt WarehouseRepository (max(), is_editable()) - ohne
 * Erweiterung genau eines; weitere bleiben nur lesbar sichtbar.
 * Lagerverwalter und weitere Felder haengen Erweiterungen (Pro) ueber
 * kontor_shop/admin/warehouse_form/fields, .../save_data und
 * kontor_shop/warehouses/saved an. Ein Lager mit Artikeln kann nicht
 * geloescht werden (stattdessen inaktiv schalten).
 */
final class WarehousesPage
{
    public const SLUG          = 'kontor-shop-warehouses';
    private const NONCE_ACTION = 'kontor_shop_warehouse_save';

    private WarehouseRepository $warehouses;
    private ProductRepository $products;
    private Repository $settings;

    public function __construct(Repository $settings)
    {
        $this->settings   = $settings;
        $this->warehouses = new WarehouseRepository();
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

        $editing = $do === 'edit' ? $this->warehouses->find(ListParams::int('id')) : null;

        if ($do === 'new' && !$this->warehouses->can_create()) {
            echo '<div class="notice notice-warning"><p>' . esc_html(self::limit_message()) . '</p></div>';
            $this->render_list();
        } elseif ($do === 'edit' && $editing !== null && !$this->warehouses->is_editable($editing)) {
            echo '<div class="notice notice-warning"><p>' . esc_html(self::read_only_message()) . '</p></div>';
            $this->render_list();
        } elseif ($do === 'new' || $do === 'edit') {
            $this->render_form($editing, $error['input'] ?? []);
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
        if (sanitize_text_field(wp_unslash((string) ($_SERVER['REQUEST_METHOD'] ?? ''))) !== 'POST' || !isset($_POST['kontor_shop_warehouse_nonce'])) {
            return null;
        }

        check_admin_referer(self::NONCE_ACTION, 'kontor_shop_warehouse_nonce');

        if (!current_user_can(Menu::CAPABILITY)) {
            wp_die(esc_html__('No permission.', 'kontor-shop'));
        }

        $data = [
            'name'            => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
            'contact_name'    => sanitize_text_field(wp_unslash($_POST['contact_name'] ?? '')),
            'address'         => sanitize_textarea_field(wp_unslash($_POST['address'] ?? '')),
            'bank_account_id' => sanitize_key((string) ($_POST['bank_account_id'] ?? '')),
            'color'           => sanitize_hex_color((string) wp_unslash($_POST['color'] ?? '')) ?: '',
            'active'          => !empty($_POST['active']),
        ];

        $id       = absint($_POST['warehouse_id'] ?? 0);
        $previous = $id > 0 ? $this->warehouses->find($id) : null;

        if ($id > 0 && ($previous === null || !$this->warehouses->is_editable($previous))) {
            Flash::redirect(self::url(), $previous === null ? __('Warehouse not found.', 'kontor-shop') : self::read_only_message(), 'error');
        }

        if ($id === 0 && !$this->warehouses->can_create()) {
            Flash::redirect(self::url(), self::limit_message(), 'error');
        }

        /**
         * Filter kontor_shop/admin/warehouse/save_data: Felder des Lagers vor
         * dem Speichern ergaenzen, z. B. Pro: manager_user_id aus
         * kontor_shop/admin/warehouse_form/fields (Werte aus $_POST selbst
         * bereinigen; Nonce und Recht sind geprueft). Fehlt ein Feld, bleibt
         * der gespeicherte Wert. Standard: die Kern-Felder.
         *
         * @param array<string, mixed> $data Felder des Lagers
         * @param int                  $id   Lager-ID, 0 = neu
         */
        $data = (array) apply_filters('kontor_shop/admin/warehouse/save_data', $data, $id);

        if ((string) ($data['name'] ?? '') === '') {
            return ['error' => __('Please enter a name.', 'kontor-shop'), 'input' => $data];
        }

        if ($id > 0) {
            $this->warehouses->update($id, $data);
        } else {
            $id = $this->warehouses->create($data);
        }

        /**
         * Aktion kontor_shop/warehouses/saved: ein Lager wurde im Backend
         * angelegt oder gespeichert (z. B. Pro: Lagerverwalter-Recht
         * vergeben/entziehen).
         *
         * @param int            $id       Lager-ID
         * @param array          $data     gespeicherte Felder (nach kontor_shop/admin/warehouse/save_data)
         * @param Warehouse|null $previous Stand vor dem Speichern, null = neu angelegt
         */
        do_action('kontor_shop/warehouses/saved', $id, $data, $previous);

        Flash::redirect(self::url(), sprintf(
            /* translators: %s: warehouse name */
            $previous !== null ? __('Warehouse “%s” saved.', 'kontor-shop') : __('Warehouse “%s” created.', 'kontor-shop'),
            $data['name']
        ));
    }

    /** Hinweis, wenn kein weiteres Lager angelegt werden darf. */
    private static function limit_message(): string
    {
        return __('This shop manages one warehouse. Edit the existing warehouse instead of creating another one.', 'kontor-shop');
    }

    /** Hinweis zu Lagern, die sich nicht bearbeiten lassen (z. B. nach einem Wechsel der Version). */
    private static function read_only_message(): string
    {
        return __('This warehouse can only be viewed: the shop manages one warehouse (the main warehouse). Its products stay in the shop.', 'kontor-shop');
    }

    private function handle_delete(int $id): void
    {
        check_admin_referer('kontor_shop_warehouse_delete_' . $id);

        $warehouse = $id > 0 ? $this->warehouses->find($id) : null;

        if ($warehouse === null) {
            Flash::redirect(self::url(), __('Warehouse not found.', 'kontor-shop'), 'error');
        }

        if (!$this->warehouses->is_editable($warehouse)) {
            Flash::redirect(self::url(), self::read_only_message(), 'error');
        }

        $used = $this->products->count_using('warehouse_id', $id);

        if ($used > 0) {
            Flash::redirect(self::url(), sprintf(
                /* translators: 1: warehouse name, 2: number of products */
                _n(
                    '“%1$s” cannot be deleted: %2$d product (including archive) still belongs to it. Assign it to another warehouse or set the warehouse to inactive.',
                    '“%1$s” cannot be deleted: %2$d products (including archive) still belong to it. Assign them to another warehouse or set the warehouse to inactive.',
                    $used,
                    'kontor-shop'
                ),
                $warehouse->name,
                $used
            ), 'error');
        }

        $this->warehouses->delete($id);

        /**
         * Aktion kontor_shop/warehouses/deleted: ein Lager wurde im Backend
         * geloescht (z. B. Pro: Lagerverwalter-Recht entziehen).
         *
         * @param Warehouse $warehouse Stand vor dem Loeschen
         */
        do_action('kontor_shop/warehouses/deleted', $warehouse);

        /* translators: %s: warehouse name */
        Flash::redirect(self::url(), sprintf(__('Warehouse “%s” deleted.', 'kontor-shop'), $warehouse->name));
    }

    private function render_list(): void
    {
        $warehouses = $this->warehouses->all();
        $counts     = $this->products->count_by('warehouse_id');
        $accounts   = [];
        $columns    = self::columns();

        foreach ((new BankAccounts($this->settings))->all() as $account) {
            $accounts[$account->id] = $account->display_name();
        }

        PageHeader::render(
            __('Warehouse', 'kontor-shop'),
            $warehouses !== [] && $this->warehouses->can_create() ? [['label' => __('Add new warehouse', 'kontor-shop'), 'url' => self::url(['do' => 'new']), 'primary' => true]] : [],
            __('A warehouse is the place products are shipped from. Each product belongs to exactly one warehouse.', 'kontor-shop')
        );

        if ($warehouses === []) {
            echo '<div class="ks-empty"><p><strong>' . esc_html__('No warehouse created yet.', 'kontor-shop') . '</strong></p><p>'
                . esc_html__('Products cannot be added without a warehouse.', 'kontor-shop') . '</p><p><a class="button button-primary" href="'
                . esc_url(self::url(['do' => 'new'])) . '">' . esc_html__('Create first warehouse', 'kontor-shop') . '</a></p></div>';

            return;
        }

        $read_only = array_filter($warehouses, fn (Warehouse $warehouse): bool => !$this->warehouses->is_editable($warehouse));

        if ($read_only !== []) {
            echo '<div class="notice notice-info inline"><p>' . esc_html__('The shop manages one warehouse (the main warehouse). Further warehouses stay visible and their products stay in the shop, but they can only be viewed.', 'kontor-shop') . '</p></div>';
        }

        echo '<div class="ks-table-scroll"><table class="wp-list-table widefat fixed striped ks-table"><thead><tr>';
        echo '<th class="column-primary">' . esc_html__('Name', 'kontor-shop') . '</th><th>' . esc_html__('Contact', 'kontor-shop') . '</th>';

        foreach ($columns as $column) {
            echo '<th>' . esc_html($column['label']) . '</th>';
        }

        echo '<th>' . esc_html__('Bank account (prepayment)', 'kontor-shop') . '</th>';
        echo '<th class="ks-col-num">' . esc_html__('Product', 'kontor-shop') . '</th><th class="ks-col-toggle">' . esc_html__('Active', 'kontor-shop') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($warehouses as $warehouse) {
            $id       = (int) $warehouse->id;
            $count    = $counts[$id] ?? 0;
            $delete   = wp_nonce_url(self::url(['do' => 'delete', 'id' => $id]), 'kontor_shop_warehouse_delete_' . $id);
            $editable = $this->warehouses->is_editable($warehouse);

            echo '<tr data-id="' . esc_attr((string) $id) . '">';

            if ($editable) {
                echo '<td class="column-primary has-row-actions"><strong><span class="ks-wh-dot ' . esc_attr(Css::wh($id)) . '"></span><a class="row-title" href="' . esc_url(self::url(['do' => 'edit', 'id' => $id])) . '">' . esc_html($warehouse->name) . '</a></strong>';
                echo '<div class="row-actions"><span class="edit"><a href="' . esc_url(self::url(['do' => 'edit', 'id' => $id])) . '">' . esc_html__('Edit', 'kontor-shop') . '</a> | </span>';
                echo '<span class="delete"><a href="' . esc_url($delete) . '" class="submitdelete" data-ks-confirm="' . esc_attr__('Really delete warehouse?', 'kontor-shop') . '">' . esc_html__('Delete', 'kontor-shop') . '</a></span></div></td>';
            } else {
                echo '<td class="column-primary"><strong><span class="ks-wh-dot ' . esc_attr(Css::wh($id)) . '"></span>' . esc_html($warehouse->name) . '</strong> '
                    . Badge::html(__('view only', 'kontor-shop'), Badge::MUTED) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Badge::html liefert escaptes HTML
            }

            echo '<td>' . esc_html($warehouse->contact_name !== '' ? $warehouse->contact_name : '—') . '</td>';

            foreach ($columns as $column) {
                echo '<td>' . wp_kses_post((string) ($column['cell'])($warehouse)) . '</td>';
            }

            echo '<td>' . esc_html($accounts[(string) $warehouse->bank_account_id] ?? __('Default account', 'kontor-shop')) . '</td>';
            echo '<td class="ks-col-num">' . ($count > 0 ? '<a href="' . esc_url(ProductsPage::url(['warehouse_id' => $id])) . '">' . (int) $count . '</a>' : '0') . '</td>';
            echo '<td class="ks-col-toggle">' . ($editable
                ? ProductsList::toggle('warehouse', $id, $warehouse->active, __('Warehouse active', 'kontor-shop'))
                : esc_html($warehouse->active ? __('yes', 'kontor-shop') : __('no', 'kontor-shop'))) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ProductsList::toggle() liefert escaptes HTML
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    /**
     * Zusaetzliche Spalten der Lagerliste (nach "Kontakt").
     *
     * @return array<int, array{label: string, cell: callable}>
     */
    private static function columns(): array
    {
        /**
         * Filter kontor_shop/admin/warehouses/columns: weitere Spalten der
         * Lagerliste, z. B. Pro: "Lagerverwalter". Standard: keine.
         *
         * @param array<int, array{label: string, cell: callable(Warehouse): string}> $columns
         *        cell liefert HTML (wird mit wp_kses_post ausgegeben)
         */
        $columns = (array) apply_filters('kontor_shop/admin/warehouses/columns', []);

        return array_values(array_filter($columns, static fn ($column): bool => is_array($column) && isset($column['label'], $column['cell']) && is_callable($column['cell'])));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function render_form(?Warehouse $editing, array $input): void
    {
        $get = static fn (string $key, mixed $default): mixed => array_key_exists($key, $input) ? $input[$key] : $default;

        echo '<form method="post" action="' . esc_url(self::url($editing ? ['do' => 'edit', 'id' => (int) $editing->id] : ['do' => 'new'])) . '" data-ks-dirty class="ks-edit">';
        wp_nonce_field(self::NONCE_ACTION, 'kontor_shop_warehouse_nonce');
        EditHeader::render(
            self::url(),
            __('Warehouse', 'kontor-shop'),
            $editing ? $editing->name : __('New warehouse', 'kontor-shop'),
            $editing ? __('Save', 'kontor-shop') : __('Create warehouse', 'kontor-shop'),
            $editing !== null && !$editing->active ? Badge::html(__('inactive', 'kontor-shop'), Badge::MUTED) : ''
        );

        if ($editing) {
            echo '<input type="hidden" name="warehouse_id" value="' . esc_attr((string) $editing->id) . '" />';
        }

        Form::card_open(__('Warehouse', 'kontor-shop'));
        Form::field(__('Name', 'kontor-shop'), 'ks-wh-name', '<input type="text" id="ks-wh-name" name="name" class="regular-text" required value="' . esc_attr((string) $get('name', $editing->name ?? '')) . '" />', __('E.g. “Main warehouse” or “North club”.', 'kontor-shop'), true);
        Form::field(__('Contact name', 'kontor-shop'), 'ks-wh-contact', '<input type="text" id="ks-wh-contact" name="contact_name" class="regular-text" value="' . esc_attr((string) $get('contact_name', $editing->contact_name ?? '')) . '" />', __('Contact person for this warehouse (optional).', 'kontor-shop'));
        Form::field(__('Address and pickup times', 'kontor-shop'), 'ks-wh-address', '<textarea id="ks-wh-address" name="address" class="large-text" rows="3">' . esc_textarea((string) $get('address', $editing->address ?? '')) . '</textarea>', __('Customers see this as the pickup location in the email “Ready for pickup”. Add pickup times if you like, e.g. “Pickup Wed 6–8 pm”.', 'kontor-shop'));

        /**
         * Aktion kontor_shop/admin/warehouse_form/fields: weitere Felder im
         * Lager-Formular (nach der Absenderadresse), z. B. Pro: der
         * Lagerverwalter. Gespeichert werden sie ueber
         * kontor_shop/admin/warehouse/save_data.
         *
         * @param Warehouse|null       $editing bearbeitetes Lager, null = neu
         * @param array<string, mixed> $input   Eingaben nach einem Fehler
         */
        do_action('kontor_shop/admin/warehouse_form/fields', $editing, $input);

        $select = '<select id="ks-wh-account" name="bank_account_id"><option value="">' . esc_html__('– default account –', 'kontor-shop') . '</option>';

        foreach ((new BankAccounts($this->settings))->all() as $i => $account) {
            $select .= '<option value="' . esc_attr($account->id) . '" ' . selected((string) $get('bank_account_id', $editing->bank_account_id ?? ''), $account->id, false) . '>'
                . esc_html(($i === 0 ? '★ ' : '') . $account->display_name() . ($i === 0 ? ' (' . __('Default account', 'kontor-shop') . ')' : '')) . '</option>';
        }

        Form::field(__('Bank account (prepayment)', 'kontor-shop'), 'ks-wh-account', $select . '</select>', __('Prepayment payments for products from this warehouse go to this account. You manage accounts under Settings → Payment.', 'kontor-shop'));
        $color = (string) $get('color', $editing?->color ?? '');
        $shown = $color !== '' ? $color : ($editing !== null ? $editing->display_color() : '#2271b1');

        Form::field(
            __('Color', 'kontor-shop'),
            'ks-wh-color',
            '<input type="color" id="ks-wh-color" name="color" value="' . esc_attr($shown) . '" />',
            __('Identifying color of the warehouse – e.g. in the “Record shipment” dialog, so you can tell warehouses apart at a glance.', 'kontor-shop')
        );
        Form::field(
            __('Status', 'kontor-shop'),
            '',
            '<label><input type="checkbox" name="active" value="1" ' . checked((bool) $get('active', $editing === null || $editing->active), true, false) . ' /> ' . esc_html__('Warehouse is active', 'kontor-shop') . '</label>',
            __('Inactive warehouses are kept along with their products.', 'kontor-shop')
        );
        Form::card_close();

        echo '</form>';
    }
}
