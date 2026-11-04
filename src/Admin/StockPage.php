<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin;

use Kontor\Shop\Admin\Ui\Badge;
use Kontor\Shop\Admin\Ui\Flash;
use Kontor\Shop\Admin\Ui\ListParams;
use Kontor\Shop\Admin\Ui\Pagination;
use Kontor\Shop\Support\Csv;
use Kontor\Shop\Core\Capabilities;
use Kontor\Shop\Domain\Catalog\Product;
use Kontor\Shop\Domain\Catalog\Variant;
use Kontor\Shop\Domain\Stock\MovementType;
use Kontor\Shop\Domain\Stock\StockLedger;
use Kontor\Shop\Infrastructure\Logging\Logger;
use Kontor\Shop\Infrastructure\Repositories\CategoryRepository;
use Kontor\Shop\Infrastructure\Repositories\ProductRepository;
use Kontor\Shop\Infrastructure\Repositories\StockRepository;
use Kontor\Shop\Infrastructure\Repositories\VariantRepository;
use Kontor\Shop\Infrastructure\Repositories\WarehouseRepository;

/**
 * Bestand (Abschnitt 7/10, Ausbau docs/PLAN_bestandsseite.md): Router und
 * Buchungslogik. Die Uebersicht (Kennzahlen, Signale, Popover-Buchung,
 * Lieferung, Nachbestellliste) und die Inventur rendert StockOverview; hier
 * bleiben Rechte, Buchen (Ledger), die Formularaktionen, die CSV-Exporte
 * (Bestandsliste, Inventurliste) und je Artikel die Detailansicht mit Buchungsformular und Verlauf. Online-Verkauf
 * und Reservierung entstehen automatisch im Checkout und werden hier nicht
 * manuell angeboten (MovementType::manual()). Shop-Admin sieht/pflegt alle
 * Artikel, ein Lagerverwalter (ohne MANAGE) nur die seines eigenen Lagers -
 * Kassiere haben hier keinen Zugriff (Abschnitt 8). Artikel mit Varianten
 * werden je Variante gebucht; ein Restbestand "ohne Variante" (von vor den
 * Varianten) kann nur noch ausgebucht werden.
 */
final class StockPage
{
    public const SLUG          = 'kontor-shop-stock';
    public const NONCE_ACTION  = 'kontor_shop_stock_move';
    public const INVENTORY_ACTION = 'kontor_shop_export_inventory';
    private const HISTORY_PER_PAGE = 25;

    private ProductRepository $products;
    private WarehouseRepository $warehouses;
    private StockRepository $stock;
    private VariantRepository $variants;
    private StockOverview $overview;

    public function __construct()
    {
        $this->products   = new ProductRepository();
        $this->warehouses = new WarehouseRepository();
        $this->stock      = new StockRepository();
        $this->variants   = new VariantRepository();
        $this->overview   = new StockOverview($this->products, $this->warehouses, $this->stock, $this->variants);
    }

