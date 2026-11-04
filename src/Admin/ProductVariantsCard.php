<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin;

use Kontor\Shop\Admin\Ui\Badge;
use Kontor\Shop\Admin\Ui\Form;
use Kontor\Shop\Domain\Catalog\Product;
use Kontor\Shop\Domain\Catalog\Variant;
use Kontor\Shop\Domain\Catalog\VariantMatrix;
use Kontor\Shop\Infrastructure\Repositories\StockRepository;
use Kontor\Shop\Infrastructure\Repositories\VariantRepository;

/**
 * Karte "Varianten" im Artikelformular: bis zu drei Merkmale (z. B. Größe,
 * Farbe, Inhalt) mit ihren Werten und darunter die Tabelle aller
 * Kombinationen - je Variante Artikelnummer, EAN, eigener Preis (leer =
 * Artikelpreis), Inhalt fuer den Grundpreis, Bild, Bestand und aktiv/inaktiv.
 * Die Tabelle baut product-editor.js aus den Merkmalen neu auf; massgeblich
 * ist aber der Server (VariantRepository::sync()), der beim Speichern
 * dieselben Kombinationen bildet.
 */
final class ProductVariantsCard
{
    /** Vorschlaege fuer Merkmalsnamen (datalist). */
    private const NAME_SUGGESTIONS = ['Größe', 'Farbe', 'Inhalt', 'Gewicht', 'Länge', 'Ausführung', 'Material'];

    public function __construct(private readonly StockRepository $stock)
    {
    }

