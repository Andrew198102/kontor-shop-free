<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Catalog;

/**
 * Was Galerie und Variantenauswahl im Browser von einem Bild brauchen: URLs
 * in drei Groessen, srcset und Alt-Text. null, wenn es kein (Bild-)Anhang ist.
 */
final class ImageData
{
    /**
     * @return array{id: int, url: string, thumb: string, full: string, srcset: string, alt: string}|null
     */
    public static function of(?int $attachment_id, string $fallback_alt = '', string $alt_override = ''): ?array
    {
        if ($attachment_id === null || $attachment_id <= 0 || !wp_attachment_is_image($attachment_id)) {
            return null;
        }

        $url = wp_get_attachment_image_url($attachment_id, 'large');

        if (!is_string($url) || $url === '') {
            return null;
        }

        $alt = $alt_override !== '' ? $alt_override : (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true);

        return [
            'id'     => $attachment_id,
            'url'    => $url,
            'thumb'  => (string) (wp_get_attachment_image_url($attachment_id, 'thumbnail') ?: $url),
            'full'   => (string) (wp_get_attachment_image_url($attachment_id, 'full') ?: $url),
            'srcset' => (string) (wp_get_attachment_image_srcset($attachment_id, 'large') ?: ''),
            'alt'    => $alt !== '' ? $alt : $fallback_alt,
        ];
    }
}
