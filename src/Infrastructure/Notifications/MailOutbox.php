<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Notifications;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- eigene Tabellen des Plugins, kein WP-Objektcache-Bezug

use Kontor\Shop\Core\Registerable;
use Kontor\Shop\Domain\Notifications\MailMessage;
use Kontor\Shop\Domain\Notifications\RetryPolicy;
use Kontor\Shop\Infrastructure\Checkout\ReservationCleanup;
use Kontor\Shop\Infrastructure\Logging\Logger;
use Kontor\Shop\Settings\Repository;

/**
 * Postausgang fuer Mails, die kein Versandweg angenommen hat (DeliveryChain).
 * Versucht sie nach RetryPolicy erneut (eigener Einmal-Termin je naechster
 * Faelligkeit, dazu stuendlich als Sicherheitsnetz ueber den Cleanup-Hook)
 * und gibt nach dem letzten Versuch auf. Aufgegebene Mails bleiben im
 * Backoffice sichtbar (Einstellungen -> E-Mail-Versand), lassen sich dort
 * erneut senden oder verwerfen und werden nach KEEP_DAYS automatisch
 * geloescht. Zugestellte Mails verschwinden sofort - der Postausgang
 * enthaelt Kundendaten und haelt sie nur so lange wie noetig.
 */
final class MailOutbox implements Registerable
{
    public const HOOK = 'kontor_shop/mail/outbox';

    public const STATUS_PENDING = 'pending';
    public const STATUS_FAILED  = 'failed';

    private const KEEP_DAYS = 30;

    /** Hoechstens so viele Mails je Lauf, damit ein Cron-Aufruf kurz bleibt. */
    private const BATCH = 20;

