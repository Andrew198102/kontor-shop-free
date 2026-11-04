<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Agents\Mcp;

/**
 * Fehler, den der KI-Assistent sehen soll (falsche Eingabe, nicht erlaubt,
 * nicht gefunden). Die Meldung geht als Werkzeug-Ergebnis mit isError
 * zurueck, damit der Assistent sie lesen und sich korrigieren kann - anders
 * als technische Fehler, die nur mit Fehlercode gemeldet werden.
 */
final class ToolError extends \RuntimeException
{
    /**
     * @param array<string, mixed> $data zusaetzliche Angaben fuer den Assistenten
     */
    public function __construct(string $message, public readonly array $data = [])
    {
        parent::__construct($message);
    }
}
