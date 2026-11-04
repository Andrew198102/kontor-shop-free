<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin;

use Kontor\Shop\Admin\Ui\Css;
use Kontor\Shop\Admin\Ui\Badge;
use Kontor\Shop\Admin\Ui\Form;
use Kontor\Shop\Admin\Ui\Options;
use Kontor\Shop\Domain\Catalog\Category;
use Kontor\Shop\Domain\Catalog\Product;
use Kontor\Shop\Domain\Catalog\UnitPrice;
use Kontor\Shop\Domain\Catalog\Warehouse;
use Kontor\Shop\Domain\Seo\UrlScheme;
use Kontor\Shop\Modules\Seo\ProductUrl;
use Kontor\Shop\Domain\Stock\StockStatus;
use Kontor\Shop\Infrastructure\Checkout\ReservationStore;
use Kontor\Shop\Infrastructure\Repositories\CategoryRepository;
use Kontor\Shop\Infrastructure\Repositories\ProductRepository;
use Kontor\Shop\Infrastructure\Repositories\StockRepository;
use Kontor\Shop\Infrastructure\Repositories\VariantRepository;
use Kontor\Shop\Infrastructure\Repositories\WarehouseRepository;
use Kontor\Shop\Modules\Seo\SeoModule;
use Kontor\Shop\Settings\Repository;

/**
 * Anlegen-/Bearbeiten-Ansicht eines Artikels als eigene Seite: oben eine
 * klebende Kopfleiste (Name, Status-Marken, Speichern), links die
 * Seitenleiste mit Veroeffentlichen, Bestand, Lager & Kategorien und
 * Shop-Adresse, rechts der Inhalt (Grunddaten, Bilder, Preis & Steuer,
 * Varianten). Beim Anlegen kann ein Anfangsbestand erfasst werden; bei einem
 * Speicherfehler werden die Eingaben wieder eingesetzt.
 */
final class ProductForm
{
    /** @var callable(string, mixed): mixed */
    private $get;

    public function __construct(
        private readonly Repository $settings,
        private readonly StockRepository $stock
    ) {
    }

    /**
     * @param array<string, mixed> $input Eingaben nach einem Fehler (ueberschreiben die gespeicherten Werte)
     */
    public function render(?Product $product, array $input = [], ?string $error = null): void
    {
        $editing    = $product !== null;
        $warehouses = (new WarehouseRepository())->all();
        $this->get  = static fn (string $key, mixed $default): mixed => array_key_exists($key, $input) ? $input[$key] : $default;

        $action = ProductsPage::url($editing ? ['do' => 'edit', 'id' => (int) $product->id] : ['do' => 'new']);

        echo '<form method="post" action="' . esc_url($action) . '" data-ks-dirty class="ks-edit">';
        wp_nonce_field(ProductsPage::NONCE_ACTION, 'kontor_shop_product_nonce');

        if ($editing) {
            echo '<input type="hidden" name="product_id" value="' . esc_attr((string) $product->id) . '" />';
        }

        $this->render_head($product, $input);

        echo '<hr class="wp-header-end" />';

        if ($error !== null) {
            echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
        }

        if ($warehouses === []) {
            echo '<div class="notice notice-warning"><p>' . wp_kses_post(sprintf(
                /* translators: %s: "Create warehouse" link */
                __('A product always belongs to a warehouse. Please %s first.', 'kontor-shop'),
                '<a href="' . esc_url(admin_url('admin.php?page=kontor-shop-warehouses&do=new')) . '">' . esc_html__('create a warehouse', 'kontor-shop') . '</a>'
            )) . '</p></div>';
        }

        echo '<div class="ks-ed-grid">';

        echo '<div class="ks-ed-main">';
        $this->render_basics($product);
        $this->render_images($product);
        $this->render_price($product, $input);
        (new ProductVariantsCard($this->stock))->render($product, $input);
        echo '</div>';

        echo '<aside class="ks-ed-side">';
        $this->render_publish($product);
        $this->render_stock($product);
        $this->render_assignment($product, $warehouses);
        $this->render_address($product);
        echo '</aside>';

        echo '</div></form>';
    }

