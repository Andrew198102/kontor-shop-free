<?php

declare(strict_types=1);

namespace Kontor\Shop\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Welche Ausgabe laeuft: Free oder Pro. Pro\Pro::register() setzt den Filter
 * kontor_shop/edition auf 'pro' - nur mit gueltiger Lizenz. Der Kern fragt
 * hier statt bei Freemius, damit er ohne Pro-Code und ohne SDK-Aufruf
 * auskommt (z. B. Hinweis "Vereinsshop mit Kontor Shop" nur in Free).
 */
final class Edition
{
    public const FREE = 'free';

    public const PRO = 'pro';

    public static function current(): string
    {
        return apply_filters('kontor_shop/edition', self::FREE) === self::PRO ? self::PRO : self::FREE;
    }

    public static function is_pro(): bool
    {
        return self::current() === self::PRO;
    }
}
