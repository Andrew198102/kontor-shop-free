<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Membership;

use Kontor\Shop\Context\RequestContext;

/**
 * Austauschbarer Mitgliedschafts-Adapter (Abschnitt 5.1). Das Plugin kennt
 * selbst keine Mitglieder - Implementierungen fragen bei einem Fremd-Plugin
 * nach (Adapter z. B. aus einem Add-on) oder liefern immer
 * "kein Mitglied" (NullProvider, P2, Fallback).
 *
 * Methodennamen bewusst wie im Umsetzungsplan (Abschnitt 5.1) festgelegt.
 */
interface MembershipProvider
{
    /**
     * null bedeutet "Mitgliedsstatus nicht ermittelbar" (z. B. Fremd-Plugin
     * inaktiv/nicht erreichbar) - Aufrufer behandeln das wie "kein Mitglied",
     * duerfen den Unterschied aber fuer Diagnosezwecke auswerten.
     */
    public function isMember(RequestContext $ctx): ?bool;

    public function memberInfo(RequestContext $ctx): ?MemberInfo;
}