    /**
     * @param array<string, mixed> $input Eingaben nach einem Fehler
     */
    public function render(?Product $product, array $input): void
    {
        $editing  = $product !== null;
        $variants = $editing ? (new VariantRepository())->for_product((int) $product->id) : [];
        $balances = $editing ? ($this->stock->variant_balances([(int) $product->id])[(int) $product->id] ?? []) : [];

        if (array_key_exists('variant_options', $input)) {
            $options = VariantMatrix::normalize($input['variant_options']);
            $enabled = !empty($input['has_variants']);
        } else {
            $options = $product->variant_options ?? [];
            $enabled = $options !== [];
        }

        $rows = $this->rows($options, $variants, is_array($input['variants'] ?? null) ? $input['variants'] : null);

        $help = __(
            'For example sizes (S, M, L, XL), colors, or fill quantities (0.5 l, 1 l). You can combine up to three attributes (size × color). Each combination has its own stock and can have its own price and image.',
            'kontor-shop'
        );

        echo '<section class="ks-card ks-variants-card' . ($enabled ? ' is-on' : '') . '" id="ks-variants" data-ks-variants><header class="ks-card__head"><h2>'
            . '<label class="ks-switch ks-switch--lead"><input type="checkbox" name="has_variants" value="1" data-ks-variants-toggle ' . checked($enabled, true, false) . ' />'
            . '<span class="ks-switch__ui" aria-hidden="true"></span><span class="ks-switch__text">' . esc_html__('Variants', 'kontor-shop') . '</span></label>'
            . Form::info($help) . '</h2>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form::info liefert escaptes HTML
            . '<span class="ks-card__meta ks-variants-card__off">' . esc_html__('Off: product without sizes, colors, etc.', 'kontor-shop') . '</span>'
            . '</header><div class="ks-card__body ks-card__body--stack">';
        echo '<p class="ks-help">' . esc_html($help) . '</p>';

        echo '<div class="ks-variants__setup" data-ks-variants-setup' . ($enabled ? '' : ' hidden') . '>';
        echo '<div class="ks-variants__options">';

        for ($i = 0; $i < VariantMatrix::MAX_OPTIONS; $i++) {
            $name   = (string) ($options[$i]['name'] ?? '');
            $values = implode(', ', $options[$i]['values'] ?? []);
            // Merkmal 3 erst auf Klick ("+ weiteres Merkmal"), solange es leer ist
            $spare  = $i > 1 && $name === '' && $values === '';

            echo '<div class="ks-option" data-ks-option' . ($spare ? ' data-ks-option-spare hidden' : '') . '>';
            echo '<input type="text" class="ks-option__name" name="' . esc_attr('variant_options[' . $i . '][name]') . '" list="ks-option-names" maxlength="100" value="' . esc_attr($name) . '" placeholder="'
                . esc_attr($i === 0 ? __('e.g. Size', 'kontor-shop') : ($i === 1 ? __('e.g. Color', 'kontor-shop') : __('Attribute', 'kontor-shop'))) . '" aria-label="' . esc_attr(sprintf(
                    /* translators: %d: attribute number */
                    __('Attribute %d', 'kontor-shop'),
                    $i + 1
                )) . '" />';
            echo '<input type="text" class="ks-option__values" name="' . esc_attr('variant_options[' . $i . '][values]') . '" value="' . esc_attr($values) . '" placeholder="'
                . esc_attr($i === 0 ? __('Values separated by commas, e.g. S, M, L, XL', 'kontor-shop') : ($i === 1 ? __('e.g. Red, Blue, Green', 'kontor-shop') : __('Values separated by commas', 'kontor-shop'))) . '" aria-label="' . esc_attr(sprintf(
                    /* translators: %d: attribute number */
                    __('Values of attribute %d (separated by commas)', 'kontor-shop'),
                    $i + 1
                )) . '" />';
            echo '<span class="ks-option__presets">' . esc_html__('Template:', 'kontor-shop') . ' '
                . '<button type="button" class="button-link" data-ks-preset="S, M, L, XL, XXL" data-ks-preset-name="' . esc_attr__('Size', 'kontor-shop') . '">' . esc_html__('Sizes', 'kontor-shop') . '</button> · '
                . '<button type="button" class="button-link" data-ks-preset="0,5 l, 1 l, 5 l" data-ks-preset-name="' . esc_attr__('Contents', 'kontor-shop') . '">' . esc_html__('Liters', 'kontor-shop') . '</button> · '
                . '<button type="button" class="button-link" data-ks-preset="250 g, 500 g, 1 kg" data-ks-preset-name="' . esc_attr__('Weight', 'kontor-shop') . '">' . esc_html__('Weight', 'kontor-shop') . '</button>'
                . '</span>';
            echo '</div>';
        }

        echo '<p class="ks-variants__add-option"><button type="button" class="button-link" data-ks-option-add hidden>+ ' . esc_html__('another attribute', 'kontor-shop') . '</button></p>';
        echo '</div><datalist id="ks-option-names">';

        foreach (self::NAME_SUGGESTIONS as $suggestion) {
            echo '<option value="' . esc_attr($suggestion) . '"></option>';
        }

        echo '</datalist>';

        echo '<p class="ks-variants__build"><button type="button" class="button" data-ks-variants-build>' . esc_html__('Update variant table', 'kontor-shop') . '</button> '
            . '<span class="description" data-ks-variants-count aria-live="polite"></span></p>';

        $has_gtin = array_filter($rows, static fn (array $row): bool => (string) $row['gtin'] !== '') !== [];

        // Sammelaktionen: Preis fuer eine Gruppe setzen, Artikelnummern erzeugen, EAN-Spalte
        echo '<div class="ks-variants__bulk" data-ks-variants-bulk>';
        echo '<label>' . esc_html__('Price for', 'kontor-shop') . ' <select data-ks-bulk-scope><option value="">' . esc_html__('all variants', 'kontor-shop') . '</option></select></label> ';
        echo '<input type="text" inputmode="decimal" class="small-text" data-ks-bulk-price placeholder="' . esc_attr__('e.g. 44.90', 'kontor-shop') . '" aria-label="' . esc_attr__('Price', 'kontor-shop') . '" /> €';
        echo '<button type="button" class="button" data-ks-bulk-apply>' . esc_html__('Apply', 'kontor-shop') . '</button>';
        echo '<button type="button" class="button-link" data-ks-bulk-clear>' . esc_html__('Clear prices (= product price)', 'kontor-shop') . '</button>';
        echo '<span class="ks-variants__bulk-sep" aria-hidden="true"></span>';
        echo '<button type="button" class="button" data-ks-bulk-sku title="' . esc_attr__('Fills empty product numbers from the product’s number and the values, e.g. PUL-M-RED', 'kontor-shop') . '">'
            . esc_html__('Generate product numbers', 'kontor-shop') . '</button>';
        echo '<label class="ks-variants__gtin-toggle"><input type="checkbox" data-ks-gtin-toggle ' . checked($has_gtin, true, false) . ' /> ' . esc_html__('EAN column', 'kontor-shop') . '</label>';
        echo '</div>';

        echo '<div class="ks-variants__table-wrap"><table class="widefat striped ks-variants__table' . ($has_gtin ? ' show-gtin' : '') . '" data-ks-variant-table><thead><tr>';
        echo '<th>' . esc_html__('Variant', 'kontor-shop') . '</th>';
        echo '<th>' . esc_html__('Product no.', 'kontor-shop') . '</th>';
        echo '<th class="ks-variants__gtin-col">' . esc_html__('EAN', 'kontor-shop') . '</th>';
        echo '<th>' . esc_html__('Price (€)', 'kontor-shop') . '</th>';
        echo '<th class="ks-variants__unit-col">' . esc_html__('Contents', 'kontor-shop') . ' <span data-ks-unit-label></span></th>';
        echo '<th>' . esc_html__('Image', 'kontor-shop') . '</th>';
        echo '<th>' . esc_html__('Stock', 'kontor-shop') . '</th>';
        echo '<th><label class="ks-variants__all"><input type="checkbox" data-ks-active-all checked aria-label="' . esc_attr__('Enable/disable all variants', 'kontor-shop') . '" /> '
            . esc_html__('Active', 'kontor-shop') . '</label></th>';
        echo '</tr></thead><tbody data-ks-variant-rows>';

        foreach ($rows as $index => $row) {
            echo $this->row_html((string) $index, $row, $product, $balances); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- row_html liefert escaptes HTML (Formularfelder, daher kein wp_kses_post)
        }

        echo '</tbody></table></div>';
        echo '<p class="ks-help">' . esc_html__(
            'Empty price = the product price applies. Empty contents = taken from the variant name (e.g. “0.5 l”), otherwise the product’s contents. Empty image = the product’s main image. Renaming a value (e.g. “Red” → “Dark red”) keeps stock, price, and image. If a combination is dropped that already has stock or orders, it is only deactivated.',
            'kontor-shop'
        ) . '</p>';

        echo '<template data-ks-variant-template>' . $this->row_html('__INDEX__', ['values' => [], 'label' => '', 'sku' => '', 'gtin' => '', 'price' => '', 'unit_amount' => '', 'image_id' => 0, 'active' => true, 'id' => 0, 'initial_stock' => ''], $product, []) . '</template>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- row_html liefert escaptes HTML (Formularfelder, daher kein wp_kses_post)

        $this->render_retired($variants, $options, $balances);
        $this->render_orphan_stock($product, $balances, $enabled);

        echo '</div></div></section>';
    }

