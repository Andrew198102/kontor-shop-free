<?php

declare(strict_types=1);

namespace Kontor\Shop\Core;

use Kontor\Shop\Admin\Menu;
use Kontor\Shop\Infrastructure\Checkout\ReservationCleanup;
use Kontor\Shop\Infrastructure\Notifications\MailOutbox;
use Kontor\Shop\Modules\Agents\AgentsModule;
use Kontor\Shop\Modules\Cart\CartModule;
use Kontor\Shop\Modules\Catalog\CatalogModule;
use Kontor\Shop\Modules\Checkout\CheckoutModule;
use Kontor\Shop\Modules\Logging\LoggingModule;
use Kontor\Shop\Modules\Media\MediaModule;
use Kontor\Shop\Modules\Notifications\NotificationsModule;
use Kontor\Shop\Modules\Notifications\PoweredBy;
use Kontor\Shop\Modules\Privacy\PrivacyModule;
use Kontor\Shop\Modules\Seo\SeoModule;
use Kontor\Shop\Modules\Storefront\DesignModule;
use Kontor\Shop\Settings\Repository;

/**
 * Zentrale Modul-Registry. Kennt alle verfuegbaren Module und instanziiert sie
 * beim Boot. Seit P1: Admin\Menu (Artikel-/Lager-/Bestandsverwaltung). Seit
 * P3: Frontend-Katalog + Warenkorb. Seit P4: Checkout + Reservierungs-Cleanup
 * (WP-Cron). Seit P6: Notifications (Mail-Trigger). Seit dem
 * Storefront-Ausbau: DesignModule (Hauptfarbe als CSS-Custom-Property).
 * Verfuegbarkeitsanfragen, automatische Zahlungserinnerungen/Autostorno,
 * Mitgliedschaft und Shoptypen kommen mit Pro (ueber kontor_shop/modules).
 * Seit Schema 22: MailOutbox (Postausgang, erneute Zustellversuche).
 * Seit 1.13: AgentsModule (KI-Assistenten ueber MCP).
 * Add-ons haengen eigene Module ueber den Filter kontor_shop/modules an
 * (vor dem Boot registrieren, also plugins_loaded mit Prioritaet < 5).
 */
final class Modules
{
    /**
     * Klassennamen aller Module in Boot-Reihenfolge.
     *
     * @var array<int, class-string<Registerable>>
     */
    private const MAP = [
        LoggingModule::class,
        Menu::class,
        CatalogModule::class,
        CartModule::class,
        CheckoutModule::class,
        ReservationCleanup::class,
        NotificationsModule::class,
        // Freiwillige Zeile "Vereinsshop mit Kontor Shop" (nur Free, nur nach Zustimmung).
        PoweredBy::class,
        MailOutbox::class,
        DesignModule::class,
        PrivacyModule::class,
        MediaModule::class,
        SeoModule::class,
        AgentsModule::class,
    ];

    /**
     * @return array<int, class-string<Registerable>>
     */
    public static function all(): array
    {
        /** @var array<int, class-string<Registerable>> $map */
        $map = apply_filters('kontor_shop/modules', self::MAP);

        return $map;
    }

    /**
     * Liefert alle Module als registrierbare Instanzen.
     *
     * @return array<int, Registerable>
     */
    public static function active(Repository $settings): array
    {
        $active = [];

        foreach (self::all() as $class) {
            if (!is_a($class, Registerable::class, true)) {
                continue;
            }

            $active[] = new $class($settings);
        }

        return $active;
    }

    /**
     * Standardwerte fuer den Aktiv-Status aller Module (fuer die Settings-
     * Defaults). Admin\Menu ist Kern-Funktionalitaet und immer aktiv, daher
     * hier noch kein Eintrag - ein echtes An/Aus-Toggle pro Modul folgt erst,
     * wenn Frontend-Module (P3+) das rechtfertigen.
     *
     * @return array<string, bool>
     */
    public static function default_states(): array
    {
        return [];
    }
}
