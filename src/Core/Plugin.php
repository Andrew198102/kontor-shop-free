<?php

declare(strict_types=1);

namespace Kontor\Shop\Core;

use Kontor\Shop\Settings\Repository;

/**
 * Einstiegspunkt. Laedt die Einstellungen, baut die aktiven Module und
 * registriert alle Hooks. Genau einmal pro Request.
 */
final class Plugin
{
    private static ?self $instance = null;

    private bool $booted = false;

    private ?Repository $settings = null;

    private function __construct()
    {
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        Lifecycle::maybe_upgrade();

        $settings       = new Repository();
        $this->settings = $settings;

        /** @var array<int, Registerable> $services */
        $services = [
            new I18n(),
            new \Kontor\Shop\Admin\UninstallChoice(),
            new \Kontor\Shop\Admin\Upgrade\PricingPage(),
            new \Kontor\Shop\Admin\Upgrade\ProStatus(),
        ];

        foreach (Modules::active($settings) as $module) {
            $services[] = $module;
        }

        $services = apply_filters('kontor_shop/services', $services, $settings);

        foreach ($services as $service) {
            if ($service instanceof Registerable) {
                $service->register();
            }
        }

        do_action('kontor_shop/booted', $settings);
    }

    public function settings(): ?Repository
    {
        return $this->settings;
    }
}
