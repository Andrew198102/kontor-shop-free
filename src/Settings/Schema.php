<?php

declare(strict_types=1);

namespace Kontor\Shop\Settings;

use Kontor\Shop\Core\Modules;
use Kontor\Shop\Infrastructure\Notifications\MailerFactory;

/**
 * Eingebaute Standardwerte fuer die Plugin-Einstellungen.
 *
 * Seit P2 der Mitgliedschafts-Provider, seit P4 Versand-Regelwerk (Abschnitt
 * 6.4) und Zahlungs-/Bankdaten (Abschnitt 6.3), seit P6 Mailer-Auswahl +
 * Mail-Vorlagen (Abschnitt 9).
 */
final class Schema
{
    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        $defaults = [
            'modules' => Modules::default_states(),

            'general' => [
                'shop_name'  => '', // leer => get_bloginfo('name')
                // Zeile "Vereinsshop mit Kontor Shop" unter Mails/Bestellbestaetigung (nur Free,
                // Modules\Notifications\PoweredBy): null = noch nicht gefragt, true/false = Antwort.
                'powered_by' => null,
            ],

            'shipping' => [
                'flat_rate'         => 0.00,
                'free_from_amount'  => 0.00, // 0 = deaktiviert
                'allow_self_pickup' => true,
                // Versand bei aufgeteilten Bestellungen (Modules\Shipping\SplitShipping):
                // separate | unified | custom (mit split_flat_rate).
                'split_mode'        => 'separate',
                'split_flat_rate'   => 0.00,
            ],

            'payment' => [
                // Liste von Bankkonten: [id, label, recipient, iban, bic]. Das erste ist das
                // Standardkonto; Lager koennen einem Konto zugeordnet werden.
                'accounts'       => [],
                // Angebotene Zahlungsarten (Modules\Payments\PaymentOptions). Mindestens eine muss fuer alle gelten.
                // (Mitgliedschaft, Mitglieder-Freiversand und "Rechnung nur fuer Mitglieder": Pro, Pro::defaults().)
                'prepayment_enabled'   => true,
                'invoice_enabled'      => false,
                // Barzahlung bei Abholung (nur mit Selbstabholung); aus, bis der Verein es einschaltet.
                'cash_enabled'         => false,
                // Ueberweisungs-QR-Code (EPC/GiroCode) auf der Bestellseite, Hinweis darauf in der Mail.
                'qr_enabled'           => true,
                // Auf Rechnung: Zahlungsziel in Tagen ab Versand/Abholung (0 = keines), danach gilt sie als
                // ueberfaellig. Nie Autostorno. (Automatische Erinnerungen: Pro, Pro::defaults().)
                'invoice_days'              => 14,
                // Altbestand (vor "Auf Rechnung"): gilt als invoice_enabled (mit Pro: nur fuer Mitglieder), bis neu gespeichert.
                'allow_invoice_on_delivery_for_members' => false,
                // Zahlungsfrist Vorkasse in Tagen (0 = keine); danach gilt eine offene Bestellung als ueberfaellig.
                'payment_days'   => 14,
            ],

            'notifications' => [
                'mailer'                    => MailerFactory::WP_MAILER,
                // Nur vom Admin geaenderte Vorlagen; sonst gelten die eingebauten Standardtexte (DefaultTemplates).
                'templates'                 => [],
                // "Neue Bestellung"-Mail an den Shop-Betreiber (Fallback ohne Integration):
                // leer = WordPress-Admin-E-Mail, mehrere Adressen per Komma.
                'admin_notify_enabled'      => true,
                'admin_notify_email'        => '',
                // Absender der Shop-Mails (leer = Vorgabe von WordPress/SMTP-Plugin) und Antwortadresse.
                'from_name'                 => '',
                'from_email'                => '',
                'reply_to'                  => '',
            ],

            'tax' => [
                // Verfuegbare Umsatzsteuersaetze in Prozent (z. B. [20.0, 10.0]). Leer =
                // der Shop ist umsatzsteuerfrei, es wird keine USt ausgewiesen. Preise
                // im Shop sind Endkundenpreise (brutto).
                'rates'        => [],
                // Vorauswahl beim Anlegen neuer Artikel ('' = keine USt).
                'default_rate' => '',
                // Leer = uebersetzter Standardtext (Settings\TextDefaults).
                'exempt_note'  => '',
                // Umsatzsteuer auf Versandkosten: proportional | fixed | none (siehe Domain\Tax\TaxCalculator).
                'shipping_mode' => 'proportional',
                'shipping_rate' => '',
            ],

            'privacy' => [
                // Aufbewahrungsfrist fuer Bestelldaten in Jahren (danach Anonymisierung auf Anfrage
                // ueber die WP-Datenschutz-Werkzeuge). Die Anonymisierung nach Fristablauf loest
                // nie automatisch etwas aus - Loeschen bleibt eine manuelle Handlung.
                'retention_years' => 7,
            ],

            'logging' => [
                // Mindeststufe, ab der ins Protokoll geschrieben wird: debug | info | warning | error | critical.
                'level'          => 'warning',
                'retention_days' => 30,
            ],

            'checkout' => [
                // Telefonnummer im Checkout: off | optional | required
                'phone'           => 'optional',
                // Abweichende Rechnungsadresse (Firma/UID) anbieten
                'billing_address' => true,
                // Erlaubte Lieferlaender (ISO-Codes); Selbstabholung ist davon unabhaengig.
                'countries'       => ['AT', 'DE'],
                // Wie lange die Ware ab dem Oeffnen der Kasse fuer den Kunden reserviert bleibt (5-60).
                'reservation_minutes' => 15,
                // Bestellstatus-Link fuer Kunden: gueltig, solange die Bestellung laeuft, danach noch so viele Tage.
                'order_link_days'     => 30,
                // Spamschutz: hoechstens so viele Bestellungen je Internetanschluss in 15 Minuten (0 = keine Grenze).
                // Angemeldete Benutzer zaehlen je Konto, nicht je Anschluss (Vereinsabend im selben WLAN).
                'rate_limit'          => 20,
            ],

            'catalog' => [
                'search_enabled' => true,
            ],

            'legal' => [
                // Seiten-IDs (0 = nicht gesetzt -> nur Klartext, kein Link).
                'terms_page_id'      => 0,
                'withdrawal_page_id' => 0,
                'privacy_page_id'    => 0,
                'imprint_page_id'    => 0,
                'require_terms'      => true,
                'require_privacy'    => true,
                // Leer = uebersetzter Standardtext (Settings\TextDefaults); {agb}/{widerruf}/{datenschutz} werden Links.
                'terms_label'        => '',
                'privacy_label'      => '',
                'price_note'         => '', // optionaler Zusatztext; die USt-Zeile entsteht automatisch aus den Steuer-Einstellungen
                'order_button_label' => '',
            ],

            // PDF-Rechnungen (Modules\Invoices). Aus, bis der Verein Absenderdaten eintraegt und einschaltet.
            'invoices' => [
                'enabled'        => false,
                // paid = beim Zahlungseingang | shipped = beim Versand/bei der Abholung | manual = nur per Knopf
                // (auf Rechnung und bar bei Abholung immer erst mit dem Versand bzw. der Abholung).
                'trigger'        => 'paid',
                'send_email'     => true,
                'seller_name'    => '',
                'seller_address' => '',
                'seller_vat_id'  => '',
                // Weitere Pflichtangaben, z. B. ZVR-Zahl, Firmenbuch, Steuernummer.
                'seller_extra'   => '',
                // Hinweis bei steuerfreien Positionen (leer = Hinweis aus Zahlung & Umsatzsteuer).
                'exempt_note'    => '',
            ],

            'design' => [
                // Leer = Frontend-CSS nutzt seinen eingebauten, neutralen
                // Default (kein Projektbezug im Code, siehe Abschnitt 5.1).
                'primary_color'             => '',
                'show_floating_cart_button' => true,
                // Leer = Icon nutzt primary_color (bzw. deren Fallback).
                'cart_icon_color'           => '',
                // Icon erst einblenden, sobald der Warenkorb Artikel enthaelt.
                'hide_cart_icon_when_empty' => false,
                // Attachment-ID eines eigenen Platzhalterbilds fuer Artikel
                // ohne Foto (0 = neutrales eingebautes Symbol).
                'placeholder_image_id'      => 0,
                // Hover-Effekt der Artikelkarten: spotlight (Rand-Licht in der
                // Akzentfarbe) | lift (nur Anheben) | none (ruhig).
                'card_effect'               => 'spotlight',
                // Grundstil (Ecken, Schatten): theme | clear | soft | square.
                'style'                     => 'theme',
                // Ecken in px; leer = wie Grundstil bzw. Theme.
                'radius'                    => '',
                // Farbschema: theme (dunkel nur bei dunklem Theme) | light | dark.
                'scheme'                    => 'theme',
                // Hoechstens so viele Karten pro Zeile: auto | 2 | 3 | 4.
                'columns'                   => 'auto',
                // Seitenverhaeltnis der Artikelbilder: 4/3 | 1/1 | 3/4.
                'image_ratio'               => '4/3',
                // Schrift der Artikelnamen: theme (Ueberschrift des Themes, falls erkennbar) | body.
                'heading_font'              => 'theme',
                // Eigenes CSS (nur mit Recht edit_css), wird nach shop.css geladen.
                'custom_css'                => '',
            ],

            // KI-Assistenten ueber MCP (Modules\Agents). Beide Zugaenge sind aus, bis sie jemand einschaltet.
            'agents' => [
                // Oeffentlicher Shop-Zugang fuer die Assistenten von Kunden (nur oeffentliche Daten).
                'shop_enabled'  => false,
                // Pro: angemeldeter Zugang fuer das Shop-Team (Artikel, Bilder, Lager, Bestellungen).
                'admin_enabled' => false,
            ],
        ];

        /** @var array<string, mixed> $defaults */
        $defaults = apply_filters('kontor_shop/settings/defaults', $defaults);

        return $defaults;
    }
}
