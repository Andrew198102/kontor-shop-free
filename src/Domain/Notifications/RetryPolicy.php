<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Notifications;

/**
 * Wann eine nicht zugestellte Mail aus dem Postausgang erneut versucht wird
 * (Infrastructure\Notifications\MailOutbox). Abstaende wachsen, damit ein
 * kurzer Ausfall (SMTP-Server, Gegenstelle) schnell ueberbrueckt wird und ein
 * dauerhafter den Server nicht im Minutentakt belastet. Nach dem letzten
 * Versuch gibt der Postausgang auf - die Mail bleibt sichtbar und laesst
 * sich von Hand erneut senden.
 */
final class RetryPolicy
{
    /** Wartezeit in Sekunden nach dem 1., 2., ... fehlgeschlagenen Versuch. */
    private const DELAYS = [5 * 60, 30 * 60, 2 * 3600, 6 * 3600, 24 * 3600];

    /**
     * Sekunden bis zum naechsten Versuch oder null, wenn aufgegeben wird.
     *
     * @param int $failed_attempts Anzahl bisher fehlgeschlagener Versuche (>= 1)
     */
    public static function next_delay(int $failed_attempts): ?int
    {
        if ($failed_attempts < 1) {
            return 0;
        }

        return self::DELAYS[$failed_attempts - 1] ?? null;
    }

    public static function max_attempts(): int
    {
        return count(self::DELAYS) + 1;
    }
}
