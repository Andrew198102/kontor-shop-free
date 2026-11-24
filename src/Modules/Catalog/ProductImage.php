<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Catalog;

/**
 * Artikelbild fuer Katalog/Warenkorb/Checkout. Ohne Bild greift das im
 * Admin gewaehlte Platzhalterbild (design.placeholder_image_id), sonst ein
 * neutrales, inline eingebettetes SVG - ein Artikel ohne Foto sieht damit
 * nie "kaputt" aus.
 */
final class ProductImage
{
    public static function render(?int $image_id, string $alt, string $size, int $placeholder_id = 0, string $class = '', string $alt_override = ''): string
    {
        $class = trim('kontor-shop-img ' . $class);

        foreach ([$image_id, $placeholder_id] as $id) {
            if ($id === null || $id <= 0 || !wp_attachment_is_image($id)) {
                continue;
            }

            $is_placeholder = $id === $placeholder_id && $id !== $image_id;
            $attachment_alt = (string) get_post_meta($id, '_wp_attachment_image_alt', true);

            $html = wp_get_attachment_image($id, $size, false, [
                'class'    => $class . ($is_placeholder ? ' kontor-shop-img--placeholder' : ''),
                'alt'      => $is_placeholder ? '' : ($alt_override !== '' ? $alt_override : ($attachment_alt !== '' ? $attachment_alt : $alt)),
                'loading'  => 'lazy',
                'decoding' => 'async',
            ]);

            if ($html !== '') {
                return $html;
            }
        }

        return '<svg class="' . esc_attr($class) . ' kontor-shop-img--placeholder" viewBox="0 0 64 64" role="img" aria-label="" focusable="false">'
            . '<rect width="64" height="64" fill="currentColor" opacity=".08"/>'
            . '<path d="M14 46l12-14 9 10 6-7 9 11z" fill="currentColor" opacity=".25"/>'
            . '<circle cx="24" cy="22" r="5" fill="currentColor" opacity=".25"/></svg>';
    }

    /** Post-Meta am Bild: '1' = freigestellt (weisser Rand), '0' = Foto mit Hintergrund. */
    public const CUTOUT_META = '_kontor_shop_cutout';

    /**
     * Ist das Bild ein freigestelltes Produktfoto (Produkt auf Weiss)? Dann
     * zeigt die Karte es ganz und laesst das Weiss mit der Bildbuehne
     * verschmelzen; Fotos mit echtem Hintergrund fuellen die Buehne. Das
     * Ergebnis steht nach der ersten Pruefung am Bild (CUTOUT_META), ein neues
     * Foto ist ein neues Bild und wird neu geprueft.
     */
    public static function is_cutout(?int $image_id): bool
    {
        if ($image_id === null || $image_id <= 0) {
            return false;
        }

        $cached = get_post_meta($image_id, self::CUTOUT_META, true);

        if ($cached === '1' || $cached === '0') {
            return $cached === '1';
        }

        $file = self::analysis_file($image_id);

        if ($file === '') {
            return false;
        }

        $result = self::detect_cutout(@file_get_contents($file) ?: ''); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- lokale Bilddatei, Fehler = kein Freisteller

        if ($result !== null) {
            update_post_meta($image_id, self::CUTOUT_META, $result ? '1' : '0');
        }

        return (bool) $result;
    }

