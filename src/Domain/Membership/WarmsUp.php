<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Membership;

/**
 * Optional fuer MembershipProvider: der Provider will das Login frueh im
 * Seitenaufbau pruefen (template_redirect), z. B. weil er dabei Cookies
 * setzt oder loescht - das geht nur, solange noch keine Ausgabe gesendet
 * wurde. Modules\Membership\MembershipModule ruft warm_up() dann auf.
 */
interface WarmsUp
{
    public function warm_up(): void;
}
