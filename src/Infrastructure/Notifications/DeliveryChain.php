<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Notifications;

use Kontor\Shop\Domain\Notifications\MailMessage;
use Kontor\Shop\Domain\Notifications\Mailer;

/**
 * Versandkette, damit eine Shop-Mail nie stillschweigend verloren geht:
 *
 *  1. der gewaehlte Versandweg (Einstellung notifications.mailer),
 *  2. wp_mail() als Ausweichweg - nutzt jedes SMTP-Plugin, ohne Plugin
 *     den Server selbst (entfaellt, wenn 1. schon wp_mail() ist),
 *  3. der Postausgang (MailOutbox): versucht es spaeter automatisch erneut
 *     und zeigt die Mail im Backoffice an, bis sie raus ist.
 *
 * Erstellt von MailerFactory::delivery(). Beim erneuten Versuch aus dem
 * Postausgang selbst laeuft die Kette ohne Schritt 3.
 */
final class DeliveryChain implements Mailer
{
    public function __construct(
        private readonly Mailer $primary,
        private readonly ?Mailer $fallback = null,
        private readonly ?MailOutbox $outbox = null,
        private readonly string $mailer_key = '',
    ) {
    }

    public function send(MailMessage $message): bool
    {
        $started = time();

        if ($this->primary->send($message)) {
            return true;
        }

        if ($this->fallback !== null && $this->fallback->send($message)) {
            return true;
        }

        if ($this->outbox !== null) {
            $this->outbox->enqueue($message, $this->mailer_key, self::failure_since($started));
        }

        return false;
    }

    /** Fehlermeldung von wp_mail() aus diesem Versuch (nicht eine aeltere). */
    public static function failure_since(int $started): string
    {
        $failure = MailHealth::last_failure();

        return $failure !== null && $failure['time'] >= $started ? $failure['message'] : '';
    }
}
