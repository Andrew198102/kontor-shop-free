<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Media;

use Kontor\Shop\Domain\Catalog\VariantMatrix;
use Kontor\Shop\Infrastructure\Repositories\ProductRepository;
use Kontor\Shop\Infrastructure\Repositories\VariantRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Saubere Namen fuer Artikelbilder - damit in der Mediathek nicht
 * "IMG_4711" oder "file-AbC123" steht und man Bilder nach Artikelname oder
 * Artikelnummer findet. Laeuft nach jedem Speichern eines Artikels (Backend
 * und KI-Zugang, jeweils settle_images()).
 *
 *  - Nur Shop-Uploads (ManagedImages): Bilder, die jemand aus der Mediathek
 *    gewaehlt hat (Logo, Blogbilder ...), werden nie umbenannt.
 *  - Titel und Alternativtext folgen dem Artikel: "Vereinsschal (SCH-1)",
 *    Galerie "Vereinsschal (SCH-1) – Bild 2", Varianten "Hoodie M / Rot (HD-1-M)".
 *    Hat jemand den Titel/Alt-Text in der Mediathek selbst geaendert, bleibt
 *    der stehen (verglichen mit dem zuletzt gesetzten Wert).
 *  - Der Dateiname ("vereinsschal-sch-1.jpg") wird genau einmal vergeben, und
 *    nur fuer Bilder, die ab Version 2.9.3 hochgeladen wurden (META_RENAME):
 *    Bilder, die schon online sind, behalten ihre Adresse - sonst zeigten
 *    Seiten-Caches und Suchmaschinen auf tote Bilder.
 */
final class ProductImageNames
{
    /** Gesetzt beim Upload: der Dateiname darf beim ersten Zuordnen noch angepasst werden. */
    public const META_RENAME = '_kontor_shop_rename';

    /** Zuletzt gesetzter Titel/Alt-Text: {title, alt}. */
    public const META_NAMED = '_kontor_shop_named';

    private const MAX_SLUG = 80;

    public static function apply(int $product_id): void
    {
        $products = new ProductRepository();
        $product  = $products->find($product_id);

        if ($product === null || trim($product->name) === '') {
            return;
        }

        $variants = array_map(static fn ($variant): array => [
            'image_id' => (int) $variant->image_id,
            'label'    => VariantMatrix::label($variant->options),
            'sku'      => $variant->sku,
        ], (new VariantRepository())->for_product($product_id));

        foreach (self::plan($product->name, $product->sku, (int) ($product->image_id ?? 0), $products->gallery_ids($product_id), $variants) as $attachment_id => $name) {
            if (ManagedImages::is_managed($attachment_id) && wp_attachment_is_image($attachment_id)) {
                self::name($attachment_id, $name);
            }
        }
    }

    /**
     * Welches Bild welchen Namen bekommt - Hauptbild, Galerie in Reihenfolge,
     * dann Variantenbilder; ein Bild, das mehrfach vorkommt, nur beim ersten Mal.
     *
     * @param array<int, int> $gallery
     * @param array<int, array{image_id: int, label: string, sku: string}> $variants
     * @return array<int, array{title: string, alt: string, slug: string}>
     */
    public static function plan(string $name, string $sku, int $main_id, array $gallery, array $variants): array
    {
        $name = trim($name);
        $sku  = trim($sku);
        $plan = [];
        $ids  = array_values(array_filter(array_unique(array_merge([$main_id], $gallery))));

        foreach ($ids as $position => $id) {
            $title = self::title($name, $sku);

            if ($position > 0 || $main_id <= 0) {
                /* translators: 1: product name (and product number), 2: number of the image */
                $title = sprintf(__('%1$s – image %2$d', 'kontor-shop'), $title, $position + 1);
            }

            $plan[(int) $id] = [
                'title' => $title,
                'alt'   => $name,
                'slug'  => self::slug($name . ' ' . $sku . ($position > 0 || $main_id <= 0 ? ' ' . ($position + 1) : '')),
            ];
        }

        foreach ($variants as $variant) {
            $id = (int) $variant['image_id'];

            if ($id <= 0 || isset($plan[$id])) {
                continue;
            }

            $label     = trim($name . ' ' . $variant['label']);
            $code      = trim($variant['sku']) !== '' ? trim($variant['sku']) : $sku;
            $plan[$id] = ['title' => self::title($label, $code), 'alt' => $label, 'slug' => self::slug($label . ' ' . $code)];
        }

        return $plan;
    }

    private static function title(string $name, string $sku): string
    {
        return $sku !== '' ? $name . ' (' . $sku . ')' : $name;
    }

    public static function slug(string $text): string
    {
        $slug = sanitize_title($text);
        $slug = substr($slug, 0, self::MAX_SLUG);

        return trim($slug, '-') !== '' ? trim($slug, '-') : 'bild';
    }

    /**
     * @param array{title: string, alt: string, slug: string} $name
     */
    private static function name(int $attachment_id, array $name): void
    {
        $last  = get_post_meta($attachment_id, self::META_NAMED, true);
        $last  = is_array($last) ? $last : null;
        $post  = get_post($attachment_id);
        $alt   = (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
        $first = $last === null;

        if ($post === null) {
            return;
        }

        // Selbst geaenderte Titel/Alt-Texte bleiben stehen.
        $title_free = $first || $post->post_title === (string) ($last['title'] ?? '');
        $alt_free   = $alt === '' || $alt === (string) ($last['alt'] ?? '') || ($first && $alt === $post->post_title);
        $update     = [];

        if ($title_free && $post->post_title !== $name['title']) {
            $update['post_title'] = $name['title'];
            $update['post_name']  = $name['slug'];
        }

        if ($update !== []) {
            wp_update_post(['ID' => $attachment_id] + $update);
        }

        if ($alt_free && $alt !== $name['alt']) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $name['alt']);
        }

