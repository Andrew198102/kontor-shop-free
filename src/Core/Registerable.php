<?php

declare(strict_types=1);

namespace Kontor\Shop\Core;

/**
 * Alles, was sich beim Boot in WordPress einhaengt (Hooks registriert).
 * Kuenftige Module (Catalog, Cart, Checkout, ...) implementieren diese
 * Schnittstelle ebenso wie einfache Basisdienste (z. B. I18n).
 */
interface Registerable
{
    public function register(): void;
}
