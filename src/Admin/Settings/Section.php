<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin\Settings;

use Kontor\Shop\Settings\Repository;

/**
 * Ein Bereich der Einstellungen (linke Navigation in Admin\SettingsPage).
 * Jeder Bereich zeichnet seine Formulare selbst und speichert die
 * POST-"tab"-Schluessel, die er in handles() angibt. Nach dem Speichern
 * leitet SettingsPage zurueck auf den Bereich (Post/Redirect/Get).
 */
abstract class Section
{
    public const NONCE_ACTION = 'kontor_shop_settings_save';

    public const GROUP_SELL  = 'sell';
    public const GROUP_TALK  = 'talk';
    public const GROUP_SHOP  = 'shop';
    public const GROUP_TECH  = 'tech';

    /** @var array<int, string> Nicht blockierende Hinweise nach dem Speichern (z. B. IBAN-Pruefung) */
    protected array $warnings = [];

    /** @var array<int, Section>|null */
    private ?array $forms = null;

    public function __construct(protected readonly Repository $settings)
    {
    }

    /** Schluessel in der URL (?tab=...) */
    abstract public function key(): string;

    abstract public function label(): string;

    abstract public function group(): string;

    /** Ein Satz unter der Ueberschrift: wofuer der Bereich da ist. */
    public function description(): string
    {
        return '';
    }

    /**
     * POST-"tab"-Werte, die dieser Bereich speichert (Formulare innerhalb des Bereichs).
     *
     * @return array<int, string>
     */
    public function handles(): array
    {
        return [$this->key()];
    }

    /**
     * Speichert ein Formular des Bereichs; liefert die Erfolgsmeldung oder
     * null (nichts gespeichert bzw. nur Warnungen).
     */
    public function save(string $tab): ?string
    {
        return null;
    }

    /** Eigene Anfragen vor der Ausgabe (z. B. Liste mit Anlegen/Loeschen); laeuft auf load-{hook}. */
    public function handle_request(): void
    {
    }

    abstract public function render(): void;

    /**
     * Weitere Formulare am Ende des Bereichs (von Erweiterungen). Jedes ist
     * selbst ein Section-Objekt: key() = Schluessel dieses Bereichs (dorthin
     * wird nach dem Speichern zurueckgeleitet), label() = Ueberschrift,
     * handles()/save()/render() wie hier.
     *
     * @return array<int, Section>
     */
    public function forms(): array
    {
        /**
         * Filter kontor_shop/settings/forms: weitere Formulare am Ende eines
         * Einstellungsbereichs, z. B. Pro: "Rueckgabe & Lieferzeit" unter
         * Versand und "Gewaehrleistung" unter Rechtliches. SettingsPage
         * zeichnet sie unter dem Bereich (Ueberschrift = label()) und gibt
         * POST-Formulare mit ihren handles() an save(). Ein Free-Formular
         * schreibt so nie Pro-Schluessel. Standard: keine.
         *
         * @param array<int, Section> $forms
         * @param Section             $section der Bereich (key())
         */
        return $this->forms ??= array_values(array_filter(
            (array) apply_filters('kontor_shop/settings/forms', [], $this),
            fn ($form): bool => $form instanceof self && $form !== $this && $form->key() === $this->key()
        ));
    }

    /**
     * @return array<int, string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @param array<string, mixed> $args
     */
    public static function url(string $tab, array $args = []): string
    {
        return add_query_arg(array_map(static fn ($value): string => rawurlencode((string) $value), array_merge(['page' => 'kontor-shop-settings', 'tab' => $tab], $args)), admin_url('admin.php'));
    }

    /**
     * Formular mit Nonce und Reiter-Feld oeffnen; $tab ist der POST-Schluessel
     * (kann von key() abweichen, wenn ein Bereich mehrere Formulare hat).
     */
    protected function form_open(string $tab, string $class = ''): void
    {
        echo '<form method="post" action="' . esc_url(self::url($this->key())) . '" data-ks-dirty class="ks-settings-section' . ($class !== '' ? ' ' . esc_attr($class) : '') . '">';
        wp_nonce_field(self::NONCE_ACTION, 'kontor_shop_settings_nonce');
        echo '<input type="hidden" name="tab" value="' . esc_attr($tab) . '" />';
    }

    /**
     * Speichern-Leiste am Ende eines Formulars - klebt am unteren Bildschirmrand,
     * solange das Formular sichtbar ist, und zeigt ungespeicherte Aenderungen an.
     */
    protected function savebar(string $label = ''): void
    {
        echo '<div class="ks-savebar"><span class="ks-dirty-flag" data-ks-dirty-flag hidden>' . esc_html__('Unsaved changes', 'kontor-shop') . '</span>'
            . '<button type="submit" class="button button-primary button-large">' . esc_html($label !== '' ? $label : __('Save', 'kontor-shop')) . '</button></div>';
    }

    /** Kurzer Erklaertext am Anfang eines Formulars. */
    protected function intro(string $text): void
    {
        echo '<div class="ks-help-box"><p>' . esc_html($text) . '</p></div>';
    }

    /** Ueberschrift eines eigenstaendigen Themenblocks innerhalb des Bereichs. */
    protected function group_title(string $title): void
    {
        echo '<h2 class="ks-group-title">' . esc_html($title) . '</h2>';
    }
}
