<?php

declare(strict_types=1);

namespace Kontor\Shop\Context;

/**
 * Ist der aktuelle Kaeufer ein erkanntes Mitglied? Eine Stelle fuer
 * Warenkorb, Kasse und Mitgliedspreise.
 *
 * Der Kern kennt keine Mitgliedschaft: ohne Erweiterung ist niemand
 * Mitglied - Normalpreis, normaler Versand, Zahlungsarten wie eingestellt.
 * Mitgliedschaft (Provider-Auswahl, Login-Pruefung) kommt mit Pro.
 */
final class Membership
{
    public static function is_member(?RequestContext $ctx = null): bool
    {
        /**
         * Filter kontor_shop/membership/is_member: ob der Kaeufer dieser
         * Anfrage als Mitglied gilt (Mitgliedspreis, Mitglieder-Freiversand,
         * Rechnung nur fuer Mitglieder). Pro fragt hier den eingestellten
         * Mitgliedschafts-Provider. Standard: false.
         *
         * @param bool           $is_member
         * @param RequestContext $ctx Benutzer und Cookies der Anfrage
         */
        return (bool) apply_filters('kontor_shop/membership/is_member', false, $ctx ?? RequestContext::from_globals());
    }
}
