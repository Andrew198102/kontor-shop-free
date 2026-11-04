<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Support;

/**
 * Entfernt persoenliche und geheime Daten aus Texten, bevor sie in einen
 * Fehlerbericht wandern (Admin\ReportPage). Das Fehlerprotokoll speichert
 * schon keine Kontext-Felder mit Namen/Adressen (Logger::sanitize_context),
 * Meldungstexte und Adressen koennen aber trotzdem welche enthalten.
 * Lieber zu viel schwaerzen als zu wenig - der Bericht landet oeffentlich.
 */
final class Redactor
{
    /**
     * @param string $site_host Host der Website (wird zu [site])
     * @param string $abspath   WordPress-Pfad auf dem Server (wird zu [wp]/)
     */
    public function __construct(private readonly string $site_host = '', private readonly string $abspath = '')
    {
    }

    public function text(string $text): string
    {
        if ($this->abspath !== '') {
            $text = str_replace(rtrim($this->abspath, '/') . '/', '[wp]/', $text);
        }

        // Abfrageteile von Adressen koennen Token enthalten (z. B. Bestaetigungslinks).
        $text = (string) preg_replace('~(https?://[^\s?#"\'<>]+)\?[^\s"\'<>]*~i', '$1?[…]', $text);

        if ($this->site_host !== '') {
            $text = str_ireplace($this->site_host, '[site]', $text);
        }

        $patterns = [
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i'                          => '[email]',
            '/\b[A-Z]{2}\d{2}(?: ?[A-Z0-9]{4}){2,7}(?: ?[A-Z0-9]{1,4})?\b/'       => '[iban]',
            '/\b(?:Bearer|Basic)\s+[A-Za-z0-9._~+\/=\-]+/i'                       => '[auth]',
            '/\b(?:sk|pk|ghp|gho|github_pat|abc_ev)_[A-Za-z0-9_]{8,}/'            => '[secret]',
            '/\b[A-Fa-f0-9]{32,}\b/'                                              => '[secret]',
            '/\b(?:\d{1,3}\.){3}\d{1,3}\b/'                                       => '[ip]',
            '/(?<![\w\/.:-])(?:\+|00)\d[\d \/\-]{7,}\d/'                          => '[phone]',
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $text);
    }

    /**
     * Kontext eines Protokolleintrags (JSON) geschwaerzt und gekuerzt.
     */
    public function context(?string $json, int $max = 600): string
    {
        if ($json === null || $json === '' || $json === '[]') {
            return '';
        }

        $text = $this->text($json);

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '…' : $text;
    }
}
