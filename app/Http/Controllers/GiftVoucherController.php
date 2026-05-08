<?php

namespace App\Http\Controllers;

use App\Models\GiftVoucher;
use App\Models\Helper\ControllerHelper;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

class GiftVoucherController extends Controller
{
    public function index(Request $request)
    {
        try {
            $lang = $request->header('language');


            $query = GiftVoucher::query();

            // Sorting
            $query = $query->orderBy(
                'gift_voucher.' . ($request->orderby ?? 'id'),
                $request->type ?? 'desc'
            );

            // Search
            if ($request->q) {
                $query = $query->where('title', 'LIKE', "%{$request->q}%");
            }

            $data = $query->paginate(Config::get('constants.api.PAGINATION'));

            foreach ($data as $item) {
                $item['created'] = Utils::formatDate($item->created_at);
            }

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(
                Validation::error($request->token, $ex->getMessage())
            );
        }
    }

    public function show(Request $request, $id)
    {
        try {

            $data = GiftVoucher::find($id);

            if (is_null($data)) {
                return response()->json(Validation::noDataLang(null));
            }

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(
                Validation::error($request->token, $ex->getMessage())
            );
        }
    }

    public function store(Request $request, $id = null)
    {
        try {
            $lang = $request->header('language');

            // Validation (custom or inline)
            $request->validate([
                'title' => 'required|string|max:255',
                'amounts' => 'required|array|min:1',
                'amounts.*' => 'numeric|min:1',
                'min_quantity' => 'required|integer|min:1',
                'max_quantity' => 'required|integer|gte:min_quantity',
                'image' => 'nullable|string',
                'description' => 'nullable|string'
            ]);

            $data = $request->all();

            if (!empty($request->image) && str_contains($request->image, 'base64')) {
                $image = $request->image;
                preg_match('/^data:image\/(\w+);base64,/', $image, $type);
                $image = substr($image, strpos($image, ',') + 1);
                $image = base64_decode($image);
                $extension = $type[1] ?? 'png';
                $fileName = 'gift_voucher_' . time() . '.' . $extension;
                $uploadPath = base_path('uploads');
                if (!file_exists($uploadPath)) {
                    mkdir($uploadPath, 0755, true);
                }
                file_put_contents($uploadPath . '/' . $fileName, $image);
                $data['image'] = $fileName;
            }

            if ($id) {

                $existing = GiftVoucher::find($id);

                if (is_null($existing)) {
                    return response()->json(Validation::noDataLang($lang));
                }

                $existing->update($data);

            } else {

                $request['admin_id'] = $request->user()->id ?? null;

                $existing = GiftVoucher::create($data);
                $id = $existing->id;
            }

            $data = GiftVoucher::find($id);
            $data['created'] = Utils::formatDate($data->created_at);

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(
                Validation::error($request->token, $ex->getMessage())
            );
        }
    }

    public function delete(Request $request, $id)
    {
        try {
            $lang = $request->header('language');

            $ids = explode(",", $id);

            foreach ($ids as $i) {
                $item = GiftVoucher::find($i);

                if (is_null($item)) {
                    return response()->json(Validation::noDataLang($lang));
                }

                $item->delete();
            }

            return response()->json(new Response($request->token, true));

        } catch (\Exception $ex) {
            return response()->json(
                Validation::error($request->token, $ex->getMessage())
            );
        }
    }
}
