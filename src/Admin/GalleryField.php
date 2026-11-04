<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin;

/**
 * Zusatzbilder eines Artikels (Galerie): Dateien hineinziehen oder hochladen
 * (mehrere auf einmal) bzw. aus der Mediathek waehlen, per Ziehen oder Pfeil-Buttons
 * sortieren, entfernen oder zum Hauptbild machen (product-editor.js).
 * Gespeichert werden nur die Attachment-IDs in Reihenfolge (gallery_ids[]).
 */
final class GalleryField
{
    /**
     * @param array<int, int> $attachment_ids
     */
    public static function render(array $attachment_ids): void
    {
        echo '<div class="ks-gallery" data-ks-gallery>';
        echo '<input type="hidden" name="gallery_present" value="1" />';
        echo '<ul class="ks-gallery__list" data-ks-gallery-list>';

        foreach ($attachment_ids as $id) {
            if ($id > 0 && wp_attachment_is_image($id)) {
                echo self::item_html($id, (string) wp_get_attachment_image_url($id, 'thumbnail')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- item_html liefert escaptes HTML
            }
        }

        echo '</ul>';
        echo '<template data-ks-gallery-template>' . self::item_html(0, '') . '</template>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- item_html liefert escaptes HTML

        if (current_user_can('upload_files')) {
            echo '<div class="ks-gallery__drop" data-ks-gallery-drop>';
            echo '<span class="ks-gallery__drop-text">' . esc_html__('Drag images here (several at once are possible)', 'kontor-shop') . '</span> ';
            echo '<button type="button" class="button" data-ks-gallery-upload>' . esc_html__('Upload files', 'kontor-shop') . '</button> ';
            echo '<button type="button" class="button-link" data-ks-gallery-add>' . esc_html__('Choose from media library', 'kontor-shop') . '</button>';
            echo '<input type="file" accept="image/jpeg,image/png,image/gif,image/webp" multiple hidden data-ks-gallery-file />';
            echo '</div>';
            echo '<p class="ks-gallery__status" data-ks-gallery-status role="status" aria-live="polite"></p>';
        }

        echo '</div>';
    }

    private static function item_html(int $id, string $url): string
    {
        return '<li class="ks-gallery__item" draggable="true" data-id="' . esc_attr((string) $id) . '">'
            . '<img src="' . esc_url($url) . '" alt="" />'
            . '<input type="hidden" name="gallery_ids[]" value="' . esc_attr((string) $id) . '" />'
            . '<span class="ks-gallery__tools">'
            . '<button type="button" data-ks-gallery-move="-1" aria-label="' . esc_attr__('Move forward', 'kontor-shop') . '">&lsaquo;</button>'
            . '<button type="button" data-ks-gallery-main aria-label="' . esc_attr__('Use as main image', 'kontor-shop') . '" title="' . esc_attr__('Use as main image', 'kontor-shop') . '">&#9733;</button>'
            . '<button type="button" data-ks-gallery-remove aria-label="' . esc_attr__('Remove image', 'kontor-shop') . '" title="' . esc_attr__('Remove image', 'kontor-shop') . '">&times;</button>'
            . '<button type="button" data-ks-gallery-move="1" aria-label="' . esc_attr__('Move back', 'kontor-shop') . '">&rsaquo;</button>'
            . '</span></li>';
    }
}