        update_post_meta($attachment_id, self::META_NAMED, [
            'title' => $title_free ? $name['title'] : (string) ($last['title'] ?? ''),
            'alt'   => $alt_free ? $name['alt'] : (string) ($last['alt'] ?? ''),
        ]);

        if (get_post_meta($attachment_id, self::META_RENAME, true) !== '') {
            delete_post_meta($attachment_id, self::META_RENAME);
            self::rename_file($attachment_id, $name['slug']);
        }
    }

    /**
     * Datei samt aller Groessen umbenennen (vor dem ersten Einsatz im Shop).
     * Schlaegt ein Schritt fehl, wird alles zurueckgedreht.
     */
    private static function rename_file(int $attachment_id, string $slug): void
    {
        $file = (string) get_attached_file($attachment_id);
        $meta = wp_get_attachment_metadata($attachment_id);

        if ($file === '' || !is_file($file) || !is_array($meta)) {
            return;
        }

        $dir   = dirname($file);
        $files = self::files_of($file, $meta);
        $old   = self::base_of($file, $meta);

        // Heisst schon so (auch "slug-2", wenn der Name vergeben war).
        if (preg_match('/^' . preg_quote($slug, '/') . '(-\d+)?$/', $old) === 1) {
            return;
        }

        $new = self::free_base($dir, $slug, $old, $files);

        if ($new === null) {
            return;
        }

        $done = [];

        foreach ($files as $name) {
            $target = self::rebase($name, $old, $new);

            if ($target === $name || !is_file($dir . '/' . $name)) {
                continue;
            }

            if (!@rename($dir . '/' . $name, $dir . '/' . $target)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Dateien in uploads, wie WordPress selbst
                foreach ($done as $from => $to) {
                    @rename($dir . '/' . $to, $dir . '/' . $from); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
                }

                return;
            }

            $done[$name] = $target;
        }

        update_attached_file($attachment_id, $dir . '/' . self::rebase(basename($file), $old, $new));
        wp_update_attachment_metadata($attachment_id, self::rebase_meta($meta, $old, $new));
    }

    /**
     * Gemeinsamer Wortstamm aller Dateien: "foto" fuer foto.jpg, foto-scaled.jpg, foto-300x200.jpg.
     *
     * @param array<string, mixed> $meta
     */
    public static function base_of(string $file, array $meta): string
    {
        $source = !empty($meta['original_image']) ? (string) $meta['original_image'] : basename($file);

        return (string) preg_replace('/-(scaled|rotated)$/', '', pathinfo($source, PATHINFO_FILENAME));
    }

    /**
     * Alle Dateinamen (ohne Ordner) eines Anhangs.
     *
     * @param array<string, mixed> $meta
     * @return array<int, string>
     */
    public static function files_of(string $file, array $meta): array
    {
        $files = [basename($file)];

        if (!empty($meta['original_image'])) {
            $files[] = (string) $meta['original_image'];
        }

        foreach ((array) ($meta['sizes'] ?? []) as $size) {
            if (is_array($size) && !empty($size['file'])) {
                $files[] = (string) $size['file'];
            }

            foreach ((array) ($size['sources'] ?? []) as $source) {
                if (is_array($source) && !empty($source['file'])) {
                    $files[] = basename((string) $source['file']);
                }
            }
        }

        return array_values(array_unique($files));
    }

    public static function rebase(string $name, string $old, string $new): string
    {
        return str_starts_with($name, $old) ? $new . substr($name, strlen($old)) : $name;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public static function rebase_meta(array $meta, string $old, string $new): array
    {
        if (!empty($meta['file'])) {
            $meta['file'] = ltrim(dirname((string) $meta['file']) . '/', './') . self::rebase(basename((string) $meta['file']), $old, $new);
        }

        if (!empty($meta['original_image'])) {
            $meta['original_image'] = self::rebase((string) $meta['original_image'], $old, $new);
        }

        foreach ((array) ($meta['sizes'] ?? []) as $key => $size) {
            if (is_array($size) && !empty($size['file'])) {
                $meta['sizes'][$key]['file'] = self::rebase((string) $size['file'], $old, $new);
            }

            foreach ((array) ($size['sources'] ?? []) as $mime => $source) {
                if (is_array($source) && !empty($source['file'])) {
                    $meta['sizes'][$key]['sources'][$mime]['file'] = self::rebase((string) $source['file'], $old, $new);
                }
            }
        }

        return $meta;
    }

    /**
     * Erster Stamm (slug, slug-2, slug-3 ...), unter dem keine der neuen Dateien schon existiert.
     *
     * @param array<int, string> $files
     */
    private static function free_base(string $dir, string $slug, string $old, array $files): ?string
    {
        for ($i = 1; $i <= 50; $i++) {
            $candidate = $i === 1 ? $slug : $slug . '-' . $i;
            $taken     = false;

            foreach ($files as $name) {
                if (file_exists($dir . '/' . self::rebase($name, $old, $candidate))) {
                    $taken = true;
                    break;
                }
            }

            if (!$taken) {
                return $candidate;
            }
        }

        return null;
    }
}
