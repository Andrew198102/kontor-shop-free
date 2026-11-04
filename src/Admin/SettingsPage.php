<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin;

use Kontor\Shop\Admin\Settings\AgentsSection;
use Kontor\Shop\Admin\Settings\DesignSection;
use Kontor\Shop\Admin\Settings\LegalSection;
use Kontor\Shop\Admin\Settings\MailSection;
use Kontor\Shop\Admin\Settings\PaymentSection;
use Kontor\Shop\Admin\Settings\RolesSection;
use Kontor\Shop\Admin\Settings\Section;
use Kontor\Shop\Admin\Settings\ShippingSection;
use Kontor\Shop\Admin\Settings\SystemSection;
use Kontor\Shop\Admin\Settings\TemplatesSection;
use Kontor\Shop\Admin\Ui\Flash;
use Kontor\Shop\Settings\Repository;

/**
 * Einstellungen: linke Navigation mit vier Gruppen (Verkaufen,
 * Kommunikation, Shop-Auftritt, Technik), rechts der gewaehlte Bereich.
 * Jeder Bereich ist eine eigene Klasse unter Admin\Settings\ und speichert
 * seine Formulare selbst; diese Klasse verteilt nur Anfragen und zeichnet
 * den Rahmen. Am Handy wird die Navigation zu einer Auswahlliste.
 *
 * Alte Reiter-Schluessel (?tab=tax, policies_returns ...) fuehren weiter auf
 * den passenden Bereich. "Mitgliedschaft" und "Shoptypen" kommen mit Pro
 * (kontor_shop/settings/sections).
 */
final class SettingsPage
{
    public const SLUG         = 'kontor-shop-settings';
    private const DEFAULT_TAB = 'payment';

    /** @var array<string, Section> */
    private array $sections = [];

    public function __construct(private readonly Repository $settings)
    {
        foreach ([
            new PaymentSection($settings),
            new ShippingSection($settings),
            new LegalSection($settings),
            new MailSection($settings),
            new TemplatesSection($settings),
            new DesignSection($settings),
            new RolesSection($settings),
            new AgentsSection($settings),
            new SystemSection($settings),
        ] as $section) {
            $this->sections[$section->key()] = $section;
        }

        // Erweiterungspunkt: Add-ons haengen eigene Bereiche an (Unterklassen von Admin\Settings\Section,
        // Gruppe ueber group(), z. B. Section::GROUP_TECH). Nur Section-Objekte zaehlen; ein entfernter
        // eingebauter Bereich kommt hinten wieder dazu, damit alte Links und der Standardbereich gueltig bleiben.
        $sections = [];

        foreach ((array) apply_filters('kontor_shop/settings/sections', $this->sections, $settings) as $section) {
            if ($section instanceof Section) {
                $sections[$section->key()] = $section;
            }
        }

        $this->sections = $sections + $this->sections;
    }

    /**
     * Schluessel aller Bereiche (inkl. Erweiterungen), z. B. fuer dev/bin/smoke.
     *
     * @return array<int, string>
     */
    public function section_keys(): array
    {
        return array_keys($this->sections);
    }

    /**
     * @param array<string, mixed> $args
     */
    public static function url(string $tab = '', array $args = []): string
    {
        return Section::url($tab !== '' ? $tab : self::DEFAULT_TAB, $args);
    }

    /**
     * Speichern auf load-{hook} (vor jeder Ausgabe), danach Umleitung auf den
     * selben Bereich (Post/Redirect/Get) - ein Neuladen sendet nichts erneut.
     */
    public function handle_request(): void
    {
        if (!current_user_can(Menu::SETTINGS_CAPABILITY)) {
            wp_die(esc_html__('No permission.', 'kontor-shop'));
        }

        $this->current()->handle_request();

        $method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';

        if ($method !== 'POST' || !isset($_POST['kontor_shop_settings_nonce'])) {
            return;
        }

        check_admin_referer(Section::NONCE_ACTION, 'kontor_shop_settings_nonce');

        $tab     = isset($_POST['tab']) ? sanitize_key(wp_unslash((string) $_POST['tab'])) : '';
        $section = $this->section_for_post($tab);

        if ($section === null) {
            return;
        }

        $notice = $section->save($tab);

        foreach ($section->warnings() as $warning) {
            Flash::add($warning, 'warning');
        }

        if ($notice === null && $section->warnings() === []) {
            return;
        }

        Flash::redirect(self::url($section->key()), $notice);
    }