    /**
     * Klebende Kopfleiste: Zurueck, Name, Status-Marken, Hilfetexte-Schalter, Speichern.
     *
     * @param array<string, mixed> $input
     */
    private function render_head(?Product $product, array $input): void
    {
        $editing = $product !== null;
        $name    = (string) ($this->get)('name', $product->name ?? '');
        $active  = (bool) ($this->get)('active', $editing ? $product->active : true);

        echo '<div class="ks-ed-head">';
        echo '<div class="ks-ed-head__title">';
        echo '<a class="ks-ed-head__back" href="' . esc_url(ProductsList::list_url()) . '">&larr; ' . esc_html__('Product', 'kontor-shop') . '</a>';
        echo '<h1 class="wp-heading-inline" data-ks-head-name data-empty="' . esc_attr__('New product', 'kontor-shop') . '">'
            . esc_html($name !== '' ? $name : __('New product', 'kontor-shop')) . '</h1>';
        echo '<span class="ks-ed-head__badges">';

        if ($editing && $product->archived) {
            echo Badge::html(__('Archived', 'kontor-shop'), Badge::BAD); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Badge liefert escaptes HTML
        } else {
            echo '<span class="ks-badge ks-badge--' . ($active ? 'ok' : 'muted') . '" data-ks-head-visible'
                . ' data-on="' . esc_attr__('Visible in shop', 'kontor-shop') . '" data-off="' . esc_attr__('Hidden', 'kontor-shop') . '">'
                . esc_html($active ? __('Visible in shop', 'kontor-shop') : __('Hidden', 'kontor-shop')) . '</span>';
        }

        /* translators: %d: number of variants */
        echo '<span class="ks-badge ks-badge--info" data-ks-head-variants data-label="' . esc_attr__('%d variants', 'kontor-shop') . '" hidden></span>';

        if ($editing) {
            $status = StockStatus::classify($this->stock->balance((int) $product->id), $product->min_stock);

            if ($status === StockStatus::OUT) {
                echo Badge::html(__('sold out', 'kontor-shop'), Badge::BAD); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Badge liefert escaptes HTML
            } elseif ($status === StockStatus::LOW) {
                echo Badge::html(__('Low stock', 'kontor-shop'), Badge::WARN); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Badge liefert escaptes HTML
            }
        }

        echo '<span class="ks-badge ks-badge--warn" data-ks-head-discontinued' . ((bool) ($this->get)('discontinued', $editing && $product->discontinued) ? '' : ' hidden') . '>'
            . esc_html__('Discontinued product', 'kontor-shop') . '</span>';
        echo '</span></div>';

        echo '<div class="ks-ed-head__actions">';
        echo '<span class="ks-dirty-flag" data-ks-dirty-flag hidden>' . esc_html__('Unsaved changes', 'kontor-shop') . '</span>';
        echo '<label class="ks-help-toggle"><input type="checkbox" data-ks-help-toggle /> ' . esc_html__('Help texts', 'kontor-shop') . '</label>';

        if ($editing && $product->active && !$product->archived && $product->slug !== '') {
            echo '<a class="button" href="' . esc_url(ProductUrl::of($product)) . '" target="_blank" rel="noopener">'
                . esc_html__('View in shop', 'kontor-shop') . ' <span aria-hidden="true">↗</span></a>';
        }

        echo '<button type="submit" class="button button-primary button-large">' . esc_html($editing ? __('Save', 'kontor-shop') : __('Add product', 'kontor-shop')) . '</button>';
        echo '</div></div>';
    }

