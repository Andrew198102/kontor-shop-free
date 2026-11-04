<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin\Ui;

use Kontor\Shop\Infrastructure\Repositories\WarehouseRepository;

/**
 * Klassen fuer Werte, die sonst als style="..." im HTML stuenden: Lagerfarbe,
 * Balkenbreite, Position einer Markierung, Spaltenzahl, Einruecktiefe. Die
 * passenden Regeln stehen in admin.css; die Lagerfarben erzeugt
 * warehouse_css() (als Inline-Stylesheet zu admin.css, Menu::enqueue_assets()).
 * So bleibt das Markup frei von Inline-Styles.
 */
final class Css
{
    public const NO_WAREHOUSE = 0;

    /** Lagerfarbe als Klasse (setzt --ks-wh), 0 = grau. */
    public static function wh(?int $warehouse_id): string
    {
        return 'ks-wh--' . max(0, (int) $warehouse_id);
    }

    /** Breite in 5-%-Schritten (ks-pct-0 ... ks-pct-100). */
    public static function pct(float $percent): string
    {
        return 'ks-pct-' . self::step($percent);
    }

    /** Linker Abstand in 5-%-Schritten (Markierung im Balken). */
    public static function at(float $percent): string
    {
        return 'ks-at-' . self::step($percent);
    }

    /** Spaltenzahl fuer Kachelreihen (1-8). */
    public static function cols(int $count): string
    {
        return 'ks-cols-' . max(1, min(8, $count));
    }

    /** Einruecktiefe in Baumlisten (0-6). */
    public static function depth(int $depth): string
    {
        return 'ks-depth-' . max(0, min(6, $depth));
    }

    public static function step(float $percent): int
    {
        return (int) (round(max(0.0, min(100.0, $percent)) / 5) * 5);
    }

    /**
     * Regeln fuer alle Lagerfarben (.ks-wh--3{--ks-wh:#2c5f3d}).
     */
    public static function warehouse_css(): string
    {
        $css = '.ks-wh--0{--ks-wh:#c3c4c7}';

        foreach ((new WarehouseRepository())->all() as $warehouse) {
            $color = sanitize_hex_color($warehouse->display_color());

            if (is_string($color) && $color !== '') {
                $css .= '.' . self::wh((int) $warehouse->id) . '{--ks-wh:' . $color . '}';
            }
        }

        return $css;
    }
}
