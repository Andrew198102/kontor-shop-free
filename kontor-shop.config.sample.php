<?php

/**
 * Beispiel fuer eine Projekt-Konfiguration von Kontor Shop.
 *
 * Kopieren nach:
 *   - <child-theme>/kontor-shop.config.php   (empfohlen), oder
 *   - <theme>/kontor-shop.config.php, oder
 *   - wp-content/mu-plugins/kontor-shop.config.php
 *
 * Die zurueckgegebenen Werte bilden die Grundeinstellung. Im Admin
 * ("Kontor Shop" -> Einstellungen) koennen sie weiter ueberschrieben werden
 * (letzte Ebene gewinnt, siehe Settings\Repository). Nur die Schluessel
 * angeben, die vom Standard abweichen sollen - alle hier gezeigten Werte
 * entsprechen den eingebauten Defaults (Settings\Schema) und muessen daher
 * NICHT kopiert werden, wenn sie so passen.
 */

declare(strict_types=1);

return [
    'general' => [
        // leer lassen => WordPress-Seitentitel (get_bloginfo('name'))
        'shop_name' => '',
    ],

    // Mitgliedschaft wirkt nur mit Pro (ohne Pro ist niemand Mitglied).
    'membership' => [
        // 'null' = kein Provider (immer "kein Mitglied"). Echte Adapter
        // (WauWauX/ThePack) folgen mit P5.
        'provider' => 'null',
    ],

    'shipping' => [
        'flat_rate'         => 0.00,
        'free_for_members'  => false, // nur mit Pro
        'free_from_amount'  => 0.00, // 0 = deaktiviert
        'allow_self_pickup' => true,
    ],

    'payment' => [
        'bank_recipient'                        => '',
        'bank_iban'                              => '',
        'bank_bic'                               => '',
        'allow_invoice_on_delivery_for_members'  => false,
    ],

    'notifications' => [
        // 'wp' = wp_mail(). Echte Adapter (ABC_Hub/WauWauX) folgen mit P7.
        'mailer' => 'wp',
        // Vorlagentexte bitte im Admin unter "Mail-Vorlagen" pflegen statt
        // hier - dort mit Platzhalter-Legende je Ereignis.
    ],

    'design' => [
        // Leer lassen => Frontend-CSS nutzt seinen eingebauten, neutralen
        // Default. Kategorien mit eigener Akzentfarbe (Menue "Kategorien")
        // ueberschreiben diese Farbe fuer ihre Artikel.
        'primary_color'             => '',
        'show_floating_cart_button' => true,
    ],
];