    /**
     * Randprobe: liegt (fast) der ganze aeussere Rand des Bildes auf reinem,
     * hellem Weiss? Geprueft wird ein schmaler Streifen an allen vier Seiten.
     *
     * @return bool|null null = Bild nicht lesbar (nicht merken)
     */
    public static function detect_cutout(string $bytes): ?bool
    {
        if ($bytes === '' || !function_exists('imagecreatefromstring')) {
            return null;
        }

        $image = @imagecreatefromstring($bytes); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- kaputte Datei = null

        if ($image === false) {
            return null;
        }

        $width  = imagesx($image);
        $height = imagesy($image);

        if ($width < 8 || $height < 8) {
            return null;
        }

        $band  = max(1, (int) round(min($width, $height) * 0.03));
        $step  = max(1, (int) floor(min($width, $height) / 60));
        $white = 0;
        $total = 0;

        $probe = static function (int $x, int $y) use ($image, &$white, &$total): void {
            $rgb = imagecolorat($image, $x, $y);
            $r   = ($rgb >> 16) & 0xFF;
            $g   = ($rgb >> 8) & 0xFF;
            $b   = $rgb & 0xFF;

            ++$total;

            if (min($r, $g, $b) >= 236 && max($r, $g, $b) - min($r, $g, $b) <= 18) {
                ++$white;
            }
        };

        for ($i = 0; $i < $band; $i += max(1, (int) ceil($band / 3))) {
            for ($x = 0; $x < $width; $x += $step) {
                $probe($x, $i);
                $probe($x, $height - 1 - $i);
            }

            for ($y = 0; $y < $height; $y += $step) {
                $probe($i, $y);
                $probe($width - 1 - $i, $y);
            }
        }

        unset($image);

        return $total > 0 && $white / $total >= 0.9;
    }

    /**
     * Post-Meta am Bild: wo das Produkt in einem freigestellten Foto liegt,
     * "x0,y0,x1,y1,ratio" (Anteile 0..1, ratio = Breite/Hoehe des Bildes);
     * 'none' = Produkt fuellt das Bild ohnehin oder nicht erkennbar.
     */
    public const BOX_META = '_kontor_shop_product_box';

    /**
     * Rahmen des Produkts in einem freigestellten Foto. Viele Fotos sind
     * Querformat mit viel Weiss rundherum - ganz gezeigt waere das Produkt
     * auf der hochformatigen Bildbuehne winzig. Mit dem Rahmen zoomt die Karte
     * auf das Produkt (shop.css: .has-box). Ergebnis steht am Bild (BOX_META).
     *
     * @return array{x0: float, y0: float, x1: float, y1: float, ratio: float}|null
     */
    public static function product_box(?int $image_id): ?array
    {
        if ($image_id === null || !self::is_cutout($image_id)) {
            return null;
        }

        $cached = (string) get_post_meta($image_id, self::BOX_META, true);

        if ($cached === 'none') {
            return null;
        }

        $parsed = self::parse_box($cached);

        if ($parsed !== null) {
            return $parsed;
        }

        $file = self::analysis_file($image_id);

        if ($file === '') {
            return null;
        }

        $box = self::detect_box(@file_get_contents($file) ?: ''); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- lokale Bilddatei, Fehler = kein Rahmen

        if ($box === false) {
            return null;
        }

        update_post_meta($image_id, self::BOX_META, $box === null ? 'none' : implode(',', array_map(static fn (float $v): string => (string) round($v, 4), $box)));

        return $box;
    }

