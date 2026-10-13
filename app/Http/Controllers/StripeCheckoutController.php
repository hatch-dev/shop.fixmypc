<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Helper\BasketUpkeep;
use App\Models\Helper\CartHold;
use App\Models\Helper\Response;
use App\Models\Helper\StripeCheckout;
use App\Models\Helper\Validation;
use App\Models\Payment;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

/**
 * The card step of a Stripe checkout, before any order exists. See
 * App\Models\Helper\StripeCheckout for the whole flow.
 */
class StripeCheckoutController extends Controller
{
    /**
     * Authorise the shopper's card for their basket.
     *
     * Only for someone with a basket to pay for, and only while Stripe is the
     * store's card gateway: an open endpoint that authorises any card for any
     * amount is a card-testing service. The amount is the basket total the
     * checkout shows; it is not trusted - OrdersController::action captures
     * only when it matches the order the server itself prices.
     */
    public function authorise(Request $request)
    {
        $payment = Payment::first();

        if (!$payment || !$payment->acceptsCardMethod(Config::get('constants.paymentMethod.STRIPE'))) {
            return response()->json(Validation::error($request->token,
                __('lang.accepted_gateway', [], $request->header('language'))
            ));
        }

        $owner = CartHold::ownerFromRequest($request);

        $hasBasket = $owner && Cart::whereNull('admin_id')
            ->when($owner[0], function ($q) use ($owner) {
                $q->where('user_id', $owner[0]);
            }, function ($q) use ($owner) {
                $q->whereNull('user_id')->where('user_token', $owner[1]);
            })
            ->forCheckout()
            ->exists();

        if (!$hasBasket) {
            return response()->json(Validation::error($request->token,
                'Your basket is empty.'
            ));
        }

        // A basket being paid for is not emptied by the 20-minute sweep, nor
        // a Buy Now line cleared as abandoned.
        CartHold::markPaying($owner[0], $owner[1]);
        BasketUpkeep::touchBuyNow($owner[0], $owner[1]);

        $result = StripeCheckout::authorise(
            $payment,
            $request->payment_token,
            (float)$request->amount,
            Setting::select('currency')->first()->currency,
            array_filter([
                'user_id' => $owner[0],
                'guest' => $owner[1] ? 1 : null,
            ])
        );

        if ($result['status'] === 'failed') {
            return response()->json(Validation::error($request->token, $result['message']));
        }

        return response()->json(new Response($request->token, $result));
    }

    /** Release an authorisation the order was never placed for. */
    public function cancel(Request $request)
    {
        $payment = Payment::first();

        if ($payment) {
            StripeCheckout::cancel($payment, $request->payment_intent);
        }

        // Nothing to tell the shopper either way; the hold expires regardless.
        return response()->json(new Response($request->token, true));
    }
}
