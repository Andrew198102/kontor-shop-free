<?php

declare(strict_types=1);

namespace Kontor\Shop\Core;

final class I18n implements Registerable
{
    public function register(): void
    {
        add_action('init', [$this, 'load_textdomain']);
    }

    public function load_textdomain(): void
    {
        load_plugin_textdomain(
            'kontor-shop',
            false,
            dirname(KONTOR_SHOP_BASENAME) . '/languages'
        );
    }
}
