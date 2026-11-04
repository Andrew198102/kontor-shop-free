<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin\Ui;

/**
 * Rueckmeldungen ueber einen Redirect hinweg (Post/Redirect/Get): Nach jedem
 * Speichern/Aktion leitet die Seite um, damit ein Neuladen nichts doppelt
 * ausfuehrt. Die Meldung liegt kurz in einem Transient je Benutzer und wird
 * beim naechsten Seitenaufruf einmalig angezeigt.
 */
final class Flash
{
    private const TTL = 300;

    private static function key(): string
    {
        return 'kontor_shop_flash_' . get_current_user_id();
    }

    /**
     * @return array{messages: array<int, array{type: string, text: string}>, data: array<string, mixed>}
     */
    private static function load(): array
    {
        $stored = get_transient(self::key());

        return is_array($stored)
            ? ['messages' => (array) ($stored['messages'] ?? []), 'data' => (array) ($stored['data'] ?? [])]
            : ['messages' => [], 'data' => []];
    }

    public static function add(string $text, string $type = 'success'): void
    {
        $state               = self::load();
        $state['messages'][] = ['type' => $type, 'text' => $text];

        set_transient(self::key(), $state, self::TTL);
    }

    public static function set(string $name, mixed $value): void
    {
        $state                 = self::load();
        $state['data'][$name] = $value;

        set_transient(self::key(), $state, self::TTL);
    }

    public static function take(string $name): mixed
    {
        $state = self::load();
        $value = $state['data'][$name] ?? null;

        if ($value !== null) {
            unset($state['data'][$name]);
            set_transient(self::key(), $state, self::TTL);
        }

        return $value;
    }

    /**
     * Gespeicherte Meldungen als WordPress-Notices ausgeben und verwerfen.
     */
    public static function render(): void
    {
        $state = self::load();

        if ($state['messages'] === []) {
            return;
        }

        $messages          = $state['messages'];
        $state['messages'] = [];

        if ($state['data'] === []) {
            delete_transient(self::key());
        } else {
            set_transient(self::key(), $state, self::TTL);
        }

        foreach ($messages as $notice) {
            echo '<div class="notice notice-' . esc_attr((string) $notice['type']) . ' is-dismissible"><p>' . esc_html((string) $notice['text']) . '</p></div>';
        }
    }

    /**
     * Meldung vormerken und zur Zielseite umleiten (beendet den Request).
     */
    public static function redirect(string $url, ?string $message = null, string $type = 'success'): never
    {
        if ($message !== null && $message !== '') {
            self::add($message, $type);
        }

        wp_safe_redirect($url);
        exit;
    }
}
