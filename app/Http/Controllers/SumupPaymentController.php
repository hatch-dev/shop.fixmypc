<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Helper\Utils;

class SumupPaymentController extends Controller
{
    public function createCheckout(Request $request)
    {
        try{
            $request->validate([
                'amount'   => 'required',
                "currency" => 'required',
            ]);

            $checkout_reference = Utils::generateTrackingId(["user_id" => rand(2, 50)]);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . env('SUMUP_API_KEY'),
                'Content-Type'  => 'application/json'
            ])->post('https://api.sumup.com/v0.1/checkouts', [
                'checkout_reference' => $checkout_reference,
                'amount' => (float)$request->amount,
                'currency' => $request->currency,
                "pay_to_email" => env('SUMUP_MERCHANT_EMAIL'),
                'redirect_url' => env('APP_URL') . "/checkout",
                'description' => 'Order Payment #' . $checkout_reference,
                'merchant_code' => env('SUMUP_MERCHANT_CODE')
            ]);

            return $response->json();
        }catch(Exception $e){
            return response()->json(['error' => $e->getMessage()], 400);
        }

    }
}
