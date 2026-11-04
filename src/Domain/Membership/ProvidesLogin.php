<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Membership;

use Kontor\Shop\Context\RequestContext;

/**
 * Optional fuer MembershipProvider: der Provider hat eine eigene Anmeldeseite.
 * Warenkorb und Kasse verlinken dann nicht angemeldete Besucher dorthin
 * ("Mitglied? Anmelden fuer Mitgliedspreise"). Der Shop selbst hat kein Login.
 */
interface ProvidesLogin
{
    /**
     * Ist beim Provider jemand angemeldet (egal ob mit Mitgliedsvorteil)?
     * Muss billig sein - wird im Warenkorb auf jeder Seite gefragt.
     */
    public function is_logged_in(RequestContext $ctx): bool;

    /**
     * @param string $return_to Absolute URL, auf die nach der Anmeldung
     *                          zurueckgeleitet werden soll.
     * @return string|null      null = keine Anmeldeseite bekannt
     */
    public function login_url(string $return_to): ?string;
}
