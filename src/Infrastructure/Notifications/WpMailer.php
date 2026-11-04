<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Notifications;

use Kontor\Shop\Domain\Notifications\MailMessage;
use Kontor\Shop\Domain\Notifications\Mailer;

/**
 * Standard-Versandweg ueber das WordPress-eigene wp_mail(). Jedes SMTP-Plugin
 * (WP Mail SMTP, FluentSMTP, Post SMTP ...) haengt sich dort ein, ohne dass
 * der Shop es kennen muss; ohne Plugin versendet der Server selbst (PHP mail()).
 * Zugleich Ausweichweg fuer alle anderen Versandwege (DeliveryChain). HTML-Mails gehen mit Nur-Text-Alternative raus (besser gegen Spamfilter);
 * Absender und Antwortadresse kommen aus den Einstellungen (Bereich "E-Mail-Versand").
 */
final class WpMailer implements Mailer
{
    public function send(MailMessage $message): bool
    {
        $to = $message->to_name !== null && $message->to_name !== ''
            ? sprintf('%s <%s>', $message->to_name, $message->to)
            : $message->to;

        $headers = [];

        if ($message->from_email !== '') {
            $headers[] = 'From: ' . ($message->from_name !== '' ? self::quote($message->from_name) . ' <' . $message->from_email . '>' : $message->from_email);
        }

        if ($message->reply_to !== '') {
            $headers[] = 'Reply-To: ' . $message->reply_to;
        }

        $alt_body = static function ($phpmailer) use ($message): void {
            $phpmailer->AltBody = $message->body;
        };

        if ($message->html !== null) {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
            add_action('phpmailer_init', $alt_body);
        }

        $sent = wp_mail($to, $message->subject, $message->html ?? $message->body, $headers);

        if ($message->html !== null) {
            remove_action('phpmailer_init', $alt_body);
        }

        if ($sent) {
            MailHealth::record_success();
        }

        return $sent;
    }

    /** Anzeigename im From-Header (Kommas/Anfuehrungszeichen sicher). */
    private static function quote(string $name): string
    {
        $name = str_replace(["\r", "\n", '"'], ['', '', "'"], $name);

        return preg_match('/[^A-Za-z0-9 ._-]/', $name) === 1 ? '"' . $name . '"' : $name;
    }
}
