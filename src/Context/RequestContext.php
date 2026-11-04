<?php

declare(strict_types=1);

namespace Kontor\Shop\Context;

/**
 * Buendelt die Anfragedaten, die ein MembershipProvider zur Mitgliedserkennung
 * braucht (Abschnitt 5.1) - aktueller WP-Benutzer sowie die rohen Cookies fuer
 * Adapter (z. B. aus einem Add-on), die ein
 * Fremd-Plugin-Cookie auswerten.
 */
final class RequestContext
{
    /**
     * @param array<string, string> $cookies
     */
    public function __construct(
        public readonly ?int $user_id,
        public readonly array $cookies,
    ) {
    }

    public static function from_globals(): self
    {
        $user_id = get_current_user_id();

        return new self(
            $user_id > 0 ? $user_id : null,
            is_array($_COOKIE) ? $_COOKIE : []
        );
    }
}