    public function render(): void
    {
        if (!current_user_can(Menu::SETTINGS_CAPABILITY)) {
            wp_die(esc_html__('No permission.', 'kontor-shop'));
        }

        $current = $this->current();

        echo '<div class="wrap ks-wrap ks-settings-wrap">';
        echo '<div class="ks-page-head"><div class="ks-page-head__title"><h1 class="wp-heading-inline">' . esc_html__('Settings', 'kontor-shop') . '</h1></div></div><hr class="wp-header-end" />';

        Flash::render();

        echo '<div class="ks-settings">';
        $this->render_nav($current->key());

        echo '<div class="ks-settings-main"><div class="ks-settings-main__head"><h2 class="ks-settings-main__title">' . esc_html($current->label()) . '</h2>';

        if ($current->description() !== '') {
            echo '<p class="ks-settings-main__desc">' . esc_html($current->description()) . '</p>';
        }

        echo '</div>';
        $current->render();

        foreach ($current->forms() as $form) {
            echo '<h2 class="ks-group-title">' . esc_html($form->label()) . '</h2>';
            $form->render();
        }

        echo '</div></div></div>';
    }

    private function current(): Section
    {
        $tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : self::DEFAULT_TAB; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige-Parameter
        $tab = self::alias($tab);

        return $this->sections[$tab] ?? $this->sections[self::DEFAULT_TAB];
    }

    /**
     * Fruehere, feingranulare Reiter-Schluessel (alte Lesezeichen, Links in Mails).
     */
    public static function alias(string $tab): string
    {
        return match ($tab) {
            'tax'                          => 'payment',
            'policies', 'policies_returns' => 'shipping',
            'policies_warranty'            => 'legal',
            default                        => $tab,
        };
    }

    private function section_for_post(string $tab): ?Section
    {
        foreach ($this->sections as $section) {
            if (in_array($tab, $section->handles(), true)) {
                return $section;
            }

            foreach ($section->forms() as $form) {
                if (in_array($tab, $form->handles(), true)) {
                    return $form;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private static function groups(): array
    {
        return [
            Section::GROUP_SELL => __('Sell', 'kontor-shop'),
            Section::GROUP_TALK => __('Communication', 'kontor-shop'),
            Section::GROUP_SHOP => __('Shop appearance', 'kontor-shop'),
            Section::GROUP_TECH => __('Technical', 'kontor-shop'),
        ];
    }

    private function render_nav(string $active): void
    {
        // Bereiche mit offenen Pflichtschritten der Einrichtung markieren.
        $todo = [];

        foreach ((new SetupChecklist($this->settings))->open_required() as $step) {
            if (preg_match('/[?&]tab=(\w+)/', $step['url'], $m) === 1) {
                $todo[self::alias($m[1])] = true;
            }
        }

        echo '<nav class="ks-settings-nav" aria-label="' . esc_attr__('Settings sections', 'kontor-shop') . '">';

        foreach (self::groups() as $group => $group_label) {
            echo '<div class="ks-settings-nav__group"><span class="ks-settings-nav__title">' . esc_html($group_label) . '</span><ul>';

            foreach ($this->sections as $key => $section) {
                if ($section->group() !== $group) {
                    continue;
                }

                echo '<li><a href="' . esc_url(self::url($key)) . '"' . ($key === $active ? ' class="is-current" aria-current="page"' : '') . '>'
                    . esc_html($section->label())
                    . (isset($todo[$key]) ? ' <span class="ks-settings-nav__todo" title="' . esc_attr__('Something is still missing for the setup.', 'kontor-shop') . '">' . esc_html__('open', 'kontor-shop') . '</span>' : '')
                    . '</a></li>';
            }

            echo '</ul></div>';
        }

        echo '</nav>';

        // Handy: Auswahlliste statt Navigation (ohne JavaScript mit "Öffnen").
        echo '<form method="get" class="ks-settings-jump"><input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '" />'
            . '<label for="ks-settings-jump">' . esc_html__('Area', 'kontor-shop') . '</label><select id="ks-settings-jump" name="tab" data-ks-autosubmit>';

        foreach (self::groups() as $group => $group_label) {
            echo '<optgroup label="' . esc_attr($group_label) . '">';

            foreach ($this->sections as $key => $section) {
                if ($section->group() === $group) {
                    echo '<option value="' . esc_attr($key) . '" ' . selected($key, $active, false) . '>' . esc_html($section->label() . (isset($todo[$key]) ? ' • ' . __('open', 'kontor-shop') : '')) . '</option>';
                }
            }

            echo '</optgroup>';
        }

        echo '</select><noscript><button type="submit" class="button">' . esc_html__('Go', 'kontor-shop') . '</button></noscript></form>';
    }
}