    /**
     * Zeilen der Tabelle: aus den Merkmalen, gefuellt mit den gespeicherten
     * Varianten bzw. den Eingaben nach einem Fehler.
     *
     * @param array<int, array{name: string, values: array<int, string>}> $options
     * @param array<int, Variant> $variants
     * @param array<int|string, mixed>|null $submitted
     * @return array<int, array<string, mixed>>
     */
    private function rows(array $options, array $variants, ?array $submitted): array
    {
        $saved = [];

        foreach ($variants as $variant) {
            $saved[$variant->key()] ??= $variant;
        }

        $posted = [];

        foreach ($submitted ?? [] as $row) {
            if (is_array($row) && is_array($row['values'] ?? null)) {
                $posted[VariantMatrix::key(array_map('strval', $row['values']))] ??= $row;
            }
        }

        $rows = [];

        foreach (VariantMatrix::combinations($options) as $values) {
            $key     = VariantMatrix::key($values);
            $variant = $saved[$key] ?? null;
            $input   = $posted[$key] ?? null;

            $rows[] = [
                'values'        => $values,
                'label'         => VariantMatrix::label($values),
                'id'            => $variant?->id ?? (int) ($input['id'] ?? 0),
                'sku'           => (string) ($input['sku'] ?? $variant?->sku ?? ''),
                'gtin'          => (string) ($input['gtin'] ?? $variant?->gtin ?? ''),
                'price'         => (string) ($input['price'] ?? ($variant?->price !== null ? number_format((float) $variant->price, 2, '.', '') : '')),
                'unit_amount'   => (string) ($input['unit_amount'] ?? ($variant?->unit_amount !== null ? self::trim_number((float) $variant->unit_amount) : '')),
                'image_id'      => (int) ($input['image_id'] ?? $variant?->image_id ?? 0),
                'active'        => $input !== null ? !empty($input['active']) : ($variant?->active ?? true),
                'initial_stock' => (string) ($input['initial_stock'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, int> $balances
     */
    private function row_html(string $index, array $row, ?Product $product, array $balances): string
    {
        $name     = static fn (string $field): string => 'variants[' . $index . '][' . $field . ']';
        $id       = (int) $row['id'];
        $image_id = (int) $row['image_id'];
        $has_img  = $image_id > 0 && wp_attachment_is_image($image_id);

        $html = '<tr data-ks-variant-row data-key="' . esc_attr(VariantMatrix::key($row['values'])) . '">';
        $html .= '<td class="ks-variants__label"><input type="hidden" name="' . esc_attr($name('id')) . '" value="' . esc_attr((string) $id) . '" data-ks-variant-id-field />'
            . '<strong data-ks-variant-label>' . esc_html((string) $row['label']) . '</strong><span data-ks-variant-values>';

        foreach ($row['values'] as $value) {
            $html .= '<input type="hidden" name="' . esc_attr($name('values')) . '[]" value="' . esc_attr((string) $value) . '" />';
        }

        $html .= '</span></td>';
        $html .= '<td><input type="text" name="' . esc_attr($name('sku')) . '" value="' . esc_attr((string) $row['sku']) . '" maxlength="64" class="ks-variants__sku" /></td>';
        $html .= '<td class="ks-variants__gtin-col"><input type="text" name="' . esc_attr($name('gtin')) . '" value="' . esc_attr((string) $row['gtin']) . '" maxlength="20" inputmode="numeric" class="ks-variants__gtin" /></td>';
        $html .= '<td><input type="text" name="' . esc_attr($name('price')) . '" value="' . esc_attr((string) $row['price']) . '" inputmode="decimal" class="ks-variants__price" placeholder="'
            . esc_attr($product !== null ? number_format($product->price, 2, ',', '') : '') . '" data-ks-price-placeholder /></td>';
        $html .= '<td class="ks-variants__unit-col"><input type="text" name="' . esc_attr($name('unit_amount')) . '" value="' . esc_attr((string) $row['unit_amount']) . '" inputmode="decimal" class="ks-variants__amount" data-ks-amount /></td>';
        $html .= '<td><div class="ks-vimg' . ($has_img ? ' has-image' : '') . '" data-ks-vimg>'
            . '<input type="hidden" name="' . esc_attr($name('image_id')) . '" value="' . esc_attr($has_img ? (string) $image_id : '') . '" />'
            . '<button type="button" class="ks-vimg__pick" data-ks-vimg-pick aria-label="' . esc_attr__('Choose image for this variant', 'kontor-shop') . '">'
            . ($has_img ? wp_get_attachment_image($image_id, [40, 40]) : '<span aria-hidden="true">+</span>') . '</button>'
            . '<button type="button" class="ks-vimg__clear button-link" data-ks-vimg-clear aria-label="' . esc_attr__('Remove variant image', 'kontor-shop') . '"' . ($has_img ? '' : ' hidden') . '>&times;</button>'
            . '</div></td>';

        if ($id > 0 && $product !== null) {
            $html .= '<td>' . Badge::stock($balances[$id] ?? 0, null) . ' <a href="' . esc_url(StockPage::url(['product_id' => (int) $product->id, 'variant_id' => $id])) . '" class="ks-variants__book">'
                . esc_html__('book', 'kontor-shop') . '</a></td>';
        } else {
            $html .= '<td><input type="number" name="' . esc_attr($name('initial_stock')) . '" value="' . esc_attr((string) $row['initial_stock']) . '" min="0" step="1" class="small-text" placeholder="0" title="'
                . esc_attr__('Initial stock of this new variant', 'kontor-shop') . '" aria-label="' . esc_attr__('Initial stock', 'kontor-shop') . '" /></td>';
        }

        $html .= '<td><input type="hidden" name="' . esc_attr($name('active')) . '" value="0" /><input type="checkbox" name="' . esc_attr($name('active')) . '" value="1" '
            . checked((bool) $row['active'], true, false) . ' aria-label="' . esc_attr__('Offer variant in shop', 'kontor-shop') . '" /></td>';

        return $html . '</tr>';
    }

    /**
     * Kombinationen, die weggefallen sind, aber wegen Bestand/Bestellungen
     * erhalten bleiben (inaktiv).
     *
     * @param array<int, Variant> $variants
     * @param array<int, array{name: string, values: array<int, string>}> $options
     * @param array<int, int> $balances
     */
    private function render_retired(array $variants, array $options, array $balances): void
    {
        $current = [];

        foreach (VariantMatrix::combinations($options) as $values) {
            $current[VariantMatrix::key($values)] = true;
        }

        $retired = array_filter($variants, static fn (Variant $variant): bool => !isset($current[$variant->key()]));

        if ($retired === []) {
            return;
        }

        echo '<div class="ks-variants__retired"><p><strong>' . esc_html__('Former variants (inactive, with stock history or orders):', 'kontor-shop') . '</strong> ';
        $parts = [];

        foreach ($retired as $variant) {
            $balance = $balances[(int) $variant->id] ?? 0;
            $parts[] = esc_html($variant->label()) . ($balance !== 0 ? ' <span class="ks-stock--low">(' . esc_html(sprintf(
                /* translators: %d: stock */
                __('%d left', 'kontor-shop'),
                $balance
            )) . ')</span>' : '');
        }

        echo implode(', ', $parts) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $parts oben einzeln mit esc_html maskiert
    }

    /**
     * Bestand "ohne Variante" aus der Zeit, bevor der Artikel Varianten hatte:
     * zaehlt im Shop nicht mehr und sollte auf die Varianten umgebucht werden.
     *
     * @param array<int, int> $balances
     */
    private function render_orphan_stock(?Product $product, array $balances, bool $enabled): void
    {
        $orphan = $balances[0] ?? 0;

        if ($product === null || !$enabled || $orphan === 0) {
            return;
        }

        echo '<div class="notice notice-warning inline"><p>' . wp_kses_post(sprintf(
            /* translators: 1: quantity, 2: link to the stock page */
            __('There is still <strong>%1$d item of stock without a variant</strong> (from before variants existed). This stock isn’t offered in the shop – please book it out under %2$s and book it in for the matching variants.', 'kontor-shop'),
            $orphan,
            '<a href="' . esc_url(StockPage::url(['product_id' => (int) $product->id])) . '">' . esc_html__('Stock', 'kontor-shop') . '</a>'
        )) . '</p></div>';
    }

    private static function trim_number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
