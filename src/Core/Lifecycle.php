<?php

declare(strict_types=1);

namespace Kontor\Shop\Core;

use Kontor\Shop\Infrastructure\Audit\AuditLog;
use Kontor\Shop\Infrastructure\Checkout\ReservationCleanup;
use Kontor\Shop\Infrastructure\Notifications\MailOutbox;
use Kontor\Shop\Infrastructure\Frontend\PageInstaller;
use Kontor\Shop\Infrastructure\Repositories\CategoryRepository;
use Kontor\Shop\Infrastructure\Repositories\ShopTypeRepository;
use Kontor\Shop\Modules\Seo\SeoModule;
use Kontor\Shop\Settings\Repository;

use function Kontor\Shop\Database\backfill_seo;
use function Kontor\Shop\Database\migrate_v1;
use function Kontor\Shop\Database\prepare_seo_slugs;

/**
 * Aktivierung / Deaktivierung / stiller Upgrade-Pfad.
 *
 * Legt bei Aktivierung und bei jedem Versionssprung alle Tabellen aus
 * database/migrate_v1.php an (dbDelta, idempotent) und gleicht die
 * Einstellungen mit den aktuellen Defaults ab. Seit P4 zusaetzlich das
 * WP-Cron-Scheduling fuer den Reservierungs-Cleanup (Abschnitt 12). Seit P8
 * zusaetzlich die Vergabe der drei Capabilities (Abschnitt 11) an die
 * administrator-Rolle. Seit dem Storefront-Ausbau zusaetzlich zwei
 * Start-Kategorien ("Allgemein"/"Jagd", ohne Akzentfarbe - der Shop-Admin
 * setzt sie bei Bedarf selbst, das Plugin bleibt projektneutral) sowie die
 * drei Shop-Seiten (PageInstaller). Seit Schema 4 gibt es Shoptypen als
 * oberste Ebene: aeltere Kategorien ohne Shoptyp werden dabei verlustfrei
 * je einem gleichnamigen Shoptyp zugeordnet, auf frischen Installationen
 * entsteht ein Start-Shoptyp "Allgemein". Seit Schema 10 (SEO/KI-Anbindung):
 * Artikel-Slugs, content_updated_at, Kategorie-Hierarchie und Rewrite-Regeln.
 * Seit Schema 15 (Kassen-Schnittstelle): paid_via/paid_by_name/paid_with an
 * Bestellungen, actor im Statusverlauf und die Tabelle api_requests
 * (Idempotenz der externen Kassen-Buchungen). Seit Schema 16: Tabelle audit
 * (Aenderungsprotokoll, Infrastructure\Audit\AuditLog). Seit Schema 17:
 * Varianten (product_variants, variant_id an Bestand/Reservierung/Position),
 * Bildergalerie (product_images) und Grundpreis-Angaben (unit_amount/unit_type).
 * Seit Schema 18: Tabelle stock_requests (Verfuegbarkeitsanfragen,
 * mit Pro). Seit Schema 19: products.discontinued
 * (Auslaufartikel, wird nicht nachbestellt). Seit Schema 21: Rabattcodes
 * (Tabellen coupons/coupon_uses, discount_code/discount_total an
 * Bestellungen, discount an Positionen). Seit Schema 22: Tabelle mail_outbox
 * (Postausgang fuer nicht zugestellte Mails, Infrastructure\Notifications\MailOutbox).
 * Seit Schema 23: Tabelle invoices (Rechnungen und Gutschriften, Modules\Invoices).
 */
final class Lifecycle
{
    private const SCHEMA_VERSION        = 23;
    private const SETTINGS_CAP_MIGRATED = 'kontor_shop_settings_cap_migrated';
    private const SCHEMA_VERSION_OPTION = 'kontor_shop_schema_version';
    /** Ausgabe (free/pro) beim letzten Boot - ein Wechsel leert einmal die Rewrite-Regeln. */
    public const EDITION_OPTION = 'kontor_shop_last_edition';

    public static function activate(): void
    {
        self::install_or_upgrade();

        AuditLog::record('plugin.activate', 'plugin', null, 'Kontor Shop ' . KONTOR_SHOP_VERSION);

        if (!wp_next_scheduled(ReservationCleanup::HOOK)) {
            wp_schedule_event(time(), 'hourly', ReservationCleanup::HOOK);
        }
    }

