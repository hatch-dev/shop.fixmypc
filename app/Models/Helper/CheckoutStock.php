<?php

namespace App\Models\Helper;

use Illuminate\Support\Facades\Config;

/**
 * Can these checkout lines be bought right now?
 *
 * One rule, asked twice: by OrdersController just before an order is
 * written, and by CartsController::checkoutCheck just before a card is
 * charged. A basket whose reservation ran out no longer holds its units, so
 * an item can sell out while it sits there; SumUp takes the money before the
 * order exists, and asking only at the order would leave a shopper charged
 * for an order that is then refused.
 *
 * The order still takes its units with a conditional decrement, so this is
 * the early, friendly answer - not the guarantee.
 */
class CheckoutStock
{
    /**
     * @param  iterable  $lines  carts rows with product and updated_inventory loaded
     * @return array  [cart id => [message, ...]] - empty when all can be bought
     */
    public static function refusals($lines, $userId, $userToken, $lang = null)
    {
        $refusals = [];

        foreach ($lines as $line) {
            $messages = [];
            $product = $line->product;
            $inventory = $line->updated_inventory;
            $title = optional($product)->title;

            if (!$product || (int)$product->status !== (int)Config::get('constants.status.PUBLIC')) {
                $messages[] = __('lang.private_product', ['product' => $title], $lang);
            }

            if (!$inventory) {
                $messages[] = __('lang.out_stock_product', ['product' => $title], $lang);

            } else if ((int)$inventory->is_active !== 1) {
                /*
                 * The quantity being bought is compared, not merely "stock
                 * above zero" (QA audit STK-01: a 100-unit order once went
                 * through against 5 units). is_active means backorder is
                 * allowed on that variant, so those lines may go below zero
                 * on purpose. Units this shopper's basket has reserved are
                 * already off quantity but are theirs (CartHold).
                 */
                $available = (int)$inventory->quantity
                    + CartHold::heldFor($userId, $userId ? null : $userToken, $line->inventory_id);

                if ((int)$line->quantity > $available) {
                    $messages[] = __('lang.out_stock_product', ['product' => $title], $lang);
                }
            }

            if ($messages) {
                $refusals[$line->id] = $messages;
            }
        }

        return $refusals;
    }
}
