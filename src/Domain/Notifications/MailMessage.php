<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Notifications;

/**
 * Eine fertig aufgeloeste E-Mail (Platzhalter bereits ersetzt, Abschnitt 9).
 * $to_name ist optional (z. B. Kundenname/Lagerverwalter-Anzeigename) - fehlt
 * er, verwenden Mailer-Implementierungen sinnvolle Fallbacks (meist die
 * Adresse selbst). $body ist immer Nur-Text; $html (falls gesetzt) die
 * komplette HTML-Fassung. Absender/Antwortadresse sind optional - leer =
 * Vorgabe von WordPress bzw. des SMTP-Plugins.
 *
 * Fuer Mailer mit eigener Huelle (z. B. aus einem Add-on): $content_html ist der gerenderte
 * Vorlageninhalt OHNE Shop-Huelle, $event der Mail-Ereignis-Schluessel
 * (DefaultTemplates), $headline die Ueberschrift fuer die Huelle,
 * $audience 'customer' oder 'internal', $reference die Bestellnummer.
 */
final class MailMessage
{
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $body,
        public readonly ?string $to_name = null,
        public readonly ?string $html = null,
        public readonly string $from_email = '',
        public readonly string $from_name = '',
        public readonly string $reply_to = '',
        public readonly ?string $content_html = null,
        public readonly string $event = '',
        public readonly string $headline = '',
        public readonly string $audience = '',
        public readonly string $reference = '',
        /**
         * Anhaenge als absolute Dateipfade (z. B. PDF-Rechnung). Pfade statt
         * Inhalt, damit der Postausgang sie bei einer Wiederholung noch findet.
         * Versandwege ohne Anhang-Unterstuetzung lassen sie weg - die Mails
         * nennen deshalb immer zusaetzlich einen Download-Link.
         *
         * @var array<int, string>
         */
        public readonly array $attachments = [],
    ) {
    }

    /**
     * Fuer den Postausgang (Infrastructure\Notifications\MailOutbox) speicherbar.
     *
     * @return array<string, mixed>
     */
    public function to_array(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function from_array(array $data): self
    {
        $text = static fn (string $key): string => isset($data[$key]) && is_scalar($data[$key]) ? (string) $data[$key] : '';
        $nullable = static fn (string $key): ?string => isset($data[$key]) && is_scalar($data[$key]) ? (string) $data[$key] : null;

        return new self(
            $text('to'),
            $text('subject'),
            $text('body'),
            $nullable('to_name'),
            $nullable('html'),
            $text('from_email'),
            $text('from_name'),
            $text('reply_to'),
            $nullable('content_html'),
            $text('event'),
            $text('headline'),
            $text('audience'),
            $text('reference'),
            array_values(array_filter(array_map('strval', isset($data['attachments']) && is_array($data['attachments']) ? $data['attachments'] : []), static fn (string $path): bool => $path !== '')),
        );
    }
}
