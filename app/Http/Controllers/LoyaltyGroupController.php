<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Helper\Response;
use App\Models\LoyaltyGroup;
use App\Models\LoyaltyGroupUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;

class LoyaltyGroupController extends Controller
{
    public function all(Request $request)
    {
        try {
            $orderBy = $request->orderby ?? 'created_at';
            $orderType = $request->type ?? 'desc';
            $search = $request->q;

            $query = LoyaltyGroup::orderBy('loyalty_groups.' . $orderBy, $orderType);

            if ($search) {
                $query = $query->where('loyalty_groups.title', 'LIKE', "%{$search}%");
            }

            $data = $query->paginate(Config::get('constants.api.PAGINATION'));

            return response()->json(new Response($request->token, $data));
        }catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function find(Request $request, $id)
    {
        try {
            $data = LoyaltyGroup::with('users')->find($id);
            if (!$data) {
                return response()->json(Validation::error($request->token, "No data found"));
            }
            return response()->json(new Response($request->token, $data));
        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function action(Request $request, $id = null)
    {
        try {
            $title = $request->title;
            $discountType = $request->discount_type;
            $discountValue = $request->discount_value;
            $users = $request->users ?? [];
            $start_date = $request->start_date;
            $end_date = $request->end_date;
            $period_days = $request->period_days;
            $validity = $request->validity ?? 'one_time';

            $data = [
                'title' => $title,
                'discount_type' => $discountType,
                'discount_value' => $discountValue,
                'validity' => $validity,
                'period_days' => null,
                'start_date' => null,
                'end_date' => null
            ];

            if ($validity === 'period') { 
                $data['period_days'] = $period_days;
            }
            
            if ($validity === 'date_range') { 
                $data['start_date'] = $start_date;
                $data['end_date'] = $end_date;
            }


            if ($id) {
                LoyaltyGroup::where('id', $id)->update($data);
            } else {
                $group = LoyaltyGroup::create($data);
                $id = $group->id;
            }

            LoyaltyGroupUser::where('loyalty_group_id', $id)->delete();
            foreach ($users as $userId) {
                LoyaltyGroupUser::create([
                    'loyalty_group_id' => $id,
                    'user_id' => $userId
                ]);
            }

            $data = LoyaltyGroup::with('users')->find($id);

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {

            DB::rollBack();

            return response()->json(
                Validation::error($request->token, $ex->getMessage())
            );
        }
    }

    public function delete(Request $request, $id)
    {
        try {

            $ids = explode(",", $id);

            foreach ($ids as $i) {

                $group = LoyaltyGroup::find($i);

                if (!$group) {
                    return response()->json(Validation::error($request->token, "No data found"));
                }

                $group->delete();
            }

            return response()->json(new Response($request->token, true));

        } catch (\Exception $ex) {
            return response()->json(
                Validation::error($request->token, $ex->getMessage())
            );
        }
    }
}