    /**
     * Sucht das Produkt auf weissem Grund: Pixel, die deutlich dunkler oder
     * farbiger als Weiss sind. Einzelne Ausreisser (Staub, JPEG-Rauschen)
     * zaehlen nicht - eine Zeile/Spalte gehoert erst ab 1 % Produktpixeln dazu.
     *
     * @return array{x0: float, y0: float, x1: float, y1: float, ratio: float}|null|false
     *         null = Produkt fuellt das Bild (fast) ganz oder nichts gefunden, false = Bild nicht lesbar
     */
    public static function detect_box(string $bytes): array|null|false
    {
        if ($bytes === '' || !function_exists('imagecreatefromstring')) {
            return false;
        }

        $source = @imagecreatefromstring($bytes); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- kaputte Datei = false

        if ($source === false || imagesx($source) < 8 || imagesy($source) < 8) {
            return false;
        }

        $ratio = imagesx($source) / imagesy($source);
        $image = imagesx($source) > 240 ? imagescale($source, 240) : $source;
        unset($source);

        if ($image === false) {
            return false;
        }

        $width  = imagesx($image);
        $height = imagesy($image);
        $rows   = array_fill(0, $height, 0);
        $cols   = array_fill(0, $width, 0);

        for ($y = 0; $y < $height; ++$y) {
            for ($x = 0; $x < $width; ++$x) {
                $rgb = imagecolorat($image, $x, $y);
                $r   = ($rgb >> 16) & 0xFF;
                $g   = ($rgb >> 8) & 0xFF;
                $b   = $rgb & 0xFF;

                if (min($r, $g, $b) < 236 || max($r, $g, $b) - min($r, $g, $b) > 20) {
                    ++$rows[$y];
                    ++$cols[$x];
                }
            }
        }

        unset($image);

        $span = static function (array $counts, int $min): ?array {
            $first = null;
            $last  = null;

            foreach ($counts as $i => $count) {
                if ($count >= $min) {
                    $first ??= $i;
                    $last = $i;
                }
            }

            return $first === null ? null : [$first, $last + 1];
        };

        $ys = $span($rows, max(2, (int) ceil($width * 0.01)));
        $xs = $span($cols, max(2, (int) ceil($height * 0.01)));

        if ($xs === null || $ys === null) {
            return null;
        }

        $box = [
            'x0'    => $xs[0] / $width,
            'y0'    => $ys[0] / $height,
            'x1'    => $xs[1] / $width,
            'y1'    => $ys[1] / $height,
            'ratio' => $ratio,
        ];

        // Fuellt das Produkt das Bild ohnehin, bringt Zoomen nichts.
        if ($box['x1'] - $box['x0'] > 0.92 && $box['y1'] - $box['y0'] > 0.92) {
            return null;
        }

        return $box;
    }

    /**
     * CSS-Variablen fuer die Bildbuehne: Mitte und Groesse des Produkts im
     * Bild, Seitenverhaeltnis des Bildes.
     *
     * @param array{x0: float, y0: float, x1: float, y1: float, ratio: float}|null $box
     */
    public static function box_style(?array $box): string
    {
        if ($box === null) {
            return '';
        }

        $f = static fn (float $value): string => rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');

        return '--ks-bx:' . $f(($box['x0'] + $box['x1']) / 2) . ';--ks-by:' . $f(($box['y0'] + $box['y1']) / 2)
            . ';--ks-bw:' . $f(max(0.05, $box['x1'] - $box['x0'])) . ';--ks-bh:' . $f(max(0.05, $box['y1'] - $box['y0']))
            . ';--ks-ir:' . $f($box['ratio']) . ';';
    }

    /** @return array{x0: float, y0: float, x1: float, y1: float, ratio: float}|null */
    private static function parse_box(string $value): ?array
    {
        $parts = explode(',', $value);

        if (count($parts) !== 5) {
            return null;
        }

        $n = array_map('floatval', $parts);

        if ($n[0] < 0 || $n[1] < 0 || $n[2] > 1 || $n[3] > 1 || $n[0] >= $n[2] || $n[1] >= $n[3] || $n[4] <= 0) {
            return null;
        }

        return ['x0' => $n[0], 'y0' => $n[1], 'x1' => $n[2], 'y1' => $n[3], 'ratio' => $n[4]];
    }

    /** Kleinste brauchbare Bilddatei fuer die Randprobe (mittlere Groesse, sonst Original). */
    private static function analysis_file(int $image_id): string
    {
        $original = (string) get_attached_file($image_id);

        if ($original === '') {
            return '';
        }

        $medium = function_exists('image_get_intermediate_size') ? image_get_intermediate_size($image_id, 'medium') : false;

        if (is_array($medium) && !empty($medium['file'])) {
            $candidate = trailingslashit(dirname($original)) . $medium['file'];

            if (is_readable($candidate)) {
                return $candidate;
            }
        }

        return is_readable($original) ? $original : '';
    }
}
