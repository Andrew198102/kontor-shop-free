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
}