    /** A) Grunddaten */
    private function render_basics(?Product $product): void
    {
        $get = $this->get;

        $this->card_open(__('Basics', 'kontor-shop'));
        echo Form::stacked( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form-Helfer liefern escaptes HTML
            __('Name', 'kontor-shop'),
            'ks-p-name',
            '<input type="text" id="ks-p-name" name="name" class="large-text" required maxlength="190" value="' . esc_attr((string) $get('name', $product->name ?? '')) . '" />',
            '',
            true,
            'ks-f--name'
        );
        echo Form::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form-Helfer liefern escaptes HTML
            Form::stacked(
                __('Product number (SKU)', 'kontor-shop'),
                'ks-p-sku',
                '<input type="text" id="ks-p-sku" name="sku" maxlength="64" value="' . esc_attr((string) $get('sku', $product->sku ?? '')) . '" />',
                __('Your own number for finding it again – only visible internally.', 'kontor-shop') // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form::stacked() escaped den Hilfetext
            ),
            Form::stacked( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form-Helfer liefern escaptes HTML
                __('Brand', 'kontor-shop'),
                'ks-p-brand',
                '<input type="text" id="ks-p-brand" name="brand" maxlength="120" value="' . esc_attr((string) $get('brand', $product->brand ?? '')) . '" />',
                __('Optional. Appears in the structured data for Google and AI search.', 'kontor-shop') // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form::stacked() escaped den Hilfetext
            ),
            Form::stacked( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form-Helfer liefern escaptes HTML
                __('GTIN / EAN', 'kontor-shop'),
                'ks-p-gtin',
                '<input type="text" id="ks-p-gtin" name="gtin" inputmode="numeric" maxlength="20" placeholder="' . esc_attr__('usually empty', 'kontor-shop') . '" value="' . esc_attr((string) $get('gtin', $product->gtin ?? '')) . '" />',
                __('Optional. 8, 12, 13, or 14 digits with a valid check digit – usually leave empty for your club’s own products.', 'kontor-shop') // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form::stacked() escaped den Hilfetext
            )
        );
        echo Form::stacked( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form-Helfer liefern escaptes HTML
            __('Description', 'kontor-shop'),
            'ks-p-desc',
            '<textarea id="ks-p-desc" name="description" class="large-text" rows="5">' . esc_textarea((string) $get('description', $product->description ?? '')) . '</textarea>',
            __('Shown with the product in the shop (plain text, no formatting).', 'kontor-shop') // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form::stacked() escaped den Hilfetext
        );
        $this->card_close();
    }

    /** B) Bilder: Hauptbild und weitere Bilder auf einer Flaeche, darunter der Alt-Text */
    private function render_images(?Product $product): void
    {
        $get     = $this->get;
        $gallery = array_map('intval', (array) $get('gallery_ids', $product !== null ? (new ProductRepository())->gallery_ids((int) $product->id) : []));

        $this->card_open(__('Images', 'kontor-shop'), '<span class="ks-card__meta">' . esc_html__('Drag to reorder · ★ makes an image the main image', 'kontor-shop') . '</span>');
        echo '<div class="ks-images">';
        echo '<div class="ks-images__main"><p class="ks-images__label">★ ' . esc_html__('Main image', 'kontor-shop') . Form::info( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form-Helfer liefern escaptes HTML
            __('Appears in the catalog, cart, and as the first image on the product page. Without an image, the shop shows the placeholder image (Settings → Design).', 'kontor-shop')
        ) . '</p><div data-ks-main-image>';
        MediaPicker::render('image_id', (int) $get('image_id', $product->image_id ?? 0), __('Choose image', 'kontor-shop'));
        echo '</div></div>';
        echo '<div class="ks-images__more"><p class="ks-images__label">' . esc_html__('More images', 'kontor-shop') . Form::info( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form-Helfer liefern escaptes HTML
            __('Optional. Additional views for the product page gallery. Change the order by dragging or with ‹ ›; ★ makes an image the main image.', 'kontor-shop')
        ) . '</p>';
        GalleryField::render($gallery);
        echo '</div></div>';
        echo Form::stacked( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form-Helfer liefern escaptes HTML
            __('Image description (alt text)', 'kontor-shop'),
            'ks-p-image-alt',
            '<input type="text" id="ks-p-image-alt" name="image_alt" class="large-text" maxlength="255" value="' . esc_attr((string) $get('image_alt', $product->image_alt ?? '')) . '" />',
            __('Briefly describe what the image shows (for screen readers, Google image search, and AI). Empty = the media library’s alt text or the product name.', 'kontor-shop') // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form::stacked() escaped den Hilfetext
        );
        $this->card_close();
    }

    /**
     * C) Preis & Steuer: Preis, Steuer, Gewicht in einer Zeile (mit Pro der
     * Mitgliedsrabatt als Umschalter mit Vorschau), Grundpreis eingeklappt.
     *
     * @param array<string, mixed> $input
     */
    private function render_price(?Product $product, array $input): void
    {
        $get      = $this->get;
        $editing  = $product !== null;
        $price    = (string) $get('price', $editing ? number_format($product->price, 2, '.', '') : '');

        $this->card_open(__('Price & tax', 'kontor-shop'));
        echo Form::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form-Helfer liefern escaptes HTML
            Form::stacked(
                __('Gross price', 'kontor-shop'),
                'ks-p-price',
                '<span class="ks-unit-input"><input type="text" inputmode="decimal" id="ks-p-price" name="price" required value="' . esc_attr($price) . '" /> €</span>',
                __('Retail price including VAT. For variants, this is the default price – each variant can have its own price.', 'kontor-shop'), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form::stacked() escaped den Hilfetext
                true
            ),
            $this->tax_field($product, $input), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tax_field() liefert escaptes HTML (Form::stacked)
            Form::stacked( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form-Helfer liefern escaptes HTML
                __('Weight', 'kontor-shop'),
                'ks-p-weight',
                '<span class="ks-unit-input"><input type="text" inputmode="decimal" id="ks-p-weight" name="weight" value="' . esc_attr((string) $get('weight', $editing && $product->weight !== null ? (string) $product->weight : '')) . '" /> kg</span>',
                __('Optional.', 'kontor-shop') // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form::stacked() escaped den Hilfetext
            )
        );

        /**
         * Aktion kontor_shop/admin/product_form/price_fields: eigene Felder in
         * der Karte "Preis & Steuer" (nach Preis/Steuer/Gewicht, vor dem
         * Grundpreis), z. B. Pro: Mitgliedsrabatt mit Vorschau. Fertiges,
         * escaptes HTML ausgeben (Form::stacked()). Gespeichert wird ueber
         * kontor_shop/admin/product/save_data.
         *
         * @param Product|null                   $product bearbeiteter Artikel, null = neu
         * @param callable(string, mixed): mixed $get     Eingabe nach einem Speicherfehler, sonst der Standardwert
         */
        do_action('kontor_shop/admin/product_form/price_fields', $product, $get);
        $this->render_unit_field($product, $input);
        $this->card_close();
    }

    /**
     * @param array<string, mixed> $input
     */
    private function tax_field(?Product $product, array $input): string
    {
        $tax_rates = array_map('floatval', (array) $this->settings->get('tax.rates', []));

        if ($tax_rates === [] && ($product === null || $product->tax_rate === null)) {
            return '';
        }

        if (array_key_exists('tax_rate', $input)) {
            $current = $input['tax_rate'] === '' ? null : (float) $input['tax_rate'];
        } else {
            $default_rate = (string) $this->settings->get('tax.default_rate', '');
            $current      = $product !== null ? $product->tax_rate : ($default_rate !== '' ? (float) $default_rate : null);
        }

        if ($current !== null && !in_array($current, $tax_rates, true)) {
            $tax_rates[] = $current; // gespeicherter Satz, der nicht mehr in der Liste steht
        }

        $select = '<select id="ks-p-tax" name="tax_rate"><option value="">' . esc_html__('no VAT (tax-exempt)', 'kontor-shop') . '</option>';

        foreach ($tax_rates as $rate) {
            $select .= '<option value="' . esc_attr((string) $rate) . '" ' . selected($current !== null && abs($current - $rate) < 0.001, true, false) . '>'
                . esc_html(number_format_i18n($rate, 2) . ' %') . '</option>';
        }

        $select .= '</select>';

        return Form::stacked(__('VAT', 'kontor-shop'), 'ks-p-tax', $select, __('You manage the available rates under Settings → VAT.', 'kontor-shop'));
    }

    /**
     * Inhalt + Einheit fuer den Grundpreis (Preis je kg/l/m/m²), eingeklappt,
     * solange kein Grundpreis eingestellt ist.
     *
     * @param array<string, mixed> $input
     */
    private function render_unit_field(?Product $product, array $input): void
    {
        $amount = array_key_exists('unit_amount', $input)
            ? (string) $input['unit_amount']
            : ($product?->unit_amount !== null ? rtrim(rtrim(number_format((float) $product->unit_amount, 3, ',', ''), '0'), ',') : '');
        $unit   = (string) ($input['unit_type'] ?? ($product->unit_type ?? ''));
        $select = '<select name="unit_type" id="ks-p-unit"><option value="">' . esc_html__('– no unit price –', 'kontor-shop') . '</option>';

        foreach (UnitPrice::units() as $value => $label) {
            $select .= '<option value="' . esc_attr($value) . '" ' . selected($unit, $value, false) . '>' . esc_html($label) . '</option>';
        }

        $select .= '</select>';

        echo '<p id="ks-unit-hint" class="ks-hint" hidden>' . esc_html__('Tip: Your variants are named after their contents (e.g. “0.5 l”). Choose the unit under “Unit price” – then the shop automatically calculates the required unit price for each variant.', 'kontor-shop') . '</p>';
        echo '<details class="ks-more" data-ks-unit-details' . ($unit !== '' || $amount !== '' ? ' open' : '') . '>';
        echo '<summary>' . esc_html__('Show unit price (only for goods sold by weight, volume, length, or area)', 'kontor-shop') . '</summary>';
        echo Form::stacked( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form-Helfer liefern escaptes HTML
            __('Contents', 'kontor-shop'),
            'ks-p-unit-amount',
            '<span class="ks-unit-input"><input type="text" inputmode="decimal" id="ks-p-unit-amount" name="unit_amount" value="' . esc_attr($amount) . '" /> ' . $select // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $select ist mit esc_attr/esc_html/selected gebaut
                . ' <span id="ks-unit-preview" class="ks-preview" aria-live="polite"></span></span>',
            __('For goods sold by weight, volume, length, or area (e.g. 500 g, 0.75 l): the shop then shows the unit price per kg / l / m / m² – this is legally required. Variants can have their own contents.', 'kontor-shop') // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form::stacked() escaped den Hilfetext
        );
        echo '</details>';
    }

    /** E) Veroeffentlichen */
    private function render_publish(?Product $product): void
    {
        $editing = $product !== null;
        $active  = (bool) ($this->get)('active', $editing ? $product->active : true);

        $this->card_open(__('Publish', 'kontor-shop'));
        echo '<label class="ks-switch"><span class="ks-switch__text">' . esc_html__('Visible in shop', 'kontor-shop') . '</span>'
            . '<input type="checkbox" name="active" value="1" data-ks-active-toggle ' . checked($active, true, false) . ' />'
            . '<span class="ks-switch__ui" aria-hidden="true"></span></label>';
        echo '<p class="ks-help">' . esc_html__('When unchecked, the product is hidden in the shop but stays saved here.', 'kontor-shop') . '</p>';

        if ($editing && $product->archived) {
            echo '<p class="ks-side-note">' . esc_html__('This product is archived and not visible in the shop, no matter how the switch is set.', 'kontor-shop') . '</p>';
        }

        echo '<button type="submit" class="button button-primary button-large ks-side-save">' . esc_html($editing ? __('Save', 'kontor-shop') : __('Add product', 'kontor-shop')) . '</button>';

        if (!$editing) {
            echo '<button type="submit" name="save_and_new" value="1" class="button ks-side-save">' . esc_html__('Create & add another', 'kontor-shop') . '</button>';
        } else {
            $links = ['<a href="' . esc_url(ProductsPage::action_url('duplicate', (int) $product->id)) . '">' . esc_html__('Duplicate', 'kontor-shop') . '</a>'];

            /**
             * Filter kontor_shop/admin/product/links: weitere Links unter dem
             * Speichern-Knopf eines bestehenden Artikels (z. B. "Change log").
             * Standard: keine.
             *
             * @param array<int, array{0: string, 1: string}> $extra   Liste aus [Beschriftung, URL]
             * @param Product                                 $product der Artikel
             */
            foreach ((array) apply_filters('kontor_shop/admin/product/links', [], $product) as $extra) {
                if (is_array($extra) && isset($extra[0], $extra[1]) && (string) $extra[0] !== '' && (string) $extra[1] !== '') {
                    $links[] = '<a href="' . esc_url((string) $extra[1]) . '">' . esc_html((string) $extra[0]) . '</a>';
                }
            }

            $links[] = $product->archived
                ? '<a href="' . esc_url(ProductsPage::action_url('restore', (int) $product->id)) . '">' . esc_html__('Restore', 'kontor-shop') . '</a>'
                : '<a class="ks-link-bad" href="' . esc_url(ProductsPage::action_url('archive', (int) $product->id)) . '">' . esc_html__('Archive', 'kontor-shop') . '</a>';

            echo '<p class="ks-side-links">' . implode('', $links) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $links sind mit esc_url/esc_html gebaut
        }

        $this->card_close();
    }

    /** F) Bestand */
    private function render_stock(?Product $product): void
    {
        $get       = $this->get;
        $editing   = $product !== null;
        $stock_url = $editing ? StockPage::url(['product_id' => (int) $product->id]) : '';

        $this->card_open(
            __('Stock', 'kontor-shop'),
            $editing ? '<a href="' . esc_url($stock_url) . '">' . esc_html__('Book / history', 'kontor-shop') . ' &rarr;</a>' : ''
        );

        if ($editing) {
            $this->render_stock_summary($product);
        } else {
            echo '<div data-ks-simple-only>' . Form::stacked( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form-Helfer liefern escaptes HTML
                __('Initial stock', 'kontor-shop'),
                'ks-p-initial',
                '<input type="number" id="ks-p-initial" name="initial_stock" class="small-text" min="0" step="1" value="' . esc_attr((string) $get('initial_stock', '0')) . '" />',
                __('Booked as a stock-in called “Initial stock”. Further movements later under “Stock”.', 'kontor-shop') // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form::stacked() escaped den Hilfetext
            ) . '</div>';
            echo '<p class="ks-side-note" data-ks-variants-only hidden>' . esc_html__('You enter the initial stock per variant in the variant table.', 'kontor-shop') . '</p>';
        }

        echo Form::stacked( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form-Helfer liefern escaptes HTML
            __('Minimum stock', 'kontor-shop'),
            'ks-p-min',
            '<input type="number" id="ks-p-min" name="min_stock" class="small-text" min="0" step="1" value="' . esc_attr((string) $get('min_stock', $editing && $product->min_stock !== null ? (string) $product->min_stock : '')) . '" />',
            __('At this stock level or below, the product appears as “low” (list, Dashboard). Empty = no warning.', 'kontor-shop') // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form::stacked() escaped den Hilfetext
        );

        $discontinued = (bool) $get('discontinued', $editing && $product->discontinued);

        echo '<div class="ks-f"><label class="ks-check"><input type="checkbox" name="discontinued" value="1" data-ks-discontinued ' . checked($discontinued, true, false) . ' /> '
            . esc_html__('Discontinued product (won’t be reordered)', 'kontor-shop')
            . Form::info(__('While stock lasts, the shop shows “While supplies last”. Once sold out, it shows “Sold out – won’t be reordered” and customers can’t sign up for notifications.', 'kontor-shop')) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form::info() liefert escaptes HTML
            . '</label></div>';

        $this->card_close();
    }

    /**
     * Grosse Bestandszahl mit Ampel, Reservierungen und ausverkauften Varianten.
     * Der Bestand wird ueber Buchungen gefuehrt und ist hier nicht editierbar.
     */
    private function render_stock_summary(Product $product): void
    {
        $id       = (int) $product->id;
        $balance  = $this->stock->balance($id);
        $status   = StockStatus::classify($balance, $product->min_stock);
        $reserved = (new ReservationStore())->reserved_for([$id])[$id] ?? 0;
        $badge    = match ($status) {
            StockStatus::OUT => Badge::html(__('sold out', 'kontor-shop'), Badge::BAD),
            StockStatus::LOW => Badge::html(__('low', 'kontor-shop'), Badge::WARN),
            default          => Badge::html(__('sufficient', 'kontor-shop'), Badge::OK),
        };

        echo '<div class="ks-stock-sum">';
        echo '<span class="ks-stock-sum__num ks-stock--' . esc_attr($status) . '">' . esc_html(number_format_i18n($balance)) . '</span>';
        echo '<span class="ks-stock-sum__unit">' . esc_html($product->has_variants() ? __('Total items', 'kontor-shop') : __('Items in stock', 'kontor-shop')) . ' ' . $badge . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $badge kommt aus Badge (escaptes HTML)

        $notes = [];

        if ($reserved > 0) {
            $notes[] = esc_html(sprintf(
                /* translators: %d: reserved quantity */
                _n('of which %d item is currently reserved in a checkout', 'of which %d items are currently reserved in a checkout', $reserved, 'kontor-shop'),
                $reserved
            ));
        }

        if ($product->has_variants()) {
            $balances = $this->stock->variant_balances([$id])[$id] ?? [];
            $active   = array_filter((new VariantRepository())->for_product($id), static fn ($variant): bool => $variant->active);
            $empty    = array_filter($active, static fn ($variant): bool => ($balances[(int) $variant->id] ?? 0) <= 0);

            if ($empty !== []) {
                $notes[] = '<span class="ks-stock--out">' . esc_html(sprintf(
                    /* translators: 1: number of sold-out variants, 2: number of active variants */
                    __('%1$d of %2$d variants sold out', 'kontor-shop'),
                    count($empty),
                    count($active)
                )) . '</span>';
            }
        }

        foreach ($notes as $note) {
            echo '<span class="ks-stock-sum__sub">' . $note . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- oben escapt
        }

        echo '</div>';
    }

    /**
     * G) Lager & Kategorien
     *
     * @param array<int, Warehouse> $warehouses
     */
    private function render_assignment(?Product $product, array $warehouses): void
    {
        $get     = $this->get;
        $editing = $product !== null;
        $repo    = new WarehouseRepository();
        // Waehlbar sind bearbeitbare Lager; das bisherige Lager des Artikels bleibt stehen (z. B. ein nur lesbares nach einem Downgrade).
        $offered = array_values(array_filter($warehouses, static fn (Warehouse $w): bool => $repo->is_editable($w) || ($product !== null && $w->id === $product->warehouse_id)));
        $wh_id   = (int) $get('warehouse_id', $product->warehouse_id ?? (count($offered) === 1 ? (int) $offered[0]->id : (int) $repo->primary()?->id));
        $options = '<option value="" data-ks-wh-class="ks-wh--0">' . esc_html__('– please choose –', 'kontor-shop') . '</option>';

        foreach ($offered as $warehouse) {
            $options .= '<option value="' . esc_attr((string) $warehouse->id) . '" data-ks-wh-class="' . esc_attr(Css::wh((int) $warehouse->id)) . '"' . selected($wh_id, (int) $warehouse->id, false) . '>'
                . esc_html($warehouse->name . ($warehouse->active ? '' : ' (' . __('inactive', 'kontor-shop') . ')')) . '</option>';
        }

        $this->card_open(__('Warehouse & categories', 'kontor-shop'));
        echo Form::stacked( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form-Helfer liefern escaptes HTML
            __('Warehouse', 'kontor-shop'),
            'ks-p-warehouse',
            '<span class="ks-wh-select"><span class="ks-wh-dot ' . esc_attr(Css::wh($wh_id)) . '" data-ks-wh-dot></span>'
                . '<select id="ks-p-warehouse" name="warehouse_id" required>' . $options . '</select></span>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $options ist mit esc_attr/esc_html/selected gebaut
            __('Which warehouse the product ships from and where stock bookings go.', 'kontor-shop'), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form::stacked() escaped den Hilfetext
            true
        );
        echo Form::stacked( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form-Helfer liefern escaptes HTML
            __('Main category', 'kontor-shop'),
            'ks-p-category',
            '<select id="ks-p-category" name="category_id">' . Options::categories((int) $get('category_id', $product->category_id ?? 0), __('– none –', 'kontor-shop')) . '</select>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Options liefert escaptes HTML
            __('Determines under which filter the product appears in the shop.', 'kontor-shop') // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form::stacked() escaped den Hilfetext
        );

        $extra = array_map('intval', (array) $get('category_ids', $editing ? (new ProductRepository())->category_ids((int) $product->id) : []));
        echo Form::stacked( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form-Helfer liefern escaptes HTML
            __('Also show under', 'kontor-shop'),
            '',
            $this->category_checklist($extra), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- category_checklist() liefert escaptes HTML
            __('Optional. The product also appears on these category pages.', 'kontor-shop') // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form::stacked() escaped den Hilfetext
        );
        $this->card_close();
    }

    /**
     * Weitere Kategorien als Haekchenliste (Unterkategorien eingerueckt).
     *
     * @param array<int, int> $selected
     */
    private function category_checklist(array $selected): string
    {
        $categories = (new CategoryRepository())->all();

        if ($categories === []) {
            return '<p class="ks-side-note">' . esc_html__('No categories yet.', 'kontor-shop') . '</p>';
        }

        $ids      = array_map(static fn (Category $category): int => (int) $category->id, $categories);
        $children = [];

        foreach ($categories as $category) {
            $parent = (int) ($category->parent_id ?? 0);
            $children[in_array($parent, $ids, true) ? $parent : 0][] = $category;
        }

        $html = '<div class="ks-catlist" role="group" aria-label="' . esc_attr__('More categories', 'kontor-shop') . '">';
        $walk = static function (int $parent, int $depth) use (&$walk, &$html, $children, $selected): void {
            foreach ($children[$parent] ?? [] as $category) {
                $html .= '<label class="' . Css::depth($depth) . '"><input type="checkbox" name="category_ids[]" value="' . esc_attr((string) $category->id) . '" '
                    . checked(in_array((int) $category->id, $selected, true), true, false) . ' /> '
                    . esc_html($category->name . ($category->active ? '' : ' (' . __('inactive', 'kontor-shop') . ')')) . '</label>';

                if ($depth < 5) {
                    $walk((int) $category->id, $depth + 1);
                }
            }
        };
        $walk(0, 0);

        return $html . '</div>';
    }

    /** H) Shop-Adresse */
    private function render_address(?Product $product): void
    {
        $editing = $product !== null;
        $slug    = (string) ($this->get)('slug', $product->slug ?? '');
        $url     = self::shop_url('%slug%', $editing ? (int) $product->id : 0);
        [$prefix, $suffix] = explode('%slug%', $url, 2) + ['', ''];

        if (!$editing) {
            $suffix = preg_replace('/-0\/$/', '-…/', $suffix) ?? $suffix;
        }

        $this->card_open(__('Shop address', 'kontor-shop'));
        echo Form::stacked( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form-Helfer liefern escaptes HTML
            __('Slug', 'kontor-shop'),
            'ks-p-slug',
            '<input type="text" id="ks-p-slug" name="slug" maxlength="80" value="' . esc_attr($slug) . '" />',
            $editing
                ? __('Part of the product address. Stays the same when you rename the product; if you change it here, the old address redirects automatically.', 'kontor-shop')
                : __('Leave empty: it’s generated from the name and never changed automatically afterward.', 'kontor-shop') // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form::stacked() escaped den Hilfetext
        );
        echo '<p class="ks-url" data-ks-slug-preview data-empty="' . esc_attr__('(from the name)', 'kontor-shop') . '">'
            . esc_html(preg_replace('#^https?://#', '', $prefix) ?? $prefix) . '<b>' . esc_html($slug !== '' ? $slug : __('(from the name)', 'kontor-shop')) . '</b>' . esc_html($suffix) . '</p>';
        $this->card_close();
    }

    private static function shop_url(string $slug, int $id): string
    {
        return home_url((new UrlScheme(SeoModule::base_path()))->product_path($slug, $id));
    }

    /**
     * @param string $aside fertiges HTML rechts in der Kartenueberschrift
     */
    private function card_open(string $title, string $aside = ''): void
    {
        echo '<section class="ks-card"><header class="ks-card__head"><h2>' . esc_html($title) . '</h2>' . $aside . '</header><div class="ks-card__body ks-card__body--stack">'; // phpcs:ignore WordPress.Security.EscapeOutput -- $aside ist fertiges HTML
    }

    private function card_close(): void
    {
        echo '</div></section>';
    }
}
