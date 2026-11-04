<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Media;

use Kontor\Shop\Core\Registerable;
use Kontor\Shop\Infrastructure\Media\ManagedImages;
use Kontor\Shop\Settings\Repository;

/**
 * REST-Anbindung des Bild-Uploads:
 *  - markiert Uploads, die das Bildfeld mit dem Parameter kontor_shop_upload
 *    schickt, als vom Plugin verwaltet (ManagedImages),
 *  - stellt zwei kleine Routen bereit, ueber die das Bildfeld sofort nachfragen
 *    kann, ob ein gerade hochgeladenes, wieder entferntes Bild geloescht oder
 *    behalten werden soll (jeweils mit denselben Schutzpruefungen wie die
 *    Aufraeumliste): DELETE /kontor-shop/v1/media/{id} und POST .../keep.
 */
final class MediaModule implements Registerable
{
    public const REST_NAMESPACE = 'kontor-shop/v1';

    public function __construct(private readonly Repository $settings)
    {
    }

    public function register(): void
    {
        add_action('rest_insert_attachment', [$this, 'flag_upload'], 10, 3);
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * @param \WP_Post         $attachment
     * @param \WP_REST_Request $request
     */
    public function flag_upload($attachment, $request, bool $creating): void
    {
        if ($creating && $request->get_param('kontor_shop_upload')) {
            ManagedImages::flag_upload((int) $attachment->ID);
        }
    }

    public function register_routes(): void
    {
        $args = [
            'id' => ['validate_callback' => static fn ($value): bool => is_numeric($value)],
        ];

        register_rest_route(self::REST_NAMESPACE, '/media/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [$this, 'delete'],
            'permission_callback' => [$this, 'can_manage_media'],
            'args'                => $args,
        ]);

        register_rest_route(self::REST_NAMESPACE, '/media/(?P<id>\d+)/keep', [
            'methods'             => 'POST',
            'callback'            => [$this, 'keep'],
            'permission_callback' => [$this, 'can_manage_media'],
            'args'                => $args,
        ]);
    }

    /**
     * @param \WP_REST_Request $request
     */
    public function can_manage_media($request): bool
    {
        return current_user_can('upload_files') && current_user_can('delete_post', (int) $request['id']);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function delete($request)
    {
        $id = (int) $request['id'];

        if (!ManagedImages::delete($id, $this->settings)) {
            return new \WP_Error(
                'kontor_shop_media_in_use',
                __('The image is still in use or wasn\'t uploaded through the shop, so it has been kept.', 'kontor-shop'),
                ['status' => 409]
            );
        }

        return new \WP_REST_Response(['deleted' => true, 'id' => $id]);
    }

    /**
     * @param \WP_REST_Request $request
     */
    public function keep($request): \WP_REST_Response
    {
        ManagedImages::keep((int) $request['id']);

        return new \WP_REST_Response(['kept' => true, 'id' => (int) $request['id']]);
    }
}
