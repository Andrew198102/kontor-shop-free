<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Agents\Mcp;

use Kontor\Shop\Infrastructure\Audit\AuditLog;
use Kontor\Shop\Infrastructure\Logging\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Abgelehnte KI-Aufrufe ins Shop-Protokoll (Stufe "Warnung", Kanal "agents").
 *
 * Der Assistent bekommt die Meldung zurueck und soll sich korrigieren - der
 * Shop-Betreiber sah davon bisher nichts. Scheitert z. B. ein Bild-Upload,
 * steht hier, welches Werkzeug, welcher Zugang, welche Meldung und in welcher
 * Form das Bild ankam (Datei, Link, nichts).
 *
 * Datenschutz: Von den Eingaben stehen nur die Feldnamen, IDs und eine
 * Beschreibung der Bildangaben im Protokoll - keine Texte (Kundendaten,
 * Notizen) und keine Download-Adressen (die enthalten Zugangsschluessel).
 */
final class ToolErrorLog
{
    /** Ganze Zahlen, die beim Nachvollziehen helfen. */
    private const IDS = ['product_id', 'image_id', 'variant_id', 'order_id', 'category_id', 'warehouse_id', 'coupon_id'];

    public static function record(string $tool, mixed $arguments, ToolError $error): void
    {
        // Rueckfrage vor einer Aenderung (confirm_token) ist kein Fehler.
        if (!empty($error->data['needs_confirmation'])) {
            return;
        }

        Logger::warning('agents', sprintf('KI-Werkzeug %s abgelehnt: %s', $tool, $error->getMessage()), [
            'zugang'  => AuditLog::actor() ?? '',
            'eingabe' => self::summary(is_array($arguments) ? $arguments : []),
        ]);
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function summary(array $args): array
    {
        $out = ['felder' => $args !== [] ? implode(', ', array_map('strval', array_keys($args))) : '(keine)'];

        foreach (self::IDS as $key) {
            if (isset($args[$key]) && is_scalar($args[$key])) {
                $out[$key] = $args[$key];
            }
        }

        if (array_key_exists('image', $args)) {
            $out['bild'] = self::describe_file($args['image']);
        }

        foreach (['image_url', 'url'] as $key) {
            if (isset($args[$key]) && is_string($args[$key])) {
                $out['bild_link'] = self::describe_link($args[$key]);
            }
        }

        if (isset($args['data_base64']) && is_string($args['data_base64'])) {
            $out['base64'] = strlen($args['data_base64']) . ' Zeichen';
        }

        if (isset($args['images']) && is_array($args['images'])) {
            $out['bilder'] = implode('; ', array_map(static function ($image): string {
                if (is_array($image) && isset($image['image_id'])) {
                    return 'image_id ' . (int) $image['image_id'];
                }

                if (is_array($image) && isset($image['url']) && is_string($image['url'])) {
                    $kind = ChatFile::unusable($image['url']);

                    return $kind === null ? self::describe_link($image['url']) : ChatFile::describe($kind, strlen($image['url']));
                }

                return 'unbrauchbar (' . gettype($image) . ')';
            }, array_slice($args['images'], 0, 10)));
        }

        return $out;
    }

    /** Datei aus dem Gespraech: ChatGPT schickt {download_url, file_id, mime_type, file_name}. */
    private static function describe_file(mixed $file): string
    {
        if (is_array($file)) {
            $parts = ['Datei mit ' . ($file !== [] ? implode(', ', array_map('strval', array_keys($file))) : 'leerem Inhalt')];

            if (isset($file['download_url']) && is_string($file['download_url'])) {
                $parts[] = 'Download von ' . self::describe_link($file['download_url']);
            }

            if (isset($file['mime_type']) && is_string($file['mime_type'])) {
                $parts[] = 'Typ ' . substr($file['mime_type'], 0, 40);
            }

            return implode(', ', $parts);
        }

        if (is_string($file)) {
            if (preg_match('#^https?://#i', $file) === 1) {
                return 'Link ' . self::describe_link($file);
            }

            return $file === '' ? 'leer' : ChatFile::describe((string) ChatFile::unusable($file), strlen($file));
        }

        return 'unbrauchbar (' . gettype($file) . ')';
    }

    /** Nur Rechner und Dateiendung - Pfad und Abfrage koennen Zugangsschluessel enthalten. */
    private static function describe_link(string $url): string
    {
        $host = (string) wp_parse_url($url, PHP_URL_HOST);

        if ($host === '') {
            return 'keine gueltige Adresse';
        }

        $extension = strtolower((string) pathinfo((string) wp_parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return $host . ($extension !== '' && strlen($extension) <= 5 ? ' (.' . $extension . ')' : '');
    }
}
