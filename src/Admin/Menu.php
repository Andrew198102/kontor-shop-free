<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin;

use Kontor\Shop\Domain\Order\PaidWith;
use Kontor\Shop\Core\Capabilities;
use Kontor\Shop\Core\Registerable;
use Kontor\Shop\Domain\Catalog\UnitPrice;
use Kontor\Shop\Domain\Catalog\VariantMatrix;
use Kontor\Shop\Admin\Ui\Css;
use Kontor\Shop\Infrastructure\Repositories\ProductRepository;
use Kontor\Shop\Settings\Repository;

/**
 * Registriert das wp-admin-Menue "Kontor Shop". Landing-Seite ist die
 * Uebersicht (DashboardPage), die fuer alle drei Rollen relevant ist
 * (Abschnitt 8/10). Das Menue ist nach Haeufigkeit gegliedert: Tagesgeschaeft
 * (Uebersicht, Bestellungen, Artikel, Bestand; mit Pro Rabatte und Anfragen), Stammdaten (Kategorien,
 * Lager) und Einrichtung (Einstellungen, Protokolle) - die Gruppen werden per
 * CSS-Trennlinie abgesetzt. Mail-Vorlagen (mit Pro auch Shoptypen) sind
 * Bereiche der Einstellungen; unter "Protokolle" haengen versteckte Unterseiten (Problem
 * melden, mit Pro das Aenderungsprotokoll).
 * Alles ausser Bestellungen/Bestand bleibt Shop-Manager/Admin-only
 * (Capabilities::MANAGE); Einstellungen und Protokolle sind Admin-only
 * (Capabilities::SETTINGS).
 * "Uebersicht", "Bestellungen" und "Bestand" nutzen Capabilities::ACCESS als
 * Menue-Sichtbarkeits-Klammer (WordPress prueft pro Menuepunkt nur eine
 * einzelne Capability); die eigentliche Autorisierung je Rolle/Aktion
 * pruefen die Seiten selbst gegen Capabilities::MANAGE/CASHIER/WAREHOUSE_MANAGER.
 */
final class Menu implements Registerable
{
    public const CAPABILITY          = Capabilities::MANAGE;
    public const SETTINGS_CAPABILITY = Capabilities::SETTINGS;

    /** Gruppen fuer kontor_shop/admin/menu (Erweiterungen haengen sich am Ende der Gruppe an). */
    public const GROUP_DAILY  = 'daily';
    public const GROUP_MASTER = 'master';
    public const GROUP_SETUP  = 'setup';

    private DashboardPage $dashboard_page;
    private DashboardWidget $dashboard_widget;
    private ProductsPage $products_page;
    private WarehousesPage $warehouses_page;
    private CategoriesPage $categories_page;
    private StockPage $stock_page;
    private SettingsPage $settings_page;
    private LogPage $log_page;

    private ReportPage $report_page;
    private OrdersPage $orders_page;
    private Ajax $ajax;

    private string $settings_page_hook = '';
    private string $products_page_hook  = '';
    private string $stock_page_hook     = '';
    private string $orders_page_hook    = '';

