<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Notifications;

/**
 * Austauschbarer Mail-Versandweg (Abschnitt 5.1). Eingebaut: WpMailer
 * (wp_mail(), nutzt automatisch jedes SMTP-Plugin). Weitere Versandwege
 * (z. B. ein Adapter aus einem Add-on) melden sich ueber die Filter in
 * Infrastructure\Notifications\MailerFactory an.
 *
 * send() liefert true, wenn der Versandweg die Mail angenommen hat. Bei
 * false versucht Infrastructure\Notifications\DeliveryChain den naechsten
 * Weg und legt die Mail zuletzt in den Postausgang - ein Adapter muss
 * selbst keinen Ausweichweg kennen.
 */
interface Mailer
{
    public function send(MailMessage $message): bool;
}
