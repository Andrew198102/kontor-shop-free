<?php

declare(strict_types=1);

namespace Kontor\Shop\Support;

/**
 * Sprachbewusste Seiten-IDs fuer Mehrsprachigkeits-Plugins. Im Backend waehlt
 * man eine Seite in der Hauptsprache; im Frontend wird hier die Uebersetzung
 * zur aktuellen Besuchersprache gesucht (Polylang: pll_get_post, WPML: Filter
 * wpml_object_id). Ohne Sprachplugin oder ohne Uebersetzung bleibt die
 * urspruengliche ID. Weitere Plugins koennen sich ueber den Filter
 * kontor_shop/translated_post_id einklinken.
 */
final class Translation
{
    public static function post_id(int $post_id, string $post_type = 'page'): int
    {
        if ($post_id <= 0) {
            return $post_id;
        }

        $translated = $post_id;

        if (function_exists('pll_get_post')) {
            $found      = pll_get_post($post_id);
            $translated = is_numeric($found) && (int) $found > 0 ? (int) $found : $post_id;
        } else {
            $found      = apply_filters('wpml_object_id', $post_id, $post_type, true);
            $translated = is_numeric($found) && (int) $found > 0 ? (int) $found : $post_id;
        }

        return (int) apply_filters('kontor_shop/translated_post_id', $translated, $post_id, $post_type);
    }
}
