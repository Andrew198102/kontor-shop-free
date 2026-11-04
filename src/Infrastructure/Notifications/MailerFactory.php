<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Notifications;

use Kontor\Shop\Domain\Notifications\Mailer;
use Kontor\Shop\Settings\Repository;

/**
 * Loest den in den Einstellungen gewaehlten Mailer-Schluessel
 * (notifications.mailer) zu einer Mailer-Instanz auf - Spiegelbild der
 * Mitgliedschafts-Provider (Pro, Abschnitt 5.1). Eingebaut ist
 * nur WpMailer. Weitere Adapter (z. B. aus einem Add-on) tragen sich ueber
 * die Filter kontor_shop/notifications/mailers (Auswahlliste) und
 * kontor_shop/notifications/mailer_instance (Aufloesung, Argumente: null,
 * Schluessel, Settings) ein. Ein Adapter liefert bei Fehlern nur false -
 * DeliveryChain weicht dann auf wp_mail() und den Postausgang aus.
 */
final class MailerFactory
{
    public const WP_MAILER = 'wp';

    /**
     * @return array<string, string> Mailer-Schluessel => Anzeigename
     */
    public static function available(): array
    {
        $mailers = [
            self::WP_MAILER => __('Default (wp_mail)', 'kontor-shop'),
        ];

        /** @var array<string, string> $mailers */
        $mailers = apply_filters('kontor_shop/notifications/mailers', $mailers);

        return $mailers;
    }

    public static function resolve(string $key, Repository $settings): Mailer
    {
        /** @var Mailer|null $mailer */
        $mailer = apply_filters('kontor_shop/notifications/mailer_instance', null, $key, $settings);

        if ($mailer instanceof Mailer) {
            return $mailer;
        }

        return new WpMailer();
    }

    /**
     * Versandkette fuer Shop-Mails (DeliveryChain): gewaehlter Weg, dann
     * wp_mail(), zuletzt der Postausgang. $with_outbox = false fuer den
     * erneuten Versuch aus dem Postausgang selbst.
     */
    public static function delivery(string $key, Repository $settings, bool $with_outbox = true): Mailer
    {
        $primary = self::resolve($key, $settings);

        return new DeliveryChain(
            $primary,
            $primary instanceof WpMailer ? null : new WpMailer(),
            $with_outbox ? new MailOutbox($settings) : null,
            $key
        );
    }
}
