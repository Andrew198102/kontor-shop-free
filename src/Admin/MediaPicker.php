<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin;

/**
 * Bildfeld mit Drag-and-drop-Upload (assets/admin/media-picker.js/.css).
 * Der Upload laeuft ueber die offizielle WordPress-REST-Schnittstelle
 * (POST /wp/v2/media) mit Fortschrittsbalken; zusaetzlich kann ein
 * vorhandenes Bild ueber die Mediathek gewaehlt werden. Gespeichert wird nur
 * die Attachment-ID im versteckten Feld - das Entfernen loest das Bild vom
 * Artikel, loescht die Datei aber nicht aus der Mediathek (sie kann anderswo
 * genutzt werden).
 */
final class MediaPicker
{
    public static function render(string $field_name, int $attachment_id, string $button_label): void
    {
        $has_image  = $attachment_id > 0 && wp_attachment_is_image($attachment_id);
        $can_upload = current_user_can('upload_files');

        echo '<div class="ks-media' . ($has_image ? ' has-image' : '') . '" data-can-upload="' . ($can_upload ? '1' : '0') . '">';
        echo '<input type="hidden" name="' . esc_attr($field_name) . '" value="' . esc_attr($has_image ? (string) $attachment_id : '') . '" class="ks-media__id" />';

        if (!$can_upload) {
            echo '<p class="description">' . esc_html__(
                'Your user is not allowed to upload files (missing "upload_files" capability).',
                'kontor-shop'
            ) . '</p></div>';

            return;
        }

        echo '<div class="ks-dropzone" role="button" tabindex="0" aria-label="' . esc_attr__('Upload image: drag a file here or press Enter to choose a file', 'kontor-shop') . '">';
        echo '<div class="ks-media__preview" aria-hidden="true">'
            . ($has_image ? wp_get_attachment_image($attachment_id, 'medium', false, ['class' => 'ks-media__img']) : '')
            . '</div>';
        echo '<div class="ks-dropzone__text">';
        echo '<strong class="ks-dropzone__title">' . esc_html__('Drag image here', 'kontor-shop') . '</strong> ';
        echo '<span>' . esc_html__('or click to choose', 'kontor-shop') . '</span>';
        echo '<small class="ks-dropzone__hint"></small>';
        echo '</div>';
        echo '<div class="ks-media__progress" hidden><div class="ks-media__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div></div>';
        echo '</div>';

        echo '<input type="file" class="ks-media__file" accept="image/jpeg,image/png,image/gif,image/webp" hidden />';
        echo '<p class="ks-media__status" role="status" aria-live="polite"></p>';
        echo '<p class="ks-media__ask" hidden><span class="ks-media__ask-text"></span> '
            . '<button type="button" class="button ks-media__ask-delete">' . esc_html__('Delete from media library', 'kontor-shop') . '</button> '
            . '<button type="button" class="button-link ks-media__ask-keep">' . esc_html__('Keep', 'kontor-shop') . '</button></p>';

        echo '<p class="ks-media__actions">';
        echo '<button type="button" class="button ks-media__library">' . esc_html($button_label ?: __('Choose from media library', 'kontor-shop')) . '</button> ';
        echo '<button type="button" class="button-link ks-media__cancel" hidden>' . esc_html__('Cancel upload', 'kontor-shop') . '</button>';
        echo '<button type="button" class="button-link ks-media__remove"' . ($has_image ? '' : ' hidden') . '>' . esc_html__('Remove image', 'kontor-shop') . '</button> ';
        echo '<button type="button" class="button-link ks-media__undo" hidden>' . esc_html__('Undo', 'kontor-shop') . '</button>';
        echo '</p>';
        echo '</div>';
    }

    /**
     * Daten fuer media-picker.js (REST-URL, Nonce, Limits, Texte).
     *
     * @return array<string, mixed>
     */
    public static function script_data(): array
    {
        $allowed = array_values(array_intersect(
            ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            array_values(get_allowed_mime_types())
        ));

        return [
            'restUrl'  => esc_url_raw(rest_url('wp/v2/media')),
            'nonce'    => wp_create_nonce('wp_rest'),
            'maxBytes' => (int) wp_max_upload_size(),
            'mediaUrl' => esc_url_raw(rest_url('kontor-shop/v1/media')),
            'types'    => $allowed,
            'i18n'     => [
                /* translators: %s: maximum upload size */
                'hint'         => __('JPG, PNG, WebP or GIF, max. %s', 'kontor-shop'),
                'replaceHint'  => __('Drag a new image here to replace it', 'kontor-shop'),
                /* translators: %d: upload progress in percent */
                'uploading'    => __('Uploading … %d %%', 'kontor-shop'),
                'processing'   => __('Processing … (creating thumbnails)', 'kontor-shop'),
                /* translators: %s: file name */
                'done'         => __('Done: %s was uploaded. Apply it with "Save".', 'kontor-shop'),
                'removed'      => __('Image removed. Apply with "Save".', 'kontor-shop'),
                'selected'     => __('Image selected. Apply with "Save".', 'kontor-shop'),
                'cancelled'    => __('Upload cancelled.', 'kontor-shop'),
                'busy'         => __('Please wait, the image is still uploading.', 'kontor-shop'),
                'badType'      => __('This file format is not supported. Allowed: JPG, PNG, WebP, GIF.', 'kontor-shop'),
                /* translators: 1: file size, 2: maximum upload size */
                'tooBig'       => __('The file is too large (%1$s). The maximum allowed is %2$s.', 'kontor-shop'),
                'network'      => __('Network error - please check your connection and try again.', 'kontor-shop'),
                'timeout'      => __('The upload took too long and was cancelled. Please try again.', 'kontor-shop'),
                'expired'      => __('Your session has expired or you don\'t have permission. Please reload the page and try again.', 'kontor-shop'),
                'restBlocked'  => __('The upload is not reachable (REST API blocked?). Use "Choose from media library" instead.', 'kontor-shop'),
                /* translators: %s: error message */
                'failed'       => __('Upload failed: %s', 'kontor-shop'),
                /* translators: %s: file name */
                'askText'      => __('The image "%s" you just uploaded is no longer used. Delete it from the media library?', 'kontor-shop'),
                'deleted'      => __('The image was deleted from the media library.', 'kontor-shop'),
                'kept'         => __('The image stays in the media library (cleanup list under Products).', 'kontor-shop'),
                'deleteFailed' => __('The image could not be deleted.', 'kontor-shop'),
                'libraryTitle' => __('Choose image', 'kontor-shop'),
                'previewAlt'   => __('Preview of the selected image', 'kontor-shop'),
            ],
        ];
    }
}