    public function __construct(private readonly Repository $settings)
    {
        $this->dashboard_page  = new DashboardPage($settings);
        $this->dashboard_widget = new DashboardWidget($settings);
        $this->products_page  = new ProductsPage($settings);
        $this->warehouses_page = new WarehousesPage($settings);
        $this->categories_page = new CategoriesPage();
        $this->stock_page      = new StockPage();
        $this->settings_page   = new SettingsPage($settings);
        $this->log_page        = new LogPage($settings);
        $this->report_page     = new ReportPage($settings);
        $this->orders_page     = new OrdersPage($settings);

        $products   = new ProductRepository();
        $this->ajax = new Ajax(new ProductsList($products, $settings), $products);
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_menu']);
        // admin_menu statt admin_init: WordPress prueft den Seitenzugriff noch vor admin_init (alte Seiten gibt es nicht mehr).
        add_action('admin_menu', [$this, 'redirect_legacy_pages'], 0);
        add_action('admin_head', [$this, 'print_menu_styles']);
        add_filter('submenu_file', [$this, 'highlight_hidden_pages']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_kontor_shop_stock_adjust', [$this->stock_page, 'ajax_adjust']);
        add_action('wp_ajax_kontor_shop_stock_min', [$this->stock_page, 'ajax_min_stock']);
        add_action('admin_post_' . StockPage::INVENTORY_ACTION, [$this->stock_page, 'export_inventory']);

        $this->ajax->register();
        $this->dashboard_widget->register();
    }

    /**
     * Trennlinien mit Gruppenname im Menue (auf jeder Admin-Seite noetig, daher
     * inline statt in admin.css, das nur auf Plugin-Seiten geladen wird).
     */
    public function print_menu_styles(): void
    {
        $groups = [
            'kontor-shop-categories' => __('Master data', 'kontor-shop'),
            SettingsPage::SLUG       => __('Setup', 'kontor-shop'),
        ];

        echo '<style id="kontor-shop-menu">';

        foreach ($groups as $slug => $label) {
            echo '#adminmenu .wp-submenu a[href$="page=' . esc_attr($slug) . '"]::before{content:"' . esc_attr(mb_strtoupper($label)) . '";display:block;margin:-2px 0 5px;padding-top:7px;'
                . 'border-top:1px solid rgba(240,246,252,.25);font-size:9px;letter-spacing:.08em;opacity:.65;}';
        }

        foreach (array_keys(self::hidden_pages()) as $hidden) {
            echo '#adminmenu .wp-submenu li:has(> a[href$="page=' . esc_attr($hidden) . '"]){display:none;}';
        }

        // PRO-Abzeichen neben "Kontor Shop" (self::badge()).
        echo '#adminmenu .toplevel_page_kontor-shop .wp-menu-name{white-space:nowrap;}'
            . '#adminmenu .ks-pro-badge{display:inline-block;margin-left:5px;padding:1px 6px;border-radius:999px;vertical-align:1px;'
            . 'background:linear-gradient(180deg,#ebc47a,#e0b25b);color:#241a06;font-size:9px;font-weight:800;line-height:15px;letter-spacing:.08em;}'
            . '#adminmenu .ks-pro-badge.is-off{background:transparent;color:inherit;opacity:.55;box-shadow:inset 0 0 0 1px currentColor;text-decoration:line-through;}';
        echo '</style>';
    }

    /**
     * Abzeichen hinter "Kontor Shop" im Admin-Menue: "pro" = gueltige Lizenz (gold),
     * "expired" = Pro installiert, Lizenz abgelaufen (durchgestrichen). Den Stand
     * liefert src/freemius.php (kontor_shop/admin/pro_badge); Free zeigt nichts.
     */
    private static function badge(): string
    {
        $state = (string) apply_filters('kontor_shop/admin/pro_badge', '');

        if ($state === 'pro') {
            return ' <span class="ks-pro-badge">PRO</span>';
        }

        if ($state === 'expired') {
            return ' <span class="ks-pro-badge is-off" title="' . esc_attr__('Pro licence expired – Pro features are off', 'kontor-shop') . '">PRO</span>';
        }

        return '';
    }

    public function enqueue_assets(string $hook_suffix): void
    {
        // Das Dashboard-Widget braucht die Shop-Styles auch auf der WP-Startseite.
        if ($hook_suffix === 'index.php' && (Capabilities::can_manage() || Capabilities::is_cashier() || Capabilities::is_warehouse_manager())) {
            wp_enqueue_style('kontor-shop-admin', KONTOR_SHOP_URL . 'assets/admin/admin.css', [], KONTOR_SHOP_VERSION);
            wp_add_inline_style('kontor-shop-admin', Css::warehouse_css());

            return;
        }

        if (!str_contains($hook_suffix, 'kontor-shop')) {
            return;
        }

        wp_enqueue_style('kontor-shop-admin', KONTOR_SHOP_URL . 'assets/admin/admin.css', [], KONTOR_SHOP_VERSION);
        wp_add_inline_style('kontor-shop-admin', Css::warehouse_css());
        wp_enqueue_script('kontor-shop-admin', KONTOR_SHOP_URL . 'assets/admin/admin.js', [], KONTOR_SHOP_VERSION, true);
        wp_localize_script('kontor-shop-admin', 'kontorShopAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(Ajax::NONCE),
            'i18n'    => [
                'networkError'      => __('Connection error – please try again.', 'kontor-shop'),
                'saveError'         => __('Saving failed.', 'kontor-shop'),
                'saved'             => __('Saved.', 'kontor-shop'),
                'orderSaved'        => __('Sort order saved.', 'kontor-shop'),
                'confirmBulkDelete' => __('Permanently delete the selected products? This can’t be undone.', 'kontor-shop'),
                'copied'            => __('Copied.', 'kontor-shop'),
                'copyManual'        => __('Text selected – now copy with Ctrl/⌘+C.', 'kontor-shop'),
            ],
        ]);

        if ($hook_suffix === $this->orders_page_hook) {
            wp_enqueue_script('kontor-shop-orders', KONTOR_SHOP_URL . 'assets/admin/orders.js', ['kontor-shop-admin'], KONTOR_SHOP_VERSION, true);
            wp_localize_script('kontor-shop-orders', 'kontorShopOrders', [
                'paidWith' => array_map(static fn (string $key, string $label): array => [$key, $label], array_keys(PaidWith::labels()), PaidWith::labels()),
                'i18n'     => [
                    'paidTitle'   => __('Record payment', 'kontor-shop'),
                    'reference'   => __('Ref.', 'kontor-shop'),
                    'paidWith'    => __('Paid with', 'kontor-shop'),
                    'paidOn'      => __('Payment received on', 'kontor-shop'),
                    'paidHint'    => __('The customer gets the payment confirmation, the warehouse gets the shipping order.', 'kontor-shop'),
                    'cancel'      => __('Cancel', 'kontor-shop'),
                    'markPaid'    => __('Mark as paid', 'kontor-shop'),
                    /* translators: %d: count */
                    'selected'    => __('%d selected', 'kontor-shop'),
                    /* translators: %d: count */
                    'confirmPaid' => __('Mark %d orders as paid?', 'kontor-shop'),
                    /* translators: %d: count */
                    'confirmShip' => __('Mark %d orders completely as shipped/picked up?', 'kontor-shop'),
                    'copied'      => __('Address copied.', 'kontor-shop'),
                    'copyManual'  => __('Address selected – now copy it.', 'kontor-shop'),
                ],
            ]);
        }

        if ($hook_suffix === $this->stock_page_hook) {
            wp_enqueue_script('kontor-shop-stock', KONTOR_SHOP_URL . 'assets/admin/stock.js', ['kontor-shop-admin'], KONTOR_SHOP_VERSION, true);
            wp_localize_script('kontor-shop-stock', 'kontorShopStock', [
                'types' => StockPage::popover_types(),
                'i18n'  => [
                    'bookIn'       => __('Book stock in', 'kontor-shop'),
                    'bookOut'      => __('Book stock out', 'kontor-shop'),
                    'what'         => __('What happened?', 'kontor-shop'),
                    'variant'      => __('Variant', 'kontor-shop'),
                    'pickVariant'  => __('Please choose a variant.', 'kontor-shop'),
                    'quantity'     => __('Quantity', 'kontor-shop'),
                    'less'         => __('less', 'kontor-shop'),
                    'more'         => __('more', 'kontor-shop'),
                    'stockNow'     => __('Stock', 'kontor-shop'),
                    'notePlaceholder' => __('Note, e.g. delivery note 2026-118 (optional)', 'kontor-shop'),
                    'noteRequired' => __('Reason for the correction (required)', 'kontor-shop'),
                    'noteMissing'  => __('A note is required for a correction.', 'kontor-shop'),
                    'qtyMissing'   => __('The quantity must be greater than 0.', 'kontor-shop'),
                    'book'         => __('Book', 'kontor-shop'),
                    'cancel'       => __('Cancel', 'kontor-shop'),
                    'keys'         => __('Enter books · Esc closes', 'kontor-shop'),
                    'negative'     => __('Warning: stock will go negative.', 'kontor-shop'),
                    'outOnly'      => __('write off only', 'kontor-shop'),
                    'expand'       => __('▸ Variants', 'kontor-shop'),
                    'collapse'     => __('▾ collapse', 'kontor-shop'),
                    /* translators: %d: count */
                    'selected'     => __('%d selected', 'kontor-shop'),
                    /* translators: %d: count */
                    'minSummary'   => __('For %d selected products.', 'kontor-shop'),
                    'remove'       => __('Remove row', 'kontor-shop'),
                    'noCode'       => __('No matching SKU/EAN found.', 'kontor-shop'),
                    'receiveBtn'   => __('Book delivery', 'kontor-shop'),
                    /* translators: %d: pieces */
                    'receivePcs'   => __('Book delivery (%d pcs.)', 'kontor-shop'),
                    'counted'      => __('Nothing counted yet.', 'kontor-shop'),
                    /* translators: 1: counted rows, 2: differences */
                    'countSummary' => __('%1$d counted · %2$d differences', 'kontor-shop'),
                    'fits'         => __('matches', 'kontor-shop'),
                    'preparing'    => __('Creating …', 'kontor-shop'),
                    'booking'      => __('Booking …', 'kontor-shop'),
                    'downloaded'   => __('File downloaded.', 'kontor-shop'),
                    'downloadFail' => __('Export failed. Please reload the page and try again.', 'kontor-shop'),
                ],
            ]);
        }

        if ($hook_suffix === $this->products_page_hook) {
            $this->enqueue_media_picker();

            wp_enqueue_script('kontor-shop-product-editor', KONTOR_SHOP_URL . 'assets/admin/product-editor.js', [], KONTOR_SHOP_VERSION, true);
            wp_localize_script('kontor-shop-product-editor', 'kontorShopProductEditor', [
                'maxCombinations' => VariantMatrix::MAX_COMBINATIONS,
                'units'           => UnitPrice::units(),
                'i18n'            => [
                    'defaultName'       => __('Variant', 'kontor-shop'),
                    /* translators: %d: number of variants */
                    'count'             => __('%d variants', 'kontor-shop'),
                    /* translators: %d: number of variants being dropped */
                    'dropped'           => __('%d will be dropped when you save', 'kontor-shop'),
                    'none'              => __('Please enter at least one attribute with values.', 'kontor-shop'),
                    /* translators: 1: count, 2: maximum number */
                    'tooMany'           => __('Too many combinations: %1$d (at most %2$s). Please reduce the values.', 'kontor-shop'),
                    'unitPrice'         => __('Unit price:', 'kontor-shop'),
                    'unitSame'          => __('Unit price = selling price (not shown separately)', 'kontor-shop'),
                    'galleryTitle'      => __('Add images', 'kontor-shop'),
                    'variantImageTitle' => __('Image for the variant', 'kontor-shop'),
                    'confirmOff'        => __('Turn off variants? When you save, all variants are deactivated and the product is sold without variants again (variant stock stays saved and comes back if you turn them on again).', 'kontor-shop'),
                    'scopeAll'          => __('all variants', 'kontor-shop'),
                    /* translators: %s: list of renamed items */
                    'renamed'           => __('renamed: %s – stock, price and image are kept', 'kontor-shop'),
                    /* translators: %d: number of variants */
                    'bulkDone'          => __('Price entered for %d variants – apply with “Save”.', 'kontor-shop'),
                    /* translators: %d: number of SKUs */
                    'skuDone'           => __('%d SKUs generated (empty fields only).', 'kontor-shop'),
                    'amountAuto'        => __('Automatically from the variant name', 'kontor-shop'),
                    'fromProduct'       => __('Choose the product image:', 'kontor-shop'),
                    'fromLibrary'       => __('Another image from the media library …', 'kontor-shop'),
                    'tooBig'            => __('The file is too large.', 'kontor-shop'),
                    'uploading'         => __('Uploading …', 'kontor-shop'),
                    'uploaded'          => __('Uploaded. Apply with “Save”.', 'kontor-shop'),
                    'uploadFailed'      => __('Upload failed.', 'kontor-shop'),
                    'busy'              => __('Please wait until all images have been uploaded.', 'kontor-shop'),
                ],
            ]);

            return;
        }

        if ($hook_suffix !== $this->settings_page_hook) {
            return;
        }

        $this->enqueue_media_picker();
    }

