<?php

namespace App\Http\Controllers;

use App\Models\Clearance;
use App\Models\Helper\ClearanceWindow;
use App\Models\Helper\ControllerHelper;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

class ClearancesController extends ControllerHelper
{
    public function all(Request $request)
    {
        try {
            if ($can = Utils::userCan($this->user, 'clearance.view')) {
                return $can;
            }

            $query = Clearance::with('product');
            $query = $query->orderBy('clearances.' . $request->orderby, $request->type);

            if ($this->isVendor) {
                $query = $query->where('admin_id', $this->user->id);
            }

            /*
             * Searched by what the list actually PRINTS in its product column,
             * which is the override title when there is one and the product's
             * own title otherwise. Matching clearances.title alone found
             * nothing at all on this data: every deal in the catalogue leaves
             * the override blank, so the column the administrator is reading
             * and the column being searched were never the same one.
             */
            if ($request->q) {
                $term = "%{$request->q}%";

                $query = $query->where(function ($q) use ($term) {
                    $q->where('clearances.title', 'LIKE', $term)
                        ->orWhereHas('product', function ($p) use ($term) {
                            $p->where('products.title', 'LIKE', $term);
                        });
                });
            }

            $data = $query->paginate(Config::get('constants.api.PAGINATION'));

            foreach ($data as $item) {
                if ($request->time_zone) {
                    $item['created'] = Utils::convertTimeToUSERzone($item->created_at, $request->time_zone);
                    $item['end_time'] = Utils::convertTimeToUSERzone($item->end_time, $request->time_zone);
                } else {
                    $item['created'] = Utils::formatDate($item->created_at);
                }
            }

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function find(Request $request, $id)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'clearance.view')) {
                return $can;
            }

            $data = Clearance::with(['product', 'product.product_inventories'])->find($id);

            /*
             * "Not there" before "not yours": isDataOwner() reads ->admin_id
             * off whatever it is handed, so a vendor asking for an id that
             * does not exist was told they were unauthorised.
             */
            if (is_null($data)) {
                return response()->json(Validation::noDataLang($lang));
            }

            if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $data)) {
                return $isOwner;
            }

            // Back on the administrator's clock, so the control shows the
            // time they set rather than its UTC equivalent.
            if ($request->time_zone && $data->end_time) {
                $data->end_time = Utils::convertTimeToUSERzone($data->end_time, $request->time_zone);
            }

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function action(Request $request, $id = null)
    {
        try {
            $lang = $request->header('language');

            $validate = Validation::clearance($request);
            if ($validate) {
                return response()->json($validate);
            }

            $product = $request->product;

            /*
             * The timer, in the clock the rest of the app runs on.
             *
             * The screen's control is a datetime-local, so what arrives is
             * whatever the administrator's own clock reads, while every
             * comparison against it -- ClearanceWindow::live, the storefront,
             * the list's Active/Expired column -- is made in UTC. Stored raw,
             * as it was, a deal set by an administrator in IST ran five and a
             * half hours past the moment they chose.
             *
             * Converted the same way flash sales, vouchers and scheduled admin
             * tasks convert theirs.
             */
            $endTime = null;

            if ($request->end_time) {
                $endTime = $request->time_zone
                    ? Utils::convertTimeToUTCzone($request->end_time, $request->time_zone)
                    : date('Y-m-d H:i:s', strtotime($request->end_time));
            }

            $payload = [
                'product_id'     => $product['id'],
                'title'          => $request->title,
                'subtitle'       => $request->subtitle,
                'discount_type'  => in_array($request->discount_type, ['fixed', 'percentage']) ? $request->discount_type : 'percentage',
                'discount_value' => $request->discount_value ?: 0,
                'description'    => $request->description,
                'highlights'     => $request->highlights ?: null,
                'end_time'       => $endTime,
                'status'         => $request->status,
                'sort_order'     => $request->sort_order ?: 0,
            ];

            if ($duplicate = $this->liveDealClash($payload, $id, $lang)) {
                return $duplicate;
            }

            if ($id) {
                if ($can = Utils::userCan($this->user, 'clearance.edit')) {
                    return $can;
                }

                $existing = Clearance::find($id);

                /*
                 * Before the ownership test, and before the write. update()
                 * on a missing id touches no rows and reports no error, so
                 * editing a deal that had been deleted in another tab came
                 * back 200 with a null body -- a save the screen showed as
                 * successful and which had gone nowhere.
                 *
                 * It also has to precede isDataOwner(), which reads
                 * ->admin_id off whatever it is handed and answers
                 * "unauthorised" for a record that simply is not there.
                 */
                if (is_null($existing)) {
                    return response()->json(Validation::noDataLang($lang));
                }

                if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $existing)) {
                    return $isOwner;
                }

                Clearance::where('id', $id)->update($payload);

            } else {
                if ($can = Utils::userCan($this->user, 'clearance.create')) {
                    return $can;
                }

                $payload['admin_id'] = $request->user()->id;
                $clearance = Clearance::create($payload);
                $id = $clearance->id;
            }

            $data = Clearance::with(['product', 'product.product_inventories'])->find($id);

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Refuse a second live deal on a product that already has one.
     *
     * Nothing stopped two, and the storefront then chose between them by
     * sort_order and then by age -- so which discount a customer got was
     * settled by a tie-break nobody typed. Two live deals also join a product
     * to two rows in every query that brings the deal in for pricing.
     *
     * Only LIVE deals clash. Finished ones are the page's own history grid,
     * and a product accumulates as many of those as it has had.
     */
    private function liveDealClash($payload, $id, $lang)
    {
        $isLive = (int)$payload['status'] === (int)Config::get('constants.status.PUBLIC')
            && (is_null($payload['end_time']) || strtotime($payload['end_time']) >= time());

        if (!$isLive) {
            return false;
        }

        $clash = ClearanceWindow::live(
            Clearance::where('product_id', $payload['product_id'])
        );

        if ($id) {
            $clash = $clash->where('id', '!=', $id);
        }

        if (!$clash->exists()) {
            return false;
        }

        return response()->json(Validation::error(
            null,
            __('lang.clearance_already_live', [], $lang),
            'form'
        ));
    }


    public function delete(Request $request, $id)
    {
        try {
            $lang = $request->header('language');
            if ($can = Utils::userCan($this->user, 'clearance.delete')) {
                return $can;
            }

            $ids = explode(",", $id);
            foreach ($ids as $i) {
                $clearance = Clearance::find($i);

                // "Not there" before "not yours", as in find() and action():
                // isDataOwner() reads ->admin_id off whatever it is handed.
                if (is_null($clearance)) {
                    return response()->json(Validation::noDataLang($lang));
                }

                if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $clearance)) {
                    return $isOwner;
                }

                $clearance->delete();
            }

            return response()->json(new Response($request->token, true));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }
}
