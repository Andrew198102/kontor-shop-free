<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Checkout;

/**
 * Eine Erweiterung bricht das Bestellen ab, weil eine Bedingung unter Sperre
 * nicht mehr gilt (z. B. Pro: ein Einmal-Rabattcode wurde eine Sekunde frueher
 * von jemand anderem eingeloest). Der Checkout rollt dann alles zurueck, gibt
 * die Reservierung frei und zeigt dem Kunden public_message - ohne
 * Fehlerprotokoll, es ist kein technischer Fehler.
 *
 * Werfen aus kontor_shop/checkout/before_orders oder
 * kontor_shop/checkout/order_created_in_tx (Modules\Checkout\CheckoutModule).
 */
class CheckoutAborted extends \RuntimeException
{
    /**
     * @param string $public_message Text fuer den Kunden (schon uebersetzt, wird beim Anzeigen escaped)
     */
    public function __construct(public readonly string $public_message, string $log_message = '')
    {
        parent::__construct($log_message !== '' ? $log_message : $public_message);
    }
}
