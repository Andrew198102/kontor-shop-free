<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Agents\Mcp;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Was ein KI-Assistent statt einer Bilddatei schickt, wenn er die Datei nicht
 * uebergeben kann. ChatGPT reicht Dateien, die der Benutzer anhaengt, als
 * {download_url, file_id, ...} weiter - Bilder aus dem eigenen Bildgenerator
 * aber nur als Kennung ("file_0000..."), manchmal als Pfad im eigenen
 * Arbeitsbereich ("/mnt/data/...") oder "sediment://...". Unser Server kann
 * keins davon abholen (bekannte Grenze von ChatGPT, kein offizieller Weg).
 * Test-Shop 13.11.2026: sechs Fehlversuche einer Benutzerin in fuenf Stunden,
 * danach 25 Fotos von Hand hochgeladen.
 */
final class ChatFile
{
    public const GENERATOR_ID = 'generator_id';
    public const SANDBOX_PATH = 'sandbox_path';
    public const SEDIMENT     = 'sediment';
    public const NO_DOWNLOAD  = 'no_download';
    public const TEXT         = 'text';

    /**
     * Art eines nicht abholbaren Datei-Verweises; null = abholbar (Datei mit
     * download_url oder https-Adresse) oder leer.
     */
    public static function unusable(mixed $value): ?string
    {
        if (is_array($value)) {
            if ($value === [] || (isset($value['download_url']) && is_string($value['download_url']) && $value['download_url'] !== '')) {
                return null;
            }

            return self::NO_DOWNLOAD;
        }

        if (!is_string($value)) {
            return $value === null ? null : self::TEXT;
        }

        $value = trim($value);

        if ($value === '' || preg_match('#^https?://#i', $value) === 1) {
            return null;
        }

        if (preg_match('#^file[-_][A-Za-z0-9]+$#', $value) === 1) {
            return self::GENERATOR_ID;
        }

        if (str_starts_with($value, 'sediment://')) {
            return self::SEDIMENT;
        }

        if (str_starts_with($value, 'sandbox:') || str_contains($value, '/mnt/data/') || str_starts_with($value, '/mnt/')) {
            return self::SANDBOX_PATH;
        }

        return self::TEXT;
    }

    /** Fuers Protokoll: Art des Verweises auf Deutsch, ohne Inhalt. */
    public static function describe(string $kind, int $length = 0): string
    {
        return match ($kind) {
            self::GENERATOR_ID => 'nur eine Datei-Kennung (file_…), typisch fuer Bilder aus dem ChatGPT-Bildgenerator',
            self::SANDBOX_PATH => 'Pfad im ChatGPT-Arbeitsbereich (/mnt/data/…), fuer den Shop nicht erreichbar',
            self::SEDIMENT     => 'interner ChatGPT-Verweis (sediment://…)',
            self::NO_DOWNLOAD  => 'Datei ohne Download-Adresse',
            default            => 'Text mit ' . $length . ' Zeichen, kein Link',
        };
    }
}
