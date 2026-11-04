<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Health;

/**
 * Eine Pruefung der Betriebsampel (Uebersicht, Widget): Status, kurzer Titel,
 * Erklaerung in Alltagssprache und optional ein Knopf, der direkt zur
 * Stelle fuehrt, an der man es behebt.
 */
final class Check
{
    public const OK   = 'ok';
    public const INFO = 'info';
    public const WARN = 'warn';
    public const BAD  = 'bad';

    public function __construct(
        public readonly string $status,
        public readonly string $title,
        public readonly string $detail,
        public readonly string $action_label = '',
        public readonly string $action_url = '',
    ) {
    }

    public function is_problem(): bool
    {
        return $this->status === self::WARN || $this->status === self::BAD;
    }

    /** Sortierung: rot vor gelb vor Hinweis vor ok. */
    public function weight(): int
    {
        return match ($this->status) {
            self::BAD  => 0,
            self::WARN => 1,
            self::INFO => 2,
            default    => 3,
        };
    }
}