    private function enqueue_media_picker(): void
    {
        wp_enqueue_media(); // nur fuer den Zweitweg "Aus Mediathek waehlen"
        wp_enqueue_style('kontor-shop-media-picker', KONTOR_SHOP_URL . 'assets/admin/media-picker.css', [], KONTOR_SHOP_VERSION);
        wp_enqueue_script(
            'kontor-shop-media-picker',
            KONTOR_SHOP_URL . 'assets/admin/media-picker.js',
            [],
            KONTOR_SHOP_VERSION,
            true
        );
        wp_localize_script('kontor-shop-media-picker', 'kontorShopMedia', MediaPicker::script_data());
    }

    /**
     * Die fruehere eigene Seite "Mail-Vorlagen" ist jetzt ein Reiter der
     * Einstellungen - alte Lesezeichen/Links fuehren dorthin.
     */
    public function redirect_legacy_pages(): void
    {
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash((string) $_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige-Parameter (Weiterleitung alter Links)

        if ($page === 'kontor-shop-templates' && current_user_can(self::SETTINGS_CAPABILITY)) {
            $event = sanitize_key((string) ($_GET['event'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige-Parameter (Weiterleitung alter Links)

            wp_safe_redirect(TemplatesPage::url($event));
            exit;
        }
    }

    /**
     * Versteckte Unterseiten markieren ihren sichtbaren Menuepunkt
     * (z. B. "Report a problem" gehoert zu "Protokolle").
     */
    public function highlight_hidden_pages(?string $submenu_file): ?string
    {
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash((string) $_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige-Parameter

        return self::hidden_pages()[$page] ?? $submenu_file;
    }

    /**
     * Versteckte Unterseiten => sichtbarer Menuepunkt, zu dem sie gehoeren.
     * Erweiterungen (Pro) haengen eigene ueber kontor_shop/admin/hidden_pages an.
     *
     * @return array<string, string>
     */
    private static function hidden_pages(): array
    {
        /** @var array<string, string> $pages */
        $pages = (array) apply_filters('kontor_shop/admin/hidden_pages', [ReportPage::SLUG => LogPage::SLUG]);

        return $pages;
    }

    /**
     * Einfarbiges Menue-Icon (Tasche mit Vereinswappen) als Daten-URI;
     * WordPress faerbt es passend zum Admin-Farbschema ein.
     */
    private static function menu_icon(): string
    {
        $svg = (string) file_get_contents(KONTOR_SHOP_PATH . 'assets/admin/menu-icon.svg'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- lokale Plugin-Datei

        return $svg !== '' ? 'data:image/svg+xml;base64,' . base64_encode($svg) : 'dashicons-store';
    }

    public function register_menu(): void
    {
        $dashboard_hook = add_menu_page(
            __('Kontor Shop', 'kontor-shop'),
            __('Kontor Shop', 'kontor-shop') . self::badge(),
            Capabilities::ACCESS,
            DashboardPage::SLUG,
            [$this->dashboard_page, 'render'],
            self::menu_icon()
        );

        // Tagesgeschaeft
        $this->submenu(DashboardPage::SLUG, __('Dashboard', 'kontor-shop'), Capabilities::ACCESS, [$this->dashboard_page, 'render'], 'dashboard');
        $this->orders_page_hook = $this->submenu(OrdersPage::SLUG, __('Orders', 'kontor-shop'), Capabilities::ACCESS, [$this->orders_page, 'render'], 'orders', [$this->orders_page, 'handle_request']);

        $products_hook            = $this->submenu(ProductsPage::SLUG, __('Product', 'kontor-shop'), self::CAPABILITY, [$this->products_page, 'render'], 'products', [$this->products_page, 'handle_request']);
        $this->products_page_hook = $products_hook;

        $this->stock_page_hook = $this->submenu(StockPage::SLUG, __('Stock', 'kontor-shop'), Capabilities::ACCESS, [$this->stock_page, 'render'], 'stock', [$this->stock_page, 'handle_request']);

        do_action('kontor_shop/admin/menu', $this, self::GROUP_DAILY);

        // Stammdaten
        $this->submenu(CategoriesPage::SLUG, __('Categories', 'kontor-shop'), self::CAPABILITY, [$this->categories_page, 'render'], 'categories', [$this->categories_page, 'handle_request']);
        $this->submenu(WarehousesPage::SLUG, __('Warehouse', 'kontor-shop'), self::CAPABILITY, [$this->warehouses_page, 'render'], 'warehouses', [$this->warehouses_page, 'handle_request']);
        do_action('kontor_shop/admin/menu', $this, self::GROUP_MASTER);

        // Einrichtung
        $this->settings_page_hook = $this->submenu(SettingsPage::SLUG, __('Settings', 'kontor-shop'), self::SETTINGS_CAPABILITY, [$this->settings_page, 'render'], 'settings', [$this->settings_page, 'handle_request']);
        $this->submenu(LogPage::SLUG, __('Logs', 'kontor-shop'), self::SETTINGS_CAPABILITY, [$this->log_page, 'render'], null, [$this->log_page, 'handle_request']);

        // Versteckte Unterseiten (siehe hidden_pages()): Seite registrieren, Menuepunkt per CSS ausblenden
        // (remove_submenu_page() wuerde WordPress den Zugriff auf die Seite verweigern lassen).
        $this->submenu(ReportPage::SLUG, __('Report a problem', 'kontor-shop'), self::SETTINGS_CAPABILITY, [$this->report_page, 'render']);
        do_action('kontor_shop/admin/menu', $this, self::GROUP_SETUP);

        if (is_string($dashboard_hook)) {
            Help::register($dashboard_hook, 'dashboard');
            add_action('load-' . $dashboard_hook, [$this->dashboard_page, 'handle_request']);
        }
    }

    /**
     * Unterseite fuer Erweiterungen (Pro): aus kontor_shop/admin/menu heraus
     * aufrufen, dann landet sie in der jeweiligen Gruppe. Versteckte Seiten
     * zusaetzlich in kontor_shop/admin/hidden_pages eintragen.
     *
     * @return string Hook-Suffix der Seite ('' falls nicht registriert)
     */
    public function add_page(string $slug, string $title, string $capability, callable $callback, ?string $help = null, ?callable $handle = null, ?string $page_title = null): string
    {
        return $this->submenu($slug, $title, $capability, $callback, $help, $handle, $page_title);
    }

    /**
     * @param callable $callback
     * @return string Hook-Suffix der Seite ('' falls nicht registriert)
     */
    private function submenu(string $slug, string $title, string $capability, callable $callback, ?string $help = null, ?callable $handle = null, ?string $page_title = null): string
    {
        $hook = add_submenu_page('kontor-shop', $page_title ?? $title, $title, $capability, $slug, $callback);
        $hook = is_string($hook) ? $hook : '';

        if ($hook !== '' && $help !== null && $help !== 'dashboard') {
            Help::register($hook, $help);
        }

        if ($hook !== '' && $handle !== null) {
            add_action('load-' . $hook, $handle);
        }

        return $hook;
    }
}