    public function __construct(private readonly Repository $settings)
    {
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'process_due']);
        add_action(ReservationCleanup::HOOK, [$this, 'process_due']);
    }

    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'kontor_shop_mail_outbox';
    }

    public function enqueue(MailMessage $message, string $mailer_key, string $error): void
    {
        global $wpdb;

        $payload = wp_json_encode($message->to_array());

        if (!is_string($payload)) {
            Logger::error('mail', 'Mail konnte nicht in den Postausgang gelegt werden (Inhalt nicht speicherbar).', ['order' => $message->reference]);

            return;
        }

        $now = time();

        $wpdb->insert(self::table(), [
            'created_at'      => gmdate('Y-m-d H:i:s', $now),
            'next_attempt_at' => gmdate('Y-m-d H:i:s', $now + (int) RetryPolicy::next_delay(1)),
            'attempts'        => 1,
            'status'          => self::STATUS_PENDING,
            'mailer'          => substr($mailer_key, 0, 32),
            'event'           => substr($message->event, 0, 64),
            'recipient'       => substr($message->to, 0, 190),
            'reference'       => substr($message->reference, 0, 32),
            'subject'         => substr($message->subject, 0, 255),
            'payload'         => $payload,
            'last_error'      => substr($error, 0, 255),
        ]);

        Logger::warning('mail', sprintf('Mail „%s“ nicht zugestellt, liegt im Postausgang: %s', $message->event, $error !== '' ? $error : 'kein Versandweg hat sie angenommen'), ['order' => $message->reference]);

        $this->schedule_next();
    }

    /** Faellige Mails erneut versuchen (Cron). */
    public function process_due(): void
    {
        global $wpdb;

        $table = self::table();

        $wpdb->query($wpdb->prepare(
            'DELETE FROM %i WHERE status = %s AND created_at < %s',
            $table,
            self::STATUS_FAILED,
            gmdate('Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS)
        ));

        /** @var array<int, array<string, mixed>> $rows */
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM %i WHERE status = %s AND next_attempt_at <= %s ORDER BY next_attempt_at ASC LIMIT %d',
            $table,
            self::STATUS_PENDING,
            gmdate('Y-m-d H:i:s'),
            self::BATCH
        ), ARRAY_A);

        foreach ($rows as $row) {
            $this->attempt($row);
        }

        $this->schedule_next();
    }

    /**
     * Alle offenen und aufgegebenen Mails sofort erneut versuchen (Knopf im Backoffice).
     *
     * @return array{sent: int, left: int}
     */
    public function retry_all(): array
    {
        global $wpdb;

        $table = self::table();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY created_at ASC LIMIT %d', $table, self::BATCH), ARRAY_A);

        $sent = 0;

        foreach ($rows as $row) {
            if ($this->attempt($row, true)) {
                ++$sent;
            }
        }

        $this->schedule_next();

        $counts = $this->counts();

        return ['sent' => $sent, 'left' => $counts['pending'] + $counts['failed']];
    }

    public function discard(int $id): void
    {
        global $wpdb;

        $wpdb->delete(self::table(), ['id' => $id], ['%d']);
    }

    /**
     * @return array{pending: int, failed: int}
     */
    public function counts(): array
    {
        global $wpdb;

        $table  = self::table();
        $counts = ['pending' => 0, 'failed' => 0];

        foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT status, COUNT(*) AS n FROM %i GROUP BY status', $table), ARRAY_A) as $row) {
            $status = (string) ($row['status'] ?? '');

            if (isset($counts[$status])) {
                $counts[$status] = (int) $row['n'];
            }
        }

        return $counts;
    }

    /**
     * Neueste Eintraege fuer die Anzeige im Backoffice (ohne Mailinhalt).
     *
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 20): array
    {
        global $wpdb;

        $table = self::table();

        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT id, created_at, next_attempt_at, attempts, status, event, recipient, reference, subject, last_error FROM %i ORDER BY created_at DESC LIMIT %d',
            $table,
            $limit
        ), ARRAY_A);
    }

    /**
     * Eine Mail ueber die Versandkette ohne Postausgang schicken. Klappt es,
     * wird der Eintrag geloescht; sonst zaehlt der Versuch, und der naechste
     * Termin kommt aus RetryPolicy (oder der Eintrag gilt als aufgegeben).
     *
     * @param array<string, mixed> $row
     */
    private function attempt(array $row, bool $manual = false): bool
    {
        global $wpdb;

        $id      = (int) ($row['id'] ?? 0);
        $data    = json_decode((string) ($row['payload'] ?? ''), true);
        $started = time();

        if (!is_array($data)) {
            $this->discard($id);

            return false;
        }

        $message = MailMessage::from_array($data);

        if (MailerFactory::delivery((string) ($row['mailer'] ?? ''), $this->settings, false)->send($message)) {
            $this->discard($id);
            Logger::info('mail', sprintf('Mail „%s“ aus dem Postausgang zugestellt.', $message->event), ['order' => $message->reference]);

            return true;
        }

        // Ein Klick auf "erneut senden" zaehlt nicht gegen die automatischen Versuche.
        $attempts = (int) ($row['attempts'] ?? 1) + ($manual ? 0 : 1);
        $delay    = RetryPolicy::next_delay($attempts);
        $error    = DeliveryChain::failure_since($started);

        $wpdb->update(self::table(), [
            'attempts'        => $attempts,
            'status'          => $delay === null ? self::STATUS_FAILED : self::STATUS_PENDING,
            'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + (int) $delay),
            'last_error'      => substr($error !== '' ? $error : (string) ($row['last_error'] ?? ''), 0, 255),
        ], ['id' => $id]);

        if ($delay === null && !$manual) {
            Logger::error('mail', sprintf('Mail „%s“ nach %d Versuchen aufgegeben.', $message->event, $attempts), ['order' => $message->reference]);
        }

        return false;
    }

    /** Einmal-Termin auf die naechste faellige Mail setzen (oder keinen, wenn der Postausgang leer ist). */
    private function schedule_next(): void
    {
        global $wpdb;

        $table = self::table();
        $next  = $wpdb->get_var($wpdb->prepare('SELECT MIN(next_attempt_at) FROM %i WHERE status = %s', $table, self::STATUS_PENDING));
        $when  = is_string($next) && $next !== '' ? (int) strtotime($next . ' UTC') : 0;

        $scheduled = wp_next_scheduled(self::HOOK);

        if ($when === 0) {
            if ($scheduled !== false) {
                wp_clear_scheduled_hook(self::HOOK);
            }

            return;
        }

        $when = max($when, time() + 60);

        if ($scheduled !== false && abs($scheduled - $when) < 60) {
            return;
        }

        wp_clear_scheduled_hook(self::HOOK);
        wp_schedule_single_event($when, self::HOOK);
    }
}
