<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Health;

/**
 * Gesamtstatus der Betriebsampel aus den Einzelpruefungen und die Regeln,
 * wann ein Arbeitsschritt "haengt" (Schwellen in Tagen, z. B. fuer Pro:
 * "Das haengt" und die Lager-Karte). Reine Logik ohne WordPress
 * (docs/PLAN_dashboard.md).
 */
final class ShopHealth
{
    /** Bezahlt, aber nach so vielen Tagen noch nicht versendet */
    public const SHIP_DAYS = 3;
    /** Zur Abholung bereit, aber nach so vielen Tagen noch nicht abgeholt */
    public const PICKUP_DAYS = 7;
    /** Rueckzahlung nach so vielen Tagen noch offen */
    public const REFUND_DAYS = 3;
    /** Kunde wartet so viele Tage auf einen Artikel (Verfuegbarkeitsanfrage) */
    public const REQUEST_DAYS = 14;
    /** Cron gilt als stehend, wenn er so lange nicht lief (Sekunden) */
    public const CRON_STALE = 3 * 3600;
    /** Mailfehler zaehlt fuer die Ampel so lange (Sekunden) */
    public const MAIL_FAILURE_WINDOW = 48 * 3600;

    /**
     * @param array<int, Check> $checks
     */
    public static function overall(array $checks): string
    {
        $status = Check::OK;

        foreach ($checks as $check) {
            if ($check->status === Check::BAD) {
                return Check::BAD;
            }

            if ($check->status === Check::WARN) {
                $status = Check::WARN;
            }
        }

        return $status;
    }

    /**
     * @param array<int, Check> $checks
     * @return array<int, Check> rot, gelb, Hinweis, ok
     */
    public static function sorted(array $checks): array
    {
        usort($checks, static fn (Check $a, Check $b): int => $a->weight() <=> $b->weight());

        return $checks;
    }

    /** Ganze Tage zwischen zwei Datumsangaben (Y-m-d …), nie negativ. */
    public static function days_between(?string $from, string $today): int
    {
        if ($from === null || $from === '') {
            return 0;
        }

        return max(0, (int) floor((strtotime(substr($today, 0, 10)) - strtotime(substr($from, 0, 10))) / 86400));
    }
}
