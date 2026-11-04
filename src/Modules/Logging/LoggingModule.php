<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Logging;

use Kontor\Shop\Core\Registerable;
use Kontor\Shop\Infrastructure\Checkout\ReservationCleanup;
use Kontor\Shop\Infrastructure\Logging\Logger;
use Kontor\Shop\Settings\Repository;

/**
 * Initialisiert das Protokoll (Logger), raeumt es mit dem
 * Reservierungs-Cleanup auf und faengt fatale PHP-Fehler aus dem Plugin-Code
 * ab (Shutdown-Handler), die sonst nur als weisse Seite/kritischer WordPress-
 * Fehler beim Kunden ankaemen.
 */
final class LoggingModule implements Registerable
{
    private const FATAL = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];

    public function __construct(private readonly Repository $settings)
    {
        Logger::boot($settings);
    }

    public function register(): void
    {
        add_action(ReservationCleanup::HOOK, [Logger::class, 'purge']);
        register_shutdown_function([$this, 'on_shutdown']);
    }

    public function on_shutdown(): void
    {
        $error = error_get_last();

        if ($error === null || !in_array($error['type'], self::FATAL, true)) {
            return;
        }

        $plugin_dir = rtrim(str_replace('\\', '/', (string) KONTOR_SHOP_PATH), '/');
        $file       = str_replace('\\', '/', (string) $error['file']);

        if (!str_starts_with($file, $plugin_dir)) {
            return; // Fehler in WordPress/anderen Plugins gehoeren nicht in dieses Protokoll
        }

        Logger::log(Logger::CRITICAL, 'fatal', (string) $error['message'], [
            'file' => basename($file) . ':' . $error['line'],
        ], Logger::new_reference());
    }
}
