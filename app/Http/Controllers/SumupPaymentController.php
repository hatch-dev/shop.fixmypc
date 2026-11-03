<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Helper\CheckoutTotals;
use App\Models\Helper\Utils;
use App\Models\Payment;

class SumupPaymentController extends Controller
{
    public function createCheckout(Request $request)
    {
        try{
            $request->validate([
                'amount'   => 'required|numeric|min:0.01',
                "currency" => 'required',
            ]);

            /*
             * A top-up or a gift card belongs to an account, so only a
             * signed-in customer may start one. An order payment stays open:
             * guests check out through this same endpoint.
             */
            if ((!empty($request->is_topup) || !empty($request->is_gift_voucher)) && !$request->user('user')) {
                return response()->json(['message' => 'Please sign in to continue.'], 401);
            }

            // Savers Club / dashboard top-ups: whole cents, between 1 and 1000 euro
            if (!empty($request->is_topup)) {
                $amount = (float)$request->amount;
                if ($amount < 1 || $amount > 1000 || round($amount, 2) != $amount) {
                    return response()->json(['message' => 'Top-ups must be between €1 and €1,000.'], 422);
                }
            }


            $amount = (float)$request->amount;

            /*
             * An order payment is charged the server's figure, never the
             * page's: what placing the order will charge (CheckoutTotals::
             * quote), less any wallet part. /payment builds the order after
             * the card clears and refuses it when SumUp took a different
             * amount -- which is what happened when the page's own sum left
             * out the Essentials Bundle discount: the shopper paid, and the
             * order read "Not Paid". A top-up or gift card has no basket, and
             * its amount is the one the customer typed.
             */
            if (empty($request->is_topup) && empty($request->is_gift_voucher)) {
                $quote = CheckoutTotals::quote(
                    $request,
                    CartsController::checkoutChoices($request),
                    $request->header('language')
                );

                if (!$quote['ok']) {
                    return response()->json(['message' => $quote['message']], 422);
                }

                $amount = $quote['amount_due'];

                if ($amount < 0.01) {
                    return response()->json(['message' => 'There is nothing left to pay by card.'], 422);
                }
            }

            if(isset($request->is_topup) && $request->is_topup){
                $checkout_reference = Utils::generateTrackingId(["user_id" => rand(2, 50)], "topup");
                $description = 'Top-up Payment #' . $checkout_reference;
            }elseif(isset($request->is_gift_voucher) && $request->is_gift_voucher){
                $checkout_reference = Utils::generateTrackingId(["user_id" => rand(2, 50)], "giftvoucher");
                $description = 'Gift Voucher Purchase #' . $checkout_reference;
            }else{
                $checkout_reference = Utils::generateTrackingId(["user_id" => rand(2, 50)]);
                $description = 'Order Payment #' . $checkout_reference;
            }

            $paymentSettings = Payment::first();

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $paymentSettings->sumup_api_key,
                'Content-Type'  => 'application/json'
            ])->post('https://api.sumup.com/v0.1/checkouts', [
                'checkout_reference' => $checkout_reference,
                'amount' => $amount,
                'currency' => $request->currency,
                "pay_to_email" => $paymentSettings->sumup_merchant_email,
                'redirect_url' => env('APP_URL') . "/checkout",
                'description' => $description,
                'merchant_code' => $paymentSettings->sumup_merchant_code
            ]);

            $body = $response->json();

            // What the checkout was created for, so the page records that figure.
            if (is_array($body)) {
                $body['charged_amount'] = $amount;
            }

            return $body;
        }catch(Exception $e){
            return response()->json(['error' => $e->getMessage()], 400);
        }

    }
}
