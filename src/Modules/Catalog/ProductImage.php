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
