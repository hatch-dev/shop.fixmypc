<?php

namespace App\Exceptions;

/**
 * Thrown while an order is being placed, when a line's stock went to another
 * order between the availability check and the moment its units were taken.
 *
 * A basket whose reservation ran out no longer holds its units, so it can
 * race another order for the last one.
 * The units are taken with a conditional decrement - only if enough are still
 * there - and the loser gets this, which OrdersController turns into the same
 * per-line "out of stock" refusal the up-front check gives, after rolling the
 * part-built order back.
 */
class StockRanOut extends \RuntimeException
{
    public $cartId;

    public $productTitle;

    public function __construct($cartId, $productTitle)
    {
        parent::__construct("Stock ran out for cart line {$cartId}");

        $this->cartId = $cartId;
        $this->productTitle = $productTitle;
    }
}
