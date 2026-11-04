<?php

declare(strict_types=1);

namespace Kontor\Shop\Core;

/**
 * Minimaler PSR-4-Autoloader fuer den Namespace Kontor\Shop\ -> src/.
 * Bewusst ohne Composer, damit das Plugin ohne Build-Schritt einsetzbar ist.
 * Ausnahme: Kontor\Shop\Pro\ liegt in src/Pro__premium_only/ - den Ordner
 * entfernt Freemius beim Bau der Free-Version (src/freemius.php).
 */
final class Autoloader
{
    private const PREFIX = 'Kontor\\Shop\\';

    public static function register(): void
    {
        spl_autoload_register([self::class, 'autoload']);
    }

    private static function autoload(string $class_name): void
    {
        if (!str_starts_with($class_name, self::PREFIX)) {
            return;
        }

        $relative_class = substr($class_name, strlen(self::PREFIX));

        if (str_starts_with($relative_class, 'Pro\\')) {
            $relative_class = 'Pro__premium_only' . substr($relative_class, 3);
        }

        $relative_path  = str_replace('\\', DIRECTORY_SEPARATOR, $relative_class) . '.php';
        $file_path      = KONTOR_SHOP_PATH . 'src' . DIRECTORY_SEPARATOR . $relative_path;

        if (is_readable($file_path)) {
            require_once $file_path;
        }
    }
}
