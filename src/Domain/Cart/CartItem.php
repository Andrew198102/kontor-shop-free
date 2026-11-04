<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Cart;

/**
 * Eine Warenkorb-Position: nur Artikel-ID, Variante (0 = ohne) + Menge.
 * Preise werden nie hier gespeichert, sondern bei jeder Anzeige/Berechnung
 * frisch aus dem aktuellen Artikel-/Variantendatensatz gelesen (Abschnitt 6.2).
 */
final class CartItem
{
    public function __construct(
        public readonly int $product_id,
        public readonly int $quantity,
        public readonly int $variant_id = 0,
    ) {
    }

    public function key(): string
    {
        return Cart::key($this->product_id, $this->variant_id);
    }
}
