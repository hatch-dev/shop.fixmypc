<?php

namespace App\Http\Controllers;

use App\Models\FlashDiscount;
use App\Models\Helper\ControllerHelper;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

/**
 * The store-wide flash discount: one promotion, a window, and a percentage or
 * a fixed amount off the whole basket.
 *
 * Not to be confused with FlashSalesController, which discounts individual
 * products through flash_sales / flash_sale_products.
 */
class FlashDiscountController extends ControllerHelper
{
    /** Columns the list may be sorted by. Anything else is ignored. */
    const SORTABLE = [
        'id', 'type', 'value', 'min_cart_value', 'max_discount',
        'start_time', 'end_time', 'is_active', 'created_at',
    ];

    /**
     * A store-wide discount belongs to the store, not to a seller.
     *
     * The old code filtered the list on flash_discounts.admin_id and read
     * $data->admin_id through Utils::isDataOwner. There is no such column, so
     * the list died with "1054 Unknown column 'admin_id'" and every other
     * action compared an id against null and refused. Vendors are turned away
     * here instead, in one place, with an answer that says so.
     */
    private function refuseVendor()
    {
        return $this->isVendor
            ? response()->json(Validation::unauthorized())
            : null;
    }

    /** Empty means "not set", which for a nullable money column is null, not 0. */
    private function money($given)
    {
        return (is_null($given) || $given === '') ? null : (float)$given;
    }

    /**
     * The form posts 2026-09-18T10:00 from <input type="datetime-local">, the
     * API client posts 2026-09-18 10:00:00. Both land in the column the same
     * way rather than relying on MySQL to interpret them.
     */
    private function moment($given)
    {
        return Carbon::parse($given)->format('Y-m-d H:i:s');
    }

    private function fields(Request $request)
    {
        return [
            'type' => $request->type,
            'value' => (float)$request->value,
            'min_cart_value' => $this->money($request->min_cart_value),
            'max_discount' => $this->money($request->max_discount),
            'start_time' => $this->moment($request->start_time),
            'end_time' => $this->moment($request->end_time),
            'is_active' => is_null($request->is_active) ? 1 : (int)(bool)$request->is_active,
        ];
    }

    public function all(Request $request)
    {
        try {

            if ($refusal = $this->refuseVendor()) {
                return $refusal;
            }

            /*
             * Landing on the list sends neither parameter, and
             * orderBy('flash_discounts.' . null, null) threw "Order direction
             * must be asc or desc" -- so the screen was empty until a column
             * header was clicked. Both are defaulted, and both are checked
             * rather than concatenated into the query.
             */
            $column = in_array($request->orderby, self::SORTABLE, true)
                ? $request->orderby
                : 'id';

            $direction = strtolower((string)$request->type) === 'asc' ? 'asc' : 'desc';

            $query = FlashDiscount::query()->orderBy('flash_discounts.' . $column, $direction);

            /*
             * The search box used to match on `type`, an enum of two values,
             * so nothing an admin could type ever found anything. A discount
             * is recognised by its size and its window.
             */
            if ($request->q) {

                $term = trim($request->q);

                $query = $query->where(function ($inner) use ($term) {

                    $inner->where('type', 'LIKE', "%{$term}%")
                        ->orWhere('start_time', 'LIKE', "%{$term}%")
                        ->orWhere('end_time', 'LIKE', "%{$term}%");

                    if (is_numeric($term)) {
                        $inner->orWhere('value', $term)
                            ->orWhere('min_cart_value', $term)
                            ->orWhere('max_discount', $term);
                    }
                });
            }

            $data = $query->paginate(Config::get('constants.api.PAGINATION'));

            foreach ($data as $item) {
                $item['created'] = Utils::formatDate($item->created_at);
                $item['live'] = $item->isLive();
            }

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function find(Request $request, $id)
    {
        try {

            if ($refusal = $this->refuseVendor()) {
                return $refusal;
            }

            $data = FlashDiscount::find($id);

            // Checked BEFORE anything reads a property off it.
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

            if ($refusal = $this->refuseVendor()) {
                return $refusal;
            }

            $existing = null;

            if ($id) {

                $existing = FlashDiscount::find($id);

                // An edit aimed at a row that is gone used to update nothing
                // and answer 200 with the deleted id.
                if (is_null($existing)) {
                    return response()->json(Validation::noData());
                }
            }

            $validate = Validation::flashDiscount($request, $id);

            if ($validate) {
                return response()->json($validate);
            }

            if ($existing) {
                $existing->update($this->fields($request));
            } else {
                $id = FlashDiscount::create($this->fields($request))->id;
            }

            return response()->json(new Response($request->token, FlashDiscount::find($id)));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function delete(Request $request, $id)
    {
        try {

            if ($refusal = $this->refuseVendor()) {
                return $refusal;
            }

            $ids = array_filter(array_map('trim', explode(',', (string)$id)), 'strlen');

            $items = FlashDiscount::whereIn('id', $ids)->get();

            /*
             * Nothing is deleted unless everything named exists. The old loop
             * deleted as it went and bailed out on the first id it could not
             * find, so a bulk delete containing one stale id left the table
             * half cleared and answered with an error.
             */
            if ($items->count() !== count($ids)) {
                return response()->json(Validation::noData());
            }

            foreach ($items as $item) {
                $item->delete();
            }

            return response()->json(new Response($request->token, true));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * What the storefront banner reads.
     *
     * Deliberately the same row FlashDiscount::current() hands the checkout,
     * so the basket cannot advertise one promotion while the order applies
     * another.
     */
    public function current(Request $request)
    {
        try {

            $flash = FlashDiscount::current();

            if (!$flash) {
                return response()->json(['active' => false]);
            }

            return response()->json([
                'active' => true,
                'type' => $flash->type,
                'value' => (float)$flash->value,
                'start_time' => $flash->start_time->toISOString(),
                'end_time' => $flash->end_time->toISOString(),
                'min_cart_value' => (float)($flash->min_cart_value ?? 0),

                /*
                 * null, not 0, for "no ceiling". 0 is a real answer to a
                 * different question and the basket used to read the two the
                 * same way.
                 */
                'max_discount' => is_null($flash->max_discount) || (float)$flash->max_discount <= 0
                    ? null
                    : (float)$flash->max_discount,
            ]);

        } catch (\Exception $ex) {
            // The banner is decoration; it must never take the basket down.
            return response()->json(['active' => false]);
        }
    }
}