    public static function deactivate(): void
    {
        AuditLog::record('plugin.deactivate', 'plugin', null, 'Kontor Shop ' . KONTOR_SHOP_VERSION);

        wp_clear_scheduled_hook(ReservationCleanup::HOOK);
        wp_clear_scheduled_hook(MailOutbox::HOOK);
        SeoModule::remove_rules_and_flush();

        // Tabellen/Daten bleiben beim Deaktivieren grundsaetzlich erhalten
        // (siehe Core\Uninstaller).
    }

    /**
     * Laeuft bei jedem Boot. Idempotent, guenstig (nur ein Options-Vergleich).
     */
    public static function maybe_upgrade(): void
    {
        self::maybe_edition_changed();

        $stored = (int) get_option(self::SCHEMA_VERSION_OPTION, 0);

        if ($stored >= self::SCHEMA_VERSION) {
            return;
        }

        self::install_or_upgrade();

        AuditLog::record('plugin.upgrade', 'plugin', null, 'Kontor Shop ' . KONTOR_SHOP_VERSION, [
            'schema_version' => [(string) $stored, (string) self::SCHEMA_VERSION],
        ]);
    }

    /**
     * Lizenz dazu oder weg: Pro bringt eigene Adressen mit (z. B. die
     * Kategorieseiten ueber kontor_shop/seo/rewrite_rules). Damit keine
     * veralteten Regeln stehen bleiben, nach einem Wechsel der Ausgabe
     * einmal neu aufbauen ($wp_rewrite gibt es beim Boot noch nicht -
     * SeoModule leert sie auf "init").
     */
    private static function maybe_edition_changed(): void
    {
        $edition = Edition::current();

        if (get_option(self::EDITION_OPTION) === $edition) {
            return;
        }

        update_option(self::EDITION_OPTION, $edition, true);
        update_option(SeoModule::FLUSH_OPTION, 1, false);
    }

    private static function install_or_upgrade(): void
    {
        require_once KONTOR_SHOP_PATH . 'database/migrate_v1.php';
        require_once KONTOR_SHOP_PATH . 'database/migrate_seo.php';
        prepare_seo_slugs();
        migrate_v1();
        backfill_seo();

        $existing = get_option(Repository::OPTION_KEY, []);
        $existing = is_array($existing) ? $existing : [];

        $merged = Repository::merge_defaults($existing);

        update_option(Repository::OPTION_KEY, $merged, true);
        update_option(self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, true);

        update_option('kontor_shop_version', KONTOR_SHOP_VERSION, true);

        $administrator = get_role('administrator');

        if ($administrator) {
            foreach (Capabilities::all() as $capability) {
                $administrator->add_cap($capability);
            }
        }

        self::install_shop_manager_role();
        self::migrate_settings_capability();
        self::migrate_legacy_bank_account();
        self::migrate_old_price_note();
        self::migrate_categories_to_shop_types();
        self::seed_default_shop_type();

        PageInstaller::install();

        // Neue Adressen (/shop/artikel/...) brauchen frische Rewrite-Regeln; $wp_rewrite
        // gibt es beim Boot noch nicht, daher nur vormerken - SeoModule leert sie auf "init".
        update_option(SeoModule::FLUSH_OPTION, 1, false);
    }

    /**
     * Rolle "Shop-Manager": alles im Shop ausser Einstellungen, Mail-Vorlagen,
     * Protokoll und Shoptypen (die haengen an Capabilities::SETTINGS).
     */
    private static function install_shop_manager_role(): void
    {
        $capabilities = [
            'read'                   => true,
            'upload_files'           => true,
            Capabilities::MANAGE     => true,
            Capabilities::ACCESS     => true,
        ];

        $role = get_role(Capabilities::SHOP_MANAGER_ROLE);

        if ($role === null) {
            add_role(Capabilities::SHOP_MANAGER_ROLE, __('Shop manager', 'kontor-shop'), $capabilities);
            return;
        }

        foreach ($capabilities as $capability => $grant) {
            $role->add_cap($capability, $grant);
        }
    }

