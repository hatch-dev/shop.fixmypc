<?php

namespace App\Http\Controllers;
use App\Models\FlashDiscount;
use App\Models\Helper\ControllerHelper;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

class FlashDiscountController extends ControllerHelper
{
    public function all(Request $request)
    {
        try {

            $query = FlashDiscount::query();
            $query = $query->orderBy('flash_discounts.' . $request->orderby, $request->type);

            if ($this->isVendor) {
                $query = $query->where('admin_id', $this->user->id);
            }

            if ($request->q) {
                $query = $query->where('type', 'LIKE', "%{$request->q}%");
            }

            $data = $query->paginate(Config::get('constants.api.PAGINATION'));

            foreach ($data as $item) {
                $item['created'] = Utils::formatDate($item->created_at);

                // calculate preview discount
                $discountAmount = 0;

                if ($item->type === 'percentage') {
                    $discountAmount = $item->value;
                } else {
                    $discountAmount = $item->value;
                }

                $item['discount_display'] = $item->type === 'percentage'
                    ? $item->value . '%'
                    : '€' . $item->value;
            }

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function find(Request $request, $id)
    {
        try {

            $data = FlashDiscount::find($id);

            if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $data)) {
                return $isOwner;
            }

            if (is_null($data)) {
                return response()->json(Validation::noData());
            }

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function action(Request $request, $id = null)
    {
        try {

            $validate = Validation::flashDiscount($request); // create this validation
            if ($validate) {
                return response()->json($validate);
            }

            if ($id) {


                $existing = FlashDiscount::find($id);

                if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $existing)) {
                    return $isOwner;
                }

                FlashDiscount::where('id', $id)->update([
                    'type' => $request->type,
                    'value' => $request->value,
                    'min_cart_value' => $request->min_cart_value,
                    'max_discount' => $request->max_discount,
                    'start_time' => $request->start_time,
                    'end_time' => $request->end_time,
                    'is_active' => $request->is_active ?? 1,
                ]);

            } else {

                $flash = FlashDiscount::create([
                    'type' => $request->type,
                    'value' => $request->value,
                    'min_cart_value' => $request->min_cart_value,
                    'max_discount' => $request->max_discount,
                    'start_time' => $request->start_time,
                    'end_time' => $request->end_time,
                    'is_active' => $request->is_active ?? 1,
                ]);

                $id = $flash->id;
            }

            $data = FlashDiscount::find($id);

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function delete(Request $request, $id)
    {
        try {
            
            $ids = explode(",", $id);

            foreach ($ids as $i) {
                $item = FlashDiscount::find($i);

                if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $item)) {
                    return $isOwner;
                }

                if (is_null($item)) {
                    return response()->json(Validation::noData());
                }

                $item->delete();
            }

            return response()->json(new Response($request->token, true));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function current()
    {
        $flash = FlashDiscount::active()->first();

        if (!$flash) {
            return response()->json([
                'active' => false
            ]);
        }

        return response()->json([
            'active'     => true,
            'type'       => $flash->type,
            'value'      => (float) $flash->value,
            'end_time'   => $flash->end_time->toISOString(),
            'start_time' => $flash->start_time->toISOString(),
            'min_cart_value' => (float) ($flash->min_cart_value ?? 0),
            'max_discount'   => (float) ($flash->max_discount ?? 0),
        ]);
    }
}
