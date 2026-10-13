<?php

namespace App\Http\Controllers;

use App\Models\GiftVoucher;
use App\Models\GiftVoucherOrder;
use App\Models\Helper\ControllerHelper;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

/**
 * Gift card designs (admin). What a customer can buy: a title, an image,
 * the values offered, how many at once, and whether it is on sale.
 *
 * This used to extend the plain Controller with no permission checks, so any
 * admin could edit or delete designs; it now checks gift_voucher.*.
 */
class GiftVoucherController extends ControllerHelper
{
    public function index(Request $request)
    {
        try {
            if ($can = Utils::userCan($this->user, 'gift_voucher.view')) {
                return $can;
            }

            $allowed = ['id', 'title', 'created_at', 'is_active'];
            $orderBy = in_array($request->orderby, $allowed) ? $request->orderby : 'id';
            $type = strtolower($request->type) === 'asc' ? 'asc' : 'desc';

            $query = GiftVoucher::query()->orderBy('gift_voucher.' . $orderBy, $type);

            if ($request->q) {
                $query->where('title', 'LIKE', "%{$request->q}%");
            }

            $data = $query->paginate(Config::get('constants.api.PAGINATION'));

            // how many have been sold, so a design in use is not deleted blind
            $sold = GiftVoucherOrder::where('status', 'success')
                ->whereIn('voucher_id', collect($data->items())->pluck('id'))
                ->selectRaw('voucher_id, SUM(quantity) as cards')
                ->groupBy('voucher_id')
                ->pluck('cards', 'voucher_id');

            foreach ($data as $item) {
                $item['created'] = Utils::formatDate($item->created_at);
                $item['sold'] = (int)($sold[$item->id] ?? 0);
            }

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function show(Request $request, $id)
    {
        try {
            if ($can = Utils::userCan($this->user, 'gift_voucher.view')) {
                return $can;
            }

            $data = GiftVoucher::find($id);

            if (is_null($data)) {
                return response()->json(Validation::noDataLang(null));
            }

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function store(Request $request, $id = null)
    {
        try {
            if ($can = Utils::userCan($this->user, $id ? 'gift_voucher.edit' : 'gift_voucher.create')) {
                return $can;
            }

            $lang = $request->header('language');

            $request->validate([
                'title'        => 'required|string|max:255',
                'amounts'      => 'required|array|min:1|max:12',
                'amounts.*'    => 'numeric|min:1|max:5000',
                'min_quantity' => 'required|integer|min:1',
                'max_quantity' => 'required|integer|gte:min_quantity|max:100',
                'image'        => 'nullable|string',
                'description'  => 'nullable|string|max:500',
                'is_active'    => 'nullable',
            ]);

            // Only the columns a design has; the form sends more than that.
            $data = $request->only(['title', 'description', 'min_quantity', 'max_quantity']);

            // Values sorted, de-duplicated and kept to two decimals.
            $data['amounts'] = collect($request->amounts)
                ->map(function ($a) { return round((float)$a, 2); })
                ->unique()->sort()->values()->all();

            $data['is_active'] = filter_var($request->input('is_active', true), FILTER_VALIDATE_BOOLEAN);

            if (!empty($request->image) && str_contains($request->image, 'base64')) {
                if (!preg_match('/^data:image\/(png|jpe?g|webp|gif);base64,/', $request->image, $type)) {
                    return response()->json(Validation::error($request->token, 'The image must be a PNG, JPG, WEBP or GIF.'));
                }

                $binary = base64_decode(substr($request->image, strpos($request->image, ',') + 1));
                $fileName = 'gift_voucher_' . time() . '.' . ($type[1] === 'jpeg' ? 'jpg' : $type[1]);
                $uploadPath = base_path('uploads');

                if (!file_exists($uploadPath)) {
                    mkdir($uploadPath, 0755, true);
                }

                file_put_contents($uploadPath . '/' . $fileName, $binary);
                $data['image'] = $fileName;

            } elseif ($request->has('image') && is_string($request->image) && $request->image !== '') {
                $data['image'] = $request->image;
            }

            if ($id) {
                $existing = GiftVoucher::find($id);

                if (is_null($existing)) {
                    return response()->json(Validation::noDataLang($lang));
                }

                $existing->update($data);
            } else {
                $existing = GiftVoucher::create($data);
                $id = $existing->id;
            }

            $data = GiftVoucher::find($id);
            $data['created'] = Utils::formatDate($data->created_at);

            return response()->json(new Response($request->token, $data));

        } catch (\Illuminate\Validation\ValidationException $ex) {
            return response()->json(Validation::error($request->token, collect($ex->errors())->flatten()->first()));
        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Delete designs nobody has bought. A design with sales is taken off
     * sale instead: deleting it would strip the name from every card and
     * purchase that points at it.
     */
    public function delete(Request $request, $id)
    {
        try {
            if ($can = Utils::userCan($this->user, 'gift_voucher.delete')) {
                return $can;
            }

            $lang = $request->header('language');
            $withdrawn = 0;

            foreach (explode(',', $id) as $i) {
                $item = GiftVoucher::find($i);

                if (is_null($item)) {
                    return response()->json(Validation::noDataLang($lang));
                }

                if (GiftVoucherOrder::where('voucher_id', $item->id)->exists()) {
                    $item->update(['is_active' => false]);
                    $withdrawn++;
                } else {
                    $item->delete();
                }
            }

            return response()->json(new Response($request->token, true, 200, $withdrawn
                ? "$withdrawn design(s) with sales were taken off sale instead of deleted."
                : ''));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }
}
