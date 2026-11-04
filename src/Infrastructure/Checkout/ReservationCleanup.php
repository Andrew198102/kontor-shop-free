<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Checkout;

use Kontor\Shop\Core\Registerable;
use Kontor\Shop\Settings\Repository;

/**
 * WP-Cron-Anbindung fuer den Reservierungs-Cleanup (Abschnitt 12). Das
 * eigentliche Scheduling (activate/deactivate) macht Core\Lifecycle; hier
 * wird nur der Hook ausgefuehrt. Nutzt bewusst den eingebauten 'hourly'-
 * Rhythmus statt eines eigenen Intervalls: bei der TTL-Reservierung
 * (reserve()) werden abgelaufene Reservierungen ohnehin ausgeschlossen
 * (expires_at > jetzt), der Cleanup ist reine Tabellenpflege, keine
 * Korrektheitsvoraussetzung - eine stuendliche Aufraeumung reicht.
 */
final class ReservationCleanup implements Registerable
{
    public const HOOK = 'kontor_shop/reservations/cleanup';

    /** Zeitpunkt des letzten Laufs (Unix) - fuer die Betriebsampel der Uebersicht. */
    public const LAST_RUN_OPTION = 'kontor_shop_cron_last_run';

    private ReservationStore $reservations;

    public function __construct(Repository $settings)
    {
        $this->reservations = new ReservationStore();
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);
    }

    public function run(): void
    {
        update_option(self::LAST_RUN_OPTION, time(), false);
        $this->reservations->purge_expired();
    }
}