    /**
     * @param array<string, mixed> $args
     */
    public static function url(array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('admin.php'));
    }

    private ?string $error = null;

    /**
     * Buchung aus dem Detailformular ausfuehren (load-{hook}, vor jeder Ausgabe, damit umgeleitet werden kann).
     */
    public function handle_request(): void
    {
        if (!Capabilities::is_warehouse_manager()) {
            wp_die(esc_html__('No permission.', 'kontor-shop'));
        }

        $this->error = $this->handle_submit();
    }

    public function render(): void
    {
        if (!Capabilities::is_warehouse_manager()) {
            wp_die(esc_html__('No permission.', 'kontor-shop'));
        }

        $error      = $this->error;
        $product_id = ListParams::int('product_id');

        echo '<div class="wrap ks-wrap">';
        Flash::render();

        if ($error !== null) {
            echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
        }

        $product = $product_id > 0 ? $this->products->find($product_id) : null;

        if ($product !== null && $this->in_scope($product)) {
            $this->render_detail($product);
        } elseif (ListParams::key('mode') === 'count') {
            $this->overview->render_count($this->scope());
        } else {
            $this->overview->render($this->scope());
        }

        echo '</div>';
    }

    /**
     * Lager, die der aktuelle Benutzer bebuchen darf (null = alle).
     *
     * @return array<int, int>|null
     */
    private function scope(): ?array
    {
        return Capabilities::warehouse_scope();
    }

    private function in_scope(Product $product): bool
    {
        $scope = $this->scope();

        return !$product->archived && ($scope === null || in_array($product->warehouse_id, $scope, true));
    }

    /**
     * Auswahl-Optionen fuer die Bewegungsart. Bei "Korrektur" gibt es zwei
     * Eintraege (erhoehen/verringern), damit es kein zweites Feld braucht.
     *
     * @return array<string, array{label: string, options: array<string, string>}>
     */
    private function move_options(): array
    {
        $labels = MovementType::labels();
        $groups = [
            'in'   => ['label' => __('Stock in (+)', 'kontor-shop'), 'options' => []],
            'out'  => ['label' => __('Stock out (−)', 'kontor-shop'), 'options' => []],
            'corr' => ['label' => __('Correction (note required)', 'kontor-shop'), 'options' => []],
        ];

        foreach (MovementType::manual() as $type) {
            if ($type === MovementType::CORRECTION) {
                $groups['corr']['options']['correction:increase'] = __('Correction: increase stock (+)', 'kontor-shop');
                $groups['corr']['options']['correction:decrease'] = __('Correction: decrease stock (−)', 'kontor-shop');

                continue;
            }

            $delta = StockLedger::signed_delta($type, 1);
            $groups[$delta > 0 ? 'in' : 'out']['options'][$type] = $labels[$type] ?? $type;
        }

        return array_filter($groups, static fn (array $group): bool => $group['options'] !== []);
    }

    /**
     * Buchung fuer Artikel mit Varianten: eine Bewegungsart, je Variante ein
     * Mengenfeld - ein Wareneingang ueber alle Groessen ist so ein Formular.
     *
     * @param array<int, Variant> $variants
     * @param array<int, int> $balances
     */
    private function render_variant_booking(Product $product, array $variants, array $balances): void
    {
        $id      = (int) $product->id;
        $focus   = ListParams::int('variant_id');
        $orphan  = $balances[0] ?? 0;

        echo '<section class="ks-card ks-w-860"><h2>' . esc_html__('Book stock per variant', 'kontor-shop') . '</h2><div class="ks-card__body">';
        echo '<form method="post" action="' . esc_url(self::url(['product_id' => $id])) . '" data-ks-variant-booking>';
        wp_nonce_field(self::NONCE_ACTION, 'kontor_shop_stock_nonce');
        echo '<input type="hidden" name="product_id" value="' . esc_attr((string) $id) . '" />';

        echo '<div class="ks-field"><div class="ks-field__label"><label for="ks-s-move">' . esc_html__('What happened?', 'kontor-shop') . '</label></div><div class="ks-field__control">'
            . str_replace('<select name="move">', '<select name="move" id="ks-s-move">', $this->move_select()) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- move_select liefert escaptes HTML
            . '<p class="description">' . esc_html__('Enter quantities only for the variants concerned – empty fields are skipped.', 'kontor-shop') . '</p></div></div>';

        echo '<table class="wp-list-table widefat striped ks-table ks-booking-table"><thead><tr><th>' . esc_html__('Variant', 'kontor-shop') . '</th><th>'
            . esc_html__('Product no.', 'kontor-shop') . '</th><th class="ks-col-num">' . esc_html__('Stock', 'kontor-shop') . '</th><th class="ks-col-num">' . esc_html__('Quantity', 'kontor-shop') . '</th></tr></thead><tbody>';

        foreach ($variants as $variant) {
            $vid = (int) $variant->id;

            echo '<tr' . ($vid === $focus ? ' class="is-focus"' : '') . '><td>' . esc_html($variant->label()) . ($variant->active ? '' : ' ' . Badge::html(__('inactive', 'kontor-shop'), Badge::MUTED)) . '</td>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Badge liefert escaptes HTML
                . '<td>' . esc_html($variant->sku) . '</td>'
                . '<td class="ks-col-num">' . Badge::stock($balances[$vid] ?? 0, null) . '</td>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Badge liefert escaptes HTML
                . '<td class="ks-col-num"><input type="number" name="quantities[' . esc_attr((string) $vid) . ']" min="0" step="1" class="small-text" placeholder="0"'
                . ($vid === $focus ? ' autofocus' : '') . ' aria-label="' . esc_attr(sprintf(
                    /* translators: %s: variant */
                    __('Quantity for %s', 'kontor-shop'),
                    $variant->label()
                )) . '" /></td></tr>';
        }

        if ($orphan !== 0) {
            echo '<tr><td><em>' . esc_html__('without variant (from before variants – book out only)', 'kontor-shop') . '</em></td><td></td>'
                . '<td class="ks-col-num">' . Badge::stock($orphan, null) . '</td>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Badge liefert escaptes HTML
                . '<td class="ks-col-num"><input type="number" name="quantities[0]" min="0" step="1" class="small-text" placeholder="0" aria-label="' . esc_attr__('Quantity without variant', 'kontor-shop') . '" /></td></tr>';
        }

        echo '</tbody><tfoot><tr><th colspan="3">' . esc_html__('Total', 'kontor-shop') . '</th><th class="ks-col-num" data-ks-booking-sum>0</th></tr></tfoot></table>';

        echo '<div class="ks-field"><div class="ks-field__label"><label for="ks-s-note">' . esc_html__('Note', 'kontor-shop') . '</label></div><div class="ks-field__control">'
            . '<textarea id="ks-s-note" name="note" class="large-text" rows="2" placeholder="' . esc_attr__('e.g. delivery note 2026-117', 'kontor-shop') . '"></textarea>'
            . '<p class="description">' . esc_html__('Applies to all rows. Optional – required only for “Correction”.', 'kontor-shop') . '</p></div></div>';

        echo '<p><button type="submit" class="button button-primary">' . esc_html__('Save bookings', 'kontor-shop') . '</button></p>';
        echo '</form></div></section>';
    }

    private function move_select(string $selected = MovementType::PURCHASE): string
    {
        $html = '<select name="move">';

        foreach ($this->move_options() as $group) {
            $html .= '<optgroup label="' . esc_attr($group['label']) . '">';

            foreach ($group['options'] as $value => $label) {
                $html .= '<option value="' . esc_attr($value) . '"' . selected($selected, $value, false) . '>' . esc_html($label) . '</option>';
            }

            $html .= '</optgroup>';
        }

        return $html . '</select>';
    }

    /**
     * Eine Buchung pruefen und im Ledger erfassen (Formular und AJAX).
     *
     * @return array{ok: bool, message: string, balance: int}
     */
    private function book(int $product_id, string $move, int $quantity, string $note, int $variant_id = 0): array
    {
        $product = $product_id > 0 ? $this->products->find($product_id) : null;
        $balance = $product !== null ? $this->stock->balance($product_id, $variant_id) : 0;

        [$type, $direction] = array_pad(explode(':', $move, 2), 2, 'increase');

        if ($product === null || !$this->in_scope($product)) {
            return ['ok' => false, 'message' => __('Product not found or no permission.', 'kontor-shop'), 'balance' => $balance];
        }

        $variant = $variant_id > 0 ? $this->variants->find($variant_id) : null;

        if ($variant_id > 0 && ($variant === null || $variant->product_id !== $product_id)) {
            return ['ok' => false, 'message' => __('This variant does not belong to the product.', 'kontor-shop'), 'balance' => $balance];
        }

        if ($product->has_variants() && $variant === null) {
            $delta_preview = StockLedger::signed_delta($type, max(1, $quantity), $direction !== 'decrease');

            if ($delta_preview > 0 || $balance === 0) {
                return ['ok' => false, 'message' => __('Please select a variant – this product is booked per variant (e.g. size).', 'kontor-shop'), 'balance' => $balance];
            }
        }

        if (!in_array($type, MovementType::manual(), true) || $quantity <= 0 || $quantity > 1000000) {
            return ['ok' => false, 'message' => __('Please enter a movement type and a quantity greater than 0.', 'kontor-shop'), 'balance' => $balance];
        }

        if ($type === MovementType::CORRECTION && $note === '') {
            return ['ok' => false, 'message' => __('A note is required for a correction.', 'kontor-shop'), 'balance' => $balance];
        }

        $delta   = StockLedger::signed_delta($type, $quantity, $direction !== 'decrease');
        $user_id = get_current_user_id();

        try {
            $this->stock->record($product_id, $type, $delta, null, $note !== '' ? $note : null, $user_id ?: null, $variant_id);
        } catch (\Throwable $e) {
            $reference = Logger::exception($e, 'stock', 'Bestandsbewegung konnte nicht gespeichert werden', ['product_id' => $product_id]);

            return [
                'ok'      => false,
                /* translators: %s: error reference number */
                'message' => sprintf(__('The stock movement could not be saved (error code %s). Please try again.', 'kontor-shop'), $reference),
                'balance' => $balance,
            ];
        }

        $balance += $delta;
        $message  = sprintf(
            /* translators: 1: product name, 2: change, 3: new stock */
            __('%1$s: booked %2$s, new stock %3$d.', 'kontor-shop'),
            $product->name . ($variant !== null ? ' – ' . $variant->label() : ($product->has_variants() ? ' – ' . __('no variant', 'kontor-shop') : '')),
            ($delta > 0 ? '+' : '') . $delta,
            $balance
        );

        if ($balance < 0) {
            $message .= ' ' . __('Warning: stock is now negative.', 'kontor-shop');
        }

        return ['ok' => true, 'message' => $message, 'balance' => $balance];
    }

    /**
     * Mehrere Varianten in einem Formular buchen (gleiche Bewegungsart und
     * Notiz). Eingaben werden vorab geprueft, damit nicht nur ein Teil
     * gebucht wird.
     *
     * @param array<int|string, mixed> $raw variant_id => Menge
     */
    private function book_many(int $product_id, array $raw): ?string
    {
        $move       = sanitize_text_field(wp_unslash((string) ($_POST['move'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in StockPage::handle_submit
        $note       = sanitize_textarea_field(wp_unslash((string) ($_POST['note'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in StockPage::handle_submit
        $quantities = [];

        foreach ($raw as $variant_id => $quantity) {
            $quantity = abs((int) $quantity);

            if ($quantity > 0) {
                $quantities[(int) $variant_id] = $quantity;
            }
        }

        if ($quantities === []) {
            return __('Please enter a quantity for at least one variant.', 'kontor-shop');
        }

        [$type] = explode(':', $move, 2);

        if ($type === MovementType::CORRECTION && $note === '') {
            return __('A note is required for a correction.', 'kontor-shop');
        }

        $product = $this->products->find($product_id);

        if (isset($quantities[0]) && StockLedger::signed_delta($type, 1, !str_ends_with($move, ':decrease')) > 0) {
            return __('Stock without a variant can only be booked out – please book in under the variants.', 'kontor-shop');
        }

        foreach (array_keys($quantities) as $variant_id) {
            $variant = $variant_id > 0 ? $this->variants->find($variant_id) : null;

            if ($product === null || ($variant_id > 0 && ($variant === null || $variant->product_id !== $product_id))) {
                return __('Product or variant not found.', 'kontor-shop');
            }
        }

        $booked = [];

        foreach ($quantities as $variant_id => $quantity) {
            $result = $this->book($product_id, $move, $quantity, $note, $variant_id);

            if (!$result['ok']) {
                $prefix = $booked !== [] ? sprintf(
                    /* translators: %s: variants already booked */
                    __('Already booked: %s.', 'kontor-shop') . ' ',
                    implode(', ', $booked)
                ) : '';

                return $prefix . $result['message'];
            }

            $variant  = $variant_id > 0 ? $this->variants->find($variant_id) : null;
            $delta    = StockLedger::signed_delta($type, $quantity, !str_ends_with($move, ':decrease'));
            $booked[] = ($variant?->label() ?? __('no variant', 'kontor-shop')) . ' ' . ($delta > 0 ? '+' : '') . $delta;
        }

        Flash::redirect(self::url(['product_id' => $product_id]), sprintf(
            /* translators: 1: product name, 2: list of bookings, 3: new total stock */
            __('%1$s: booked – %2$s. New total stock %3$d.', 'kontor-shop'),
            (string) $product?->name,
            implode(', ', $booked),
            $this->stock->balance($product_id)
        ));
    }

    /**
     * Bewegungsarten fuer das Buchen-Popover: Zugang (+) und Abgang (−),
     * Korrektur jeweils am Ende mit fester Richtung.
     *
     * @return array{in: array<int, array{0: string, 1: string}>, out: array<int, array{0: string, 1: string}>, notes: array<int, string>}
     */
    public static function popover_types(): array
    {
        $labels = MovementType::labels();
        $in     = [];
        $out    = [];

        foreach (MovementType::manual() as $type) {
            if ($type === MovementType::CORRECTION) {
                continue;
            }

            if (StockLedger::signed_delta($type, 1) > 0) {
                $in[] = [$type, $labels[$type] ?? $type];
            } else {
                $out[] = [$type, $labels[$type] ?? $type];
            }
        }

        $in[]  = ['correction:increase', __('Correction +', 'kontor-shop')];
        $out[] = ['correction:decrease', __('Correction −', 'kontor-shop')];

        return ['in' => $in, 'out' => $out, 'notes' => ['correction:increase', 'correction:decrease']];
    }

    /**
     * Neu gerenderte Zeile (tbody) fuer die Uebersicht nach einer Aenderung.
     */
    private function row_payload(int $product_id): string
    {
        $product = $this->products->find($product_id);

        return $product !== null ? $this->overview->row_html($this->overview->row_for($product), $this->overview->show_warehouse($this->scope())) : '';
    }

    /**
     * Schnellbuchung aus der Uebersicht (AJAX, Buchen-Popover).
     */
    public function ajax_adjust(): void
    {
        check_ajax_referer(Ajax::NONCE, 'nonce');

        if (!Capabilities::is_warehouse_manager()) {
            wp_send_json_error(['message' => __('No permission.', 'kontor-shop')], 403);
        }

        $product_id = absint($_POST['product_id'] ?? 0);
        $result     = $this->book(
            $product_id,
            sanitize_text_field(wp_unslash((string) ($_POST['move'] ?? ''))),
            absint($_POST['quantity'] ?? 0),
            sanitize_textarea_field(wp_unslash((string) ($_POST['note'] ?? ''))),
            absint($_POST['variant_id'] ?? 0)
        );

        if (!$result['ok']) {
            wp_send_json_error(['message' => $result['message']], 422);
        }

        wp_send_json_success([
            'message' => $result['message'],
            'balance' => $this->stock->balance($product_id),
            'row'     => $this->row_payload($product_id),
        ]);
    }

    /**
     * Mindestbestand direkt in der Liste aendern (AJAX). Leer = keiner.
     */
    public function ajax_min_stock(): void
    {
        check_ajax_referer(Ajax::NONCE, 'nonce');

        if (!Capabilities::is_warehouse_manager()) {
            wp_send_json_error(['message' => __('No permission.', 'kontor-shop')], 403);
        }

        $product_id = absint($_POST['product_id'] ?? 0);
        $product    = $product_id > 0 ? $this->products->find($product_id) : null;

        if ($product === null || !$this->in_scope($product)) {
            wp_send_json_error(['message' => __('Product not found or no permission.', 'kontor-shop')], 404);
        }

        $raw = trim(sanitize_text_field(wp_unslash((string) ($_POST['min_stock'] ?? ''))));

        if ($raw !== '' && (!ctype_digit($raw) || (int) $raw > 1000000)) {
            wp_send_json_error(['message' => __('Please enter a whole number from 0 (empty = no minimum stock).', 'kontor-shop')], 422);
        }

        $min = $raw !== '' ? (int) $raw : null;
        $this->products->set_min_stock($product_id, $min);

        wp_send_json_success([
            'message' => $min !== null
                /* translators: 1: product, 2: minimum stock */
                ? sprintf(__('%1$s: minimum stock %2$d saved.', 'kontor-shop'), $product->name, $min)
                /* translators: %s: product */
                : sprintf(__('%s: minimum stock removed.', 'kontor-shop'), $product->name),
            'row'     => $this->row_payload($product_id),
        ]);
    }

    /**
     * Lieferung erfassen: mehrere Artikel/Varianten als Wareneingang mit einer
     * gemeinsamen Notiz. Alle Zeilen werden vorab geprueft, damit nicht nur ein
     * Teil der Lieferung gebucht wird.
     */
    private function receive_many(): ?string
    {
        $note  = sanitize_text_field(wp_unslash((string) ($_POST['note'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in StockPage::handle_submit
        $lines = [];

        foreach ((array) map_deep(wp_unslash($_POST['lines'] ?? []), 'absint') as $line) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in StockPage::handle_submit
            $line       = (array) $line;
            $product_id = absint($line['product'] ?? 0);
            $variant_id = absint($line['variant'] ?? 0);
            $quantity   = abs((int) ($line['qty'] ?? 0));

            if ($product_id <= 0 || $quantity <= 0) {
                continue;
            }

            $product = $this->products->find($product_id);
            $variant = $variant_id > 0 ? $this->variants->find($variant_id) : null;

            if ($product === null || !$this->in_scope($product)) {
                return __('A product in the delivery was not found or does not belong to your warehouse.', 'kontor-shop');
            }

            if (($variant_id > 0 && ($variant === null || $variant->product_id !== $product_id)) || ($product->has_variants() && $variant === null)) {
                /* translators: %s: product */
                return sprintf(__('%s: Please specify the variant (e.g. size).', 'kontor-shop'), $product->name);
            }

            if ($quantity > 1000000) {
                return __('The quantity is too large.', 'kontor-shop');
            }

            $key          = $product_id . ':' . $variant_id;
            $lines[$key]  = [$product_id, $variant_id, ($lines[$key][2] ?? 0) + $quantity];
        }

        if ($lines === []) {
            return __('Please enter a quantity in at least one row.', 'kontor-shop');
        }

        $pieces = 0;

        foreach ($lines as [$product_id, $variant_id, $quantity]) {
            $result = $this->book($product_id, MovementType::PURCHASE, $quantity, $note, $variant_id);

            if (!$result['ok']) {
                return $result['message'];
            }

            $pieces += $quantity;
        }

        Flash::redirect(self::url(), sprintf(
            /* translators: 1: items, 2: units */
            _n('Delivery booked: %1$d item, %2$d units.', 'Delivery booked: %1$d items, %2$d units.', count($lines), 'kontor-shop'),
            count($lines),
            $pieces
        ) . ($note !== '' ? ' (' . $note . ')' : ''));
    }

    /**
     * Inventur abschliessen: je gezaehlter Zeile die Differenz zum aktuellen
     * Soll im Regal (Bestand + verkauft, aber nicht versendet) als Korrektur
     * buchen. Das Soll wird hier frisch berechnet, falls inzwischen verkauft wurde.
     */
    private function count_commit(): ?string
    {
        $extra  = sanitize_text_field(wp_unslash((string) ($_POST['note'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in StockPage::handle_submit
        $note   = sprintf(
            /* translators: %s: date */
            __('Stock count %s', 'kontor-shop'),
            wp_date('d.m.Y')
        ) . ($extra !== '' ? ' – ' . $extra : '');
        $counts = [];

        foreach ((array) map_deep(wp_unslash($_POST['counts'] ?? []), 'sanitize_text_field') as $product_id => $by_variant) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in StockPage::handle_submit
            foreach ((array) $by_variant as $variant_id => $value) {
                $value = trim((string) $value);

                if ($value === '') {
                    continue;
                }

                if (!ctype_digit($value)) {
                    return __('Please enter whole numbers from 0 only.', 'kontor-shop');
                }

                $counts[] = [absint($product_id), absint($variant_id), (int) $value];
            }
        }

        if ($counts === []) {
            return __('Nothing has been counted yet.', 'kontor-shop');
        }

        $ids   = array_values(array_unique(array_column($counts, 0)));
        $bound = $this->stock->bound_in_orders($ids);
        $moves = [];

        foreach ($counts as [$product_id, $variant_id, $counted]) {
            $product = $this->products->find($product_id);
            $variant = $variant_id > 0 ? $this->variants->find($variant_id) : null;

            if ($product === null || !$this->in_scope($product) || ($variant_id > 0 && ($variant === null || $variant->product_id !== $product_id))) {
                return __('A counted product was not found or does not belong to your warehouse.', 'kontor-shop');
            }

            $soll = $this->stock->balance($product_id, $product->has_variants() ? $variant_id : null)
                + ($product->has_variants() ? ($bound[$product_id][$variant_id] ?? 0) : array_sum($bound[$product_id] ?? []));
            $diff = $counted - $soll;

            if ($diff !== 0) {
                $moves[] = [$product_id, $variant_id, $diff];
            }
        }

        $user_id = get_current_user_id();

        foreach ($moves as [$product_id, $variant_id, $diff]) {
            try {
                $this->stock->record($product_id, MovementType::CORRECTION, $diff, null, $note, $user_id ?: null, $variant_id);
            } catch (\Throwable $e) {
                $reference = Logger::exception($e, 'stock', 'Inventur-Korrektur konnte nicht gespeichert werden', ['product_id' => $product_id]);

                /* translators: %s: error reference number */
                return sprintf(__('The stock count could not be booked completely (error code %s). Corrections already booked are in the history.', 'kontor-shop'), $reference);
            }
        }

        Flash::redirect(self::url(), $moves === []
            ? sprintf(
                /* translators: %d: rows counted */
                _n('Stock count: %d row counted, everything matches – nothing booked.', 'Stock count: %d rows counted, everything matches – nothing booked.', count($counts), 'kontor-shop'),
                count($counts)
            )
            : sprintf(
                /* translators: 1: rows counted, 2: corrections, 3: note */
                _n('Stock count: %1$d row counted, %2$d difference(s) booked as a correction (“%3$s”).', 'Stock count: %1$d rows counted, %2$d difference(s) booked as a correction (“%3$s”).', count($counts), 'kontor-shop'),
                count($counts),
                count($moves),
                $note
            ));
    }

    /**
     * Mindestbestand fuer mehrere Artikel auf einmal (Sammelaktion).
     */
    private function min_bulk(): ?string
    {
        $ids = array_values(array_filter(array_map('absint', explode(',', sanitize_text_field(wp_unslash((string) ($_POST['product_ids'] ?? ''))))))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in StockPage::handle_submit
        $raw = trim(sanitize_text_field(wp_unslash((string) ($_POST['min_stock'] ?? '')))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in StockPage::handle_submit

        if ($ids === []) {
            return __('Please select products first.', 'kontor-shop');
        }

        if ($raw !== '' && !ctype_digit($raw)) {
            return __('Please enter a whole number from 0 (empty = no minimum stock).', 'kontor-shop');
        }

        $done = 0;

        foreach ($ids as $id) {
            $product = $this->products->find($id);

            if ($product !== null && $this->in_scope($product)) {
                $this->products->set_min_stock($id, $raw !== '' ? (int) $raw : null);
                $done++;
            }
        }

        Flash::redirect(wp_get_referer() ?: self::url(), sprintf(
            /* translators: %d: number of products */
            _n('Minimum stock saved for %d product.', 'Minimum stock saved for %d products.', $done, 'kontor-shop'),
            $done
        ));
    }

    /**
     * Inventurliste als CSV (admin-post, GET): Zaehlliste je Lager/Kategorie
     * mit Soll im Regal und leeren Spalten zum Eintragen. Lagerverwalter
     * bekommen nur ihre Lager (scope wird in der Abfrage erzwungen).
     */
    public function export_inventory(): void
    {
        if (!Capabilities::is_warehouse_manager()) {
            wp_die(esc_html__('No permission.', 'kontor-shop'), '', ['response' => 403]);
        }

        check_admin_referer(self::INVENTORY_ACTION);

        $scope        = $this->scope();
        $warehouse_id = max(0, ListParams::int('warehouse_id'));
        $category_id  = max(-1, ListParams::int('category_id'));

        if ($warehouse_id > 0 && $scope !== null && !in_array($warehouse_id, $scope, true)) {
            wp_die(esc_html__('No permission for this warehouse.', 'kontor-shop'), '', ['response' => 403]);
        }

        $warehouse = $warehouse_id > 0 ? $this->warehouses->find($warehouse_id) : null;
        $category  = $category_id > 0 ? (new CategoryRepository())->find($category_id) : null;
        $rows      = $this->overview->inventory_rows($scope, $warehouse_id, $category_id, ListParams::int('skip_empty') === 1);
        $user      = wp_get_current_user();
        $file      = 'kontor-shop-inventur' . ($warehouse !== null ? '_' . sanitize_title($warehouse->name) : '') . '_' . wp_date('Y-m-d_H-i') . '.csv';

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $file . '"');

        echo "\xEF\xBB\xBF"; // UTF-8-BOM, damit Excel Umlaute richtig liest.
        echo Csv::row([__('Stock count list', 'kontor-shop')]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV-Download (text/csv), Csv::row quotiert die Zellen, HTML-Escaping wuerde die Daten verfaelschen
        echo Csv::row([__('Warehouse', 'kontor-shop'), $warehouse !== null ? $warehouse->name : __('All warehouses', 'kontor-shop')]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV-Download (text/csv), Csv::row quotiert die Zellen, HTML-Escaping wuerde die Daten verfaelschen
        echo Csv::row([__('Category', 'kontor-shop'), $category !== null ? $category->name : ($category_id === -1 ? __('no category', 'kontor-shop') : __('All categories', 'kontor-shop'))]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV-Download (text/csv), Csv::row quotiert die Zellen, HTML-Escaping wuerde die Daten verfaelschen
        echo Csv::row([__('Stock (expected)', 'kontor-shop'), wp_date('d.m.Y H:i')]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV-Download (text/csv), Csv::row quotiert die Zellen, HTML-Escaping wuerde die Daten verfaelschen
        echo Csv::row([__('Created by', 'kontor-shop'), $user->display_name]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV-Download (text/csv), Csv::row quotiert die Zellen, HTML-Escaping wuerde die Daten verfaelschen
        echo Csv::row([__('Items', 'kontor-shop'), (string) count($rows)]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV-Download (text/csv), Csv::row quotiert die Zellen, HTML-Escaping wuerde die Daten verfaelschen
        echo Csv::row([]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV-Download (text/csv), Csv::row quotiert die Zellen, HTML-Escaping wuerde die Daten verfaelschen
        $header = [
            __('Warehouse', 'kontor-shop'), __('Category', 'kontor-shop'), __('Product', 'kontor-shop'), __('Variant', 'kontor-shop'),
            __('Product no.', 'kontor-shop'), __('EAN/GTIN', 'kontor-shop'), __('Expected on shelf', 'kontor-shop'),
            __('of which sold, not shipped', 'kontor-shop'), __('Counted', 'kontor-shop'), __('Remarks', 'kontor-shop'),
        ];
        echo Csv::row($header); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV-Download (text/csv), Csv::row quotiert die Zellen, HTML-Escaping wuerde die Daten verfaelschen

        foreach ($rows as $row) {
            echo Csv::row($row); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV-Download (text/csv), Csv::row quotiert die Zellen, HTML-Escaping wuerde die Daten verfaelschen
        }

        echo Csv::row([]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV-Download (text/csv), Csv::row quotiert die Zellen, HTML-Escaping wuerde die Daten verfaelschen
        echo Csv::row([__('Counted by', 'kontor-shop'), '', __('Date', 'kontor-shop'), '', __('Signature', 'kontor-shop')]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV-Download (text/csv), Csv::row quotiert die Zellen, HTML-Escaping wuerde die Daten verfaelschen

        exit;
    }

    /**
     * Formulare der Bestandsseite (POST, danach Umleitung).
     */
    private function handle_submit(): ?string
    {
        if (sanitize_text_field(wp_unslash((string) ($_SERVER['REQUEST_METHOD'] ?? ''))) !== 'POST' || !isset($_POST['kontor_shop_stock_nonce'])) {
            return null;
        }

        check_admin_referer(self::NONCE_ACTION, 'kontor_shop_stock_nonce');

        if (!Capabilities::is_warehouse_manager()) {
            wp_die(esc_html__('No permission.', 'kontor-shop'));
        }

        switch (sanitize_key((string) ($_POST['stock_action'] ?? ''))) {
            case 'receive':
                return $this->receive_many();
            case 'count':
                return $this->count_commit();
            case 'min_bulk':
                return $this->min_bulk();
        }

        $product_id = absint($_POST['product_id'] ?? 0);

        if (isset($_POST['quantities']) && is_array($_POST['quantities'])) {
            return $this->book_many($product_id, (array) map_deep(wp_unslash($_POST['quantities']), 'absint'));
        }

        $variant_id = absint($_POST['variant_id'] ?? 0);
        $result     = $this->book(
            $product_id,
            sanitize_text_field(wp_unslash((string) ($_POST['move'] ?? ''))),
            absint($_POST['quantity'] ?? 0),
            sanitize_textarea_field(wp_unslash((string) ($_POST['note'] ?? ''))),
            $variant_id
        );

        if (!$result['ok']) {
            return $result['message'];
        }

        Flash::redirect(self::url(['product_id' => $product_id] + ($variant_id > 0 ? ['variant_id' => $variant_id] : [])), $result['message']);
    }

    private function render_detail(Product $product): void
    {
        $id       = (int) $product->id;
        $balance  = $this->stock->balance($id);
        $total    = $this->stock->count_for_product($id);
        $page     = ListParams::page();
        $rows     = $this->stock->recent_for_product($id, self::HISTORY_PER_PAGE, ($page - 1) * self::HISTORY_PER_PAGE);
        $variants = $this->variants->for_product($id);
        $by_id    = [];

        foreach ($variants as $variant) {
            $by_id[(int) $variant->id] = $variant;
        }

        $variant_bal = $this->stock->variant_balances([$id])[$id] ?? [];
        $show_var    = $product->has_variants() || $variants !== [];

        echo '<h1 class="wp-heading-inline">' . esc_html(sprintf(
            /* translators: %s: product name */
            __('Stock: %s', 'kontor-shop'),
            $product->name
        )) . '</h1> ';
        echo '<a href="' . esc_url(self::url()) . '" class="page-title-action">' . esc_html__('Back to dashboard', 'kontor-shop') . '</a><hr class="wp-header-end" />';

        $min_note = '';

        if ($product->min_stock !== null) {
            $min_note = ' <span class="ks-muted">' . esc_html(sprintf(
                /* translators: %d: minimum stock */
                __('(minimum stock %d)', 'kontor-shop'),
                $product->min_stock
            )) . '</span>';
        }

        echo '<p>' . esc_html($show_var ? __('Total stock (all variants):', 'kontor-shop') : __('Current stock:', 'kontor-shop')) . ' <span class="ks-big">' . Badge::stock($balance, $product->min_stock) . '</span>' . $min_note . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Badge liefert escaptes HTML, $min_note ist oben escaped

        if ($show_var) {
            $this->render_variant_booking($product, $variants, $variant_bal);
        } else {
            echo '<section class="ks-card ks-w-760"><h2>' . esc_html__('Record movement', 'kontor-shop') . '</h2><div class="ks-card__body">';
            echo '<form method="post" action="' . esc_url(self::url(['product_id' => $id])) . '">';
            wp_nonce_field(self::NONCE_ACTION, 'kontor_shop_stock_nonce');
            echo '<input type="hidden" name="product_id" value="' . esc_attr((string) $id) . '" />';

            echo '<div class="ks-field"><div class="ks-field__label"><label for="ks-s-move">' . esc_html__('What happened?', 'kontor-shop') . '</label></div><div class="ks-field__control">'
                . str_replace('<select name="move">', '<select name="move" id="ks-s-move">', $this->move_select()) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- move_select liefert escaptes HTML
            echo '<div class="ks-field"><div class="ks-field__label"><label for="ks-s-qty">' . esc_html__('Quantity', 'kontor-shop') . '</label></div><div class="ks-field__control">'
                . '<input type="number" id="ks-s-qty" name="quantity" class="small-text" min="1" step="1" value="1" required /></div></div>';
            echo '<div class="ks-field"><div class="ks-field__label"><label for="ks-s-note">' . esc_html__('Note', 'kontor-shop') . '</label></div><div class="ks-field__control">'
                . '<textarea id="ks-s-note" name="note" class="large-text" rows="2"></textarea>'
                . '<p class="description">' . esc_html__('Optional – required only for “Correction” (so it stays traceable later why a correction was made).', 'kontor-shop') . '</p></div></div>';

            echo '<p><button type="submit" class="button button-primary">' . esc_html__('Save movement', 'kontor-shop') . '</button></p>';
            echo '</form></div></section>';
        }

        echo '<h2>' . esc_html__('History', 'kontor-shop') . '</h2>';
        echo '<table class="wp-list-table widefat fixed striped ks-table ks-w-1000"><thead><tr>';
        echo '<th>' . esc_html__('Date', 'kontor-shop') . '</th><th>' . esc_html__('Type', 'kontor-shop') . '</th>'
            . ($show_var ? '<th>' . esc_html__('Variant', 'kontor-shop') . '</th>' : '')
            . '<th class="ks-col-num">' . esc_html__('Change', 'kontor-shop') . '</th>';
        echo '<th>' . esc_html__('Note', 'kontor-shop') . '</th><th>' . esc_html__('Recorded by', 'kontor-shop') . '</th></tr></thead><tbody>';

        foreach ($rows as $movement) {
            $user      = !empty($movement['user_id']) ? get_userdata((int) $movement['user_id']) : false;
            $delta     = (int) $movement['quantity_delta'];
            $order_ref = !empty($movement['order_id'])
                ? ' <a href="' . esc_url(admin_url('admin.php?page=kontor-shop-orders&order=' . (int) $movement['order_id'])) . '">' . esc_html(sprintf(
                /* translators: %d: order number */
                __('Order #%d', 'kontor-shop'),
                (int) $movement['order_id']
            )) . '</a>'
                : '';

            echo '<tr>';
            echo '<td>' . esc_html(mysql2date('d.m.Y H:i', (string) $movement['created_at'])) . '</td>';
            echo '<td>' . esc_html(MovementType::labels()[(string) $movement['type']] ?? (string) $movement['type']) . $order_ref . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $order_ref ist oben escaped

            if ($show_var) {
                $variant_id = (int) ($movement['variant_id'] ?? 0);

                echo '<td>' . esc_html($variant_id > 0 ? (isset($by_id[$variant_id]) ? $by_id[$variant_id]->label() : '#' . $variant_id) : '—') . '</td>';
            }
            echo '<td class="ks-col-num"><strong class="' . ($delta < 0 ? 'ks-text-bad' : 'ks-text-ok') . '">' . esc_html(($delta > 0 ? '+' : '') . $delta) . '</strong></td>';
            echo '<td>' . esc_html((string) ($movement['note'] ?? '')) . '</td>';
            echo '<td>' . esc_html($user ? $user->display_name : '—') . '</td>';
            echo '</tr>';
        }

        if ($rows === []) {
            echo '<tr><td colspan="' . ($show_var ? 6 : 5) . '">' . esc_html__('No movements recorded yet.', 'kontor-shop') . '</td></tr>';
        }

        echo '</tbody></table>';

        echo '<div class="tablenav bottom">';
        Pagination::render($total, $page, self::HISTORY_PER_PAGE, ['page' => self::SLUG], ['product_id' => $id]);
        echo '<br class="clear" /></div>';
    }
}
