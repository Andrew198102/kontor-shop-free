<?php

declare(strict_types=1);

namespace Kontor\Shop\Settings;

/**
 * Uebersetzbare Standardtexte fuer Felder, die der Verein selbst
 * formulieren kann. Im Schema stehen diese Felder leer - die Einstellungen
 * werden teils vor dem Laden der Sprachdatei aufgeloest, deshalb wird der
 * Standard erst beim Anzeigen in der Sprache der Website geholt. Ein
 * gespeicherter eigener Text hat immer Vorrang. Bis 2.1.1 standen hier
 * deutsche Texte fest im Schema - auf englischen Seiten falsch.
 */
final class TextDefaults
{
    /** Platzhalter {agb} und {widerruf} werden zu Links (Modules\Checkout\LegalText). */
    public static function terms_label(): string
    {
        return __('I have read the {agb} and the {widerruf} and accept them.', 'kontor-shop');
    }

    /** Platzhalter {datenschutz} wird zum Link. */
    public static function privacy_label(): string
    {
        return __('I have read the {datenschutz} and agree that my data is processed to handle the order.', 'kontor-shop');
    }

    public static function order_button_label(): string
    {
        return __('Place order with obligation to pay', 'kontor-shop');
    }

    public static function exempt_note(): string
    {
        return __('VAT exempt – no VAT is charged.', 'kontor-shop');
    }

    /** Gespeicherter Text oder - wenn leer - der Standard. */
    public static function or_default(string $stored, string $default): string
    {
        $stored = trim($stored);

        return $stored !== '' ? $stored : $default;
    }
}
