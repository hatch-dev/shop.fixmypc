<?php

namespace App\Http\Controllers;

use App\Models\FooterImageLink;
use App\Models\Helper\ControllerHelper;
use App\Models\Helper\FileHelper;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

class PaymentsController extends ControllerHelper
{
    public function save(Request $request)
    {
        try{
            if($can = Utils::userCan($this->user, 'setting.edit')){
                return $can;
            }

            $validate = Validation::payment($request);
            if($validate){
                return response()->json($validate);
            }

            // Only SumUp or Stripe can take cards at checkout.
            if (!is_null($request->card_gateway) && $request->card_gateway !== '' &&
                !in_array((int)$request->card_gateway, Payment::cardGateways(), true)) {
                return response()->json(Validation::error($request->token,
                    'Card payments can be handled by SumUp or Stripe only.'
                ));
            }

            foreach (Payment::RETIRED_GATEWAYS as $gateway) {
                $request[$gateway] = 0;
            }

            // Only real settings columns: find() adds `secrets`, which the
            // form sends straight back.
            $input = $request->only((new Payment)->getFillable());

            // find() never sends a secret out, so a blank one means "keep the
            // stored value", not "erase it".
            foreach (Payment::SECRET_FIELDS as $field) {
                if (array_key_exists($field, $input) && trim((string)$input[$field]) === '') {
                    unset($input[$field]);
                }
            }

            $data = Payment::first();

            if(is_null($data)){
                $input['admin_id'] = $this->user->id;

                Payment::create($input);
            }else{
                Payment::where('id', $data->id)->update($input);
            }

            return response()->json(new Response($request->token, Payment::first()->adminView()));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function find(Request $request)
    {
        if ($can = Utils::userCan($this->user, 'setting.view')) {
            return $can;
        }

        $payment = Payment::first();

        return response()->json(new Response($request->token, $payment ? $payment->adminView() : null));
    }
}
