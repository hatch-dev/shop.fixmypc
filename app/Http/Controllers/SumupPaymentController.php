<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Helper\Utils;
use App\Models\Payment;

class SumupPaymentController extends Controller
{
    public function createCheckout(Request $request)
    {
        try{
            $request->validate([
                'amount'   => 'required',
                "currency" => 'required',
            ]);


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
                'amount' => (float)$request->amount,
                'currency' => $request->currency,
                "pay_to_email" => $paymentSettings->sumup_merchant_email,
                'redirect_url' => env('APP_URL') . "/checkout",
                'description' => $description,
                'merchant_code' => $paymentSettings->sumup_merchant_code
            ]);

            return $response->json();
        }catch(Exception $e){
            return response()->json(['error' => $e->getMessage()], 400);
        }

    }
}