    /**
     * Einmalig beim Update auf Schema 11: SETTINGS wurde erst jetzt von MANAGE
     * abgetrennt. Alle, die bisher MANAGE hatten (Rollen und einzelne
     * Benutzer), behalten damit ihren Zugriff auf Einstellungen usw. Die
     * neue Rolle Shop-Manager wird ausgenommen. Das Flag verhindert, dass
     * ein spaeter bewusst entzogenes Recht bei einer Reaktivierung
     * wiederkommt.
     */
    private static function migrate_settings_capability(): void
    {
        if (get_option(self::SETTINGS_CAP_MIGRATED)) {
            return;
        }

        foreach (wp_roles()->role_objects as $name => $role) {
            if ($name !== Capabilities::SHOP_MANAGER_ROLE && $role->has_cap(Capabilities::MANAGE)) {
                $role->add_cap(Capabilities::SETTINGS);
            }
        }

        foreach (get_users(['fields' => ['ID'], 'number' => -1]) as $row) {
            $user = new \WP_User((int) $row->ID);

            if (in_array(Capabilities::SHOP_MANAGER_ROLE, (array) $user->roles, true)) {
                continue;
            }

            if ($user->has_cap(Capabilities::MANAGE) || user_can($user, 'manage_options')) {
                $user->add_cap(Capabilities::SETTINGS);
            }
        }

        update_option(self::SETTINGS_CAP_MIGRATED, 1, false);
    }

    /**
     * Schema 6: der frueher vorbelegte Preishinweis ("inkl. gesetzlicher
     * Umsatzsteuer") wuerde fuer umsatzsteuerfreie Shops nicht stimmen. Die
     * USt-Zeile entsteht jetzt automatisch aus den Steuer-Einstellungen; ein
     * unveraenderter alter Standardtext wird daher geleert.
     */
    private static function migrate_old_price_note(): void
    {
        $settings = get_option(Repository::OPTION_KEY, []);

        if (!is_array($settings) || !isset($settings['legal']['price_note'])) {
            return;
        }

        if ($settings['legal']['price_note'] === 'Alle Preise inkl. gesetzlicher Umsatzsteuer, zzgl. Versandkosten.') {
            $settings['legal']['price_note'] = '';
            update_option(Repository::OPTION_KEY, $settings, true);
        }
    }

    /**
     * Schema 5: die bisher einzelne Bankverbindung (payment.bank_recipient/
     * bank_iban/bank_bic) wird zum ersten Konto der neuen Kontenliste
     * (payment.accounts). Laeuft nur, solange noch kein Konto existiert.
     */
    private static function migrate_legacy_bank_account(): void
    {
        $settings = get_option(Repository::OPTION_KEY, []);
        $settings = is_array($settings) ? $settings : [];
        $payment  = isset($settings['payment']) && is_array($settings['payment']) ? $settings['payment'] : [];

        if (!empty($payment['accounts'])) {
            return;
        }

        $recipient = (string) ($payment['bank_recipient'] ?? '');
        $iban      = (string) ($payment['bank_iban'] ?? '');
        $bic       = (string) ($payment['bank_bic'] ?? '');

        if ($recipient === '' && $iban === '' && $bic === '') {
            return;
        }

        $payment['accounts'] = [[
            'id'        => 'acc_main',
            'label'     => __('Main account', 'kontor-shop'),
            'recipient' => $recipient,
            'iban'      => $iban,
            'bic'       => $bic,
        ]];

        unset($payment['bank_recipient'], $payment['bank_iban'], $payment['bank_bic']);

        $settings['payment'] = $payment;
        update_option(Repository::OPTION_KEY, $settings, true);
    }

    /**
     * Schema 4: Kategorien aus aelteren Versionen haben noch keinen Shoptyp.
     * Jede bekommt einen gleichnamigen Shoptyp (gleicher Slug/gleiche Farbe/
     * Sortierung) - verlustfrei, der Shop-Admin kann Namen und Struktur
     * danach frei umbauen. Laeuft nur fuer Kategorien ohne Zuordnung, ist
     * also gefahrlos wiederholbar.
     */
    private static function migrate_categories_to_shop_types(): void
    {
        $categories = new CategoryRepository();
        $shop_types = new ShopTypeRepository();

        foreach ($categories->all() as $category) {
            if ($category->shop_type_id !== null || $category->id === null) {
                continue;
            }

            $type    = $shop_types->find_by_slug($category->slug);
            $type_id = $type?->id ?? $shop_types->create([
                'name'         => $category->name,
                'slug'         => $category->slug,
                'accent_color' => $category->accent_color,
                'sort_order'   => $category->sort_order,
                'active'       => $category->active,
            ]);

            $categories->assign_shop_type($category->id, $type_id);
        }
    }

    /**
     * Frische Installation ohne jeden Shoptyp: einen neutralen "Allgemein"
     * anlegen, damit Kategorien/Shortcode sofort nutzbar sind. Keine
     * Akzentfarbe vorbelegt (Projektneutralitaet, Abschnitt 5.1).
     */
    private static function seed_default_shop_type(): void
    {
        (new ShopTypeRepository())->default_id();
    }
}
