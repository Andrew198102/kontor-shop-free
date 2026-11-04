<?php

declare(strict_types=1);

namespace Kontor\Shop\Core;

use Kontor\Shop\Infrastructure\Audit\AuditLog;

/**
 * Rollen/Capabilities (Abschnitt 11). Administratoren (`manage_options`)
 * haben implizit alle drei - sowohl per Kurzschluss in den Checks hier als
 * auch, weil Core\Lifecycle die drei Capabilities zusaetzlich direkt an die
 * administrator-Rolle vergibt.
 *
 * ACCESS ist keine eigene Berechtigungsstufe aus dem Plan, sondern reine
 * Menue-Sichtbarkeits-Klammer: WordPress prueft pro Menuepunkt genau eine
 * Capability, daher braucht "Bestellungen"/"Bestand" (fuer Kassier UND
 * Lagerverwalter sichtbar) eine gemeinsame Capability, die immer zusammen
 * mit einer der drei echten Capabilities vergeben wird (grant()/revoke()).
 * Die eigentliche Autorisierung je Aktion pruefen die Admin-Seiten weiterhin
 * gegen MANAGE/CASHIER/WAREHOUSE_MANAGER.
 *
 * grant()/revoke() schreiben ins Aenderungsprotokoll, sofern sich am
 * Benutzer wirklich etwas aendert.
 */
final class Capabilities
{
    public const MANAGE            = 'manage_kontor_shop';
    public const CASHIER           = 'kontor_shop_cashier';
    public const WAREHOUSE_MANAGER = 'kontor_shop_warehouse_manager';
    public const ACCESS            = 'kontor_shop_access';
    public const SETTINGS          = 'kontor_shop_settings';
    public const SHOP_MANAGER_ROLE = 'kontor_shop_manager';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [self::MANAGE, self::CASHIER, self::WAREHOUSE_MANAGER, self::ACCESS, self::SETTINGS];
    }

    public static function can_manage(): bool
    {
        return current_user_can('manage_options') || current_user_can(self::MANAGE);
    }

    /**
     * Einstellungen, Mail-Vorlagen, Protokoll und Shoptypen: nur
     * Administratoren bzw. Benutzer mit der SETTINGS-Capability. Die Rolle
     * "Shop-Manager" hat sie bewusst nicht.
     */
    public static function can_settings(): bool
    {
        return current_user_can('manage_options') || current_user_can(self::SETTINGS);
    }

    public static function is_cashier(): bool
    {
        return self::can_manage() || current_user_can(self::CASHIER);
    }

    /**
     * Darf der Benutzer Bestand buchen und versenden - in irgendeinem Lager?
     * Shop-Admin immer, sonst nur mit mindestens einem Lager im Scope
     * (warehouse_scope()). Ohne Lagerverwalter-Funktion (Free) bringt die
     * Capability allein also keinen Lagerzugriff.
     */
    public static function is_warehouse_manager(): bool
    {
        return self::can_manage() || self::warehouse_scope() !== [];
    }

    /**
     * Ob der aktuelle Benutzer fuer ein konkretes Lager Bestands-/
     * Versandaktionen ausfuehren darf: Shop-Admin immer, sonst nur der
     * eingetragene Lagerverwalter dieses einen Lagers (Abschnitt 11) - und
     * nur, solange die Lagerverwalter-Funktion ihm Lager zuteilt
     * (warehouse_scope()).
     */
    public static function manages_warehouse(?int $warehouse_manager_user_id): bool
    {
        if (self::can_manage()) {
            return true;
        }

        return current_user_can(self::WAREHOUSE_MANAGER)
            && $warehouse_manager_user_id !== null
            && $warehouse_manager_user_id === get_current_user_id()
            && self::warehouse_scope() !== [];
    }

    /**
     * Lager, deren Bestand und Bestellungen der aktuelle Benutzer bearbeiten
     * darf: null = alle (Shop-Admin), [] = keines.
     *
     * @return array<int, int>|null
     */
    public static function warehouse_scope(): ?array
    {
        $default = self::can_manage() ? null : [];

        /**
         * Filter kontor_shop/warehouses/scope: Lager-IDs, die der Benutzer
         * bebuchen und versenden darf (z. B. Pro: die Lager, deren
         * Lagerverwalter er ist). null = alle. Standard: null fuer
         * Shop-Admins, sonst [] (kein Lager).
         *
         * @param array<int, int>|null $scope
         * @param int                  $user_id aktueller Benutzer
         */
        $scope = apply_filters('kontor_shop/warehouses/scope', $default, get_current_user_id());

        if ($scope === null) {
            return $default === null ? null : [];
        }

        return array_values(array_unique(array_filter(array_map('intval', (array) $scope), static fn (int $id): bool => $id > 0)));
    }

    public static function grant(int $user_id, string $capability): void
    {
        $user = get_userdata($user_id);

        if (!$user) {
            return;
        }

        $had = !empty($user->caps[$capability]);

        $user->add_cap($capability);
        $user->add_cap(self::ACCESS);

        if (!$had) {
            AuditLog::record('capability.grant', 'user', $user_id, (string) $user->user_login, ['capability' => [null, $capability]]);
        }
    }

    public static function revoke(int $user_id, string $capability): void
    {
        $user = get_userdata($user_id);

        if (!$user) {
            return;
        }

        $had = !empty($user->caps[$capability]);

        $user->remove_cap($capability);

        if ($had) {
            AuditLog::record('capability.revoke', 'user', $user_id, (string) $user->user_login, ['capability' => [$capability, null]]);
        }

        if (!user_can($user, self::MANAGE) && !user_can($user, self::CASHIER) && !user_can($user, self::WAREHOUSE_MANAGER)) {
            $user->remove_cap(self::ACCESS);
        }
    }
}
