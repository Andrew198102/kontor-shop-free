<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Seo;

final class RouteDecision
{
    public const OK          = 200;
    public const MOVED       = 301;
    public const NOT_FOUND   = 404;
    public const GONE        = 410;
    public const UNAVAILABLE = 503;

    public function __construct(
        public readonly int $status,
        public readonly ?string $location = null,
        public readonly ?PublicProduct $product = null,
    ) {
    }
}
