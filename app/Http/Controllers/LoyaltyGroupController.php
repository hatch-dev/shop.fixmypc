<?php

namespace App\Http\Controllers;

use App\Models\Helper\ControllerHelper;
use App\Models\Helper\Pricing;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use App\Models\LoyaltyGroup;
use App\Models\LoyaltyGroupUser;
use App\Models\OrderedLoyaltyDiscount;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class LoyaltyGroupController extends ControllerHelper
{
    /**
     * Only these columns may be sorted on. The old version interpolated
     * $request->orderby straight into orderBy(), so the caller chose the
     * column list for us.
     */
    private const SORTABLE = [
        'created_at', 'title', 'discount_type', 'discount_value', 'status', 'validity'
    ];

    public function all(Request $request)
    {
        try {
            if ($can = Utils::userCan($this->user, 'loyalty_group.view')) {
                return $can;
            }

            $orderBy = in_array($request->orderby, self::SORTABLE, true)
                ? $request->orderby
                : 'created_at';

            $orderType = strtolower($request->type) === 'asc' ? 'asc' : 'desc';

            $query = LoyaltyGroup::withCount([
                    'members',
                    'members as redeemed_count' => function ($q) {
                        $q->whereNotNull('used_at');
                    }
                ])
                ->orderBy('loyalty_groups.' . $orderBy, $orderType);

            if ($request->q) {
                $query->where('loyalty_groups.title', 'LIKE', "%{$request->q}%");
            }

            if (!is_null($request->status) && $request->status !== '') {
                $query->where('loyalty_groups.status', (int)$request->status);
            }

            $data = $query->paginate(Config::get('constants.api.PAGINATION'));

            /*
             * The list has to say whether a group is doing anything today.
             * "Active" in the database only means an admin has not parked it;
             * a date_range group that ended last week is switched on and dead.
             */
            foreach ($data as $group) {
                $group->is_running = $group->isRunning();
            }

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function find(Request $request, $id)
    {
        try {
            if ($can = Utils::userCan($this->user, 'loyalty_group.view')) {
                return $can;
            }

            /*
             * Only the columns the form needs. find() used to hand back whole
             * User models for every member, which carries the password hash,
             * the remember token and the two-factor secret out to the browser.
             */
            $data = LoyaltyGroup::with(['users' => function ($q) {
                $q->select('users.id', 'users.name', 'users.email');
            }])->find($id);

            if (!$data) {
                return response()->json(Validation::error($request->token, "No data found"));
            }

            $data->is_running = $data->isRunning();

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Who has actually spent this group's discount, and on what.
     *
     * Read from ordered_loyalty_discount rather than from the membership rows:
     * that table is the record of money taken off an order, and it carries the
     * label the customer was shown at the time. A membership only tells you
     * someone is eligible.
     *
     * Paginated, because a long-running group accumulates a row per order and
     * the edit form must not have to load all of them to render.
     */
    public function redemptions(Request $request, $id)
    {
        try {
            if ($can = Utils::userCan($this->user, 'loyalty_group.view')) {
                return $can;
            }

            if (!LoyaltyGroup::where('id', $id)->exists()) {
                return response()->json(Validation::error($request->token, "No data found"));
            }

            $query = OrderedLoyaltyDiscount::query()
                ->join('orders', 'orders.id', '=', 'ordered_loyalty_discount.order_id')
                // A deleted order is not a redemption. The SoftDeletes scope
                // only reaches queries made through the Order model.
                ->whereNull('orders.deleted_at')
                /*
                 * Left join: an order whose customer has since deleted their
                 * account still happened, and dropping it would make the totals
                 * below disagree with the money that was actually given away.
                 */
                ->leftJoin('users', 'users.id', '=', 'orders.user_id')
                ->where('ordered_loyalty_discount.loyalty_group_id', $id)
                ->select(
                    'ordered_loyalty_discount.id',
                    'ordered_loyalty_discount.discount',
                    'ordered_loyalty_discount.label',
                    'ordered_loyalty_discount.created_at',
                    'orders.id as order_id',
                    'orders.order as order_number',
                    'orders.status as order_status',
                    'orders.total_amount',
                    'orders.payment_done',
                    'users.id as user_id',
                    'users.name as user_name',
                    'users.email as user_email'
                )
                ->orderByDesc('ordered_loyalty_discount.created_at');

            if ($request->q) {
                $search = $request->q;

                $query->where(function ($q) use ($search) {
                    $q->where('users.name', 'LIKE', "%{$search}%")
                        ->orWhere('users.email', 'LIKE', "%{$search}%")
                        ->orWhere('orders.order', 'LIKE', "%{$search}%");
                });
            }

            $data = $query->paginate(Config::get('constants.api.PAGINATION'));

            /*
             * Totals over the whole group, not just the page being viewed --
             * a per-page sum would change every time the admin clicked next.
             */
            $totals = OrderedLoyaltyDiscount::where('loyalty_group_id', $id)
                ->selectRaw('COUNT(*) as redemption_count, COALESCE(SUM(discount),0) as total_discount')
                ->first();

            return response()->json(new Response($request->token, [
                'redemptions' => $data,
                'redemption_count' => (int)$totals->redemption_count,
                'total_discount' => Pricing::round($totals->total_discount),
                'customer_count' => OrderedLoyaltyDiscount::where('loyalty_group_id', $id)
                    ->join('orders', 'orders.id', '=', 'ordered_loyalty_discount.order_id')
                    ->whereNull('orders.deleted_at')
                    ->distinct()
                    ->count('orders.user_id')
            ]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function action(Request $request, $id = null)
    {
        try {
            if ($can = Utils::userCan($this->user, $id ? 'loyalty_group.edit' : 'loyalty_group.create')) {
                return $can;
            }

            if ($invalid = Validation::loyaltyGroup($request)) {
                return response()->json($invalid);
            }

            if ($id && !LoyaltyGroup::where('id', $id)->exists()) {
                return response()->json(Validation::error($request->token, "No data found"));
            }

            $validity = $request->validity;

            $customerLabel = trim((string)$request->customer_label);

            $data = [
                'title' => $request->title,
                // Blank means "use the storefront default", not an empty label.
                'customer_label' => $customerLabel !== '' ? $customerLabel : null,
                'status' => (int)($request->status ?? 1),
                'discount_type' => $request->discount_type,
                'discount_value' => $request->discount_value,
                'min_cart_value' => $this->nullableNumber($request->min_cart_value),
                'max_discount' => $this->nullableNumber($request->max_discount),
                'validity' => $validity,
                'period_days' => $validity === 'period' ? (int)$request->period_days : null,
                'start_date' => $validity === 'date_range' ? $request->start_date : null,
                'end_date' => $validity === 'date_range' ? $request->end_date : null
            ];

            $users = collect($request->users ?? [])
                ->map(function ($u) {
                    return (int)$u;
                })
                ->filter()
                ->unique()
                ->values()
                ->all();

            /*
             * The whole save is one transaction. The old version wrote the
             * group, deleted every membership, then re-inserted them a row at a
             * time outside any transaction, and called DB::rollBack() in the
             * catch with no matching beginTransaction(). A failure halfway
             * through left a group whose members had simply been deleted.
             */
            $groupId = DB::transaction(function () use ($id, $data, $users) {

                if ($id) {
                    LoyaltyGroup::where('id', $id)->update($data);
                    $group = LoyaltyGroup::find($id);
                } else {
                    $group = LoyaltyGroup::create($data);
                }

                $this->syncMembers($group, $users);

                return $group->id;
            });

            $result = LoyaltyGroup::with(['users' => function ($q) {
                $q->select('users.id', 'users.name', 'users.email');
            }])->find($groupId);

            return response()->json(new Response($request->token, $result));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function delete(Request $request, $id)
    {
        try {
            if ($can = Utils::userCan($this->user, 'loyalty_group.delete')) {
                return $can;
            }

            $ids = collect(explode(",", $id))
                ->map(function ($i) {
                    return (int)trim($i);
                })
                ->filter()
                ->unique()
                ->values();

            $found = LoyaltyGroup::whereIn('id', $ids)->pluck('id');

            if ($found->isEmpty()) {
                return response()->json(Validation::error($request->token, "No data found"));
            }

            /*
             * A group that has already paid for orders is switched off rather
             * than deleted: ordered_loyalty_discount rows point at it and the
             * order history has to keep explaining itself. Only groups that
             * never discounted anything are actually removed.
             *
             * The old loop deleted ids one at a time and returned an error on
             * the first id that did not exist, leaving a bulk delete half
             * applied and reported as a failure.
             */
            $spent = OrderedLoyaltyDiscount::whereIn('loyalty_group_id', $found)
                ->pluck('loyalty_group_id')
                ->unique();

            $removable = $found->diff($spent);

            DB::transaction(function () use ($removable, $spent) {

                if ($removable->isNotEmpty()) {
                    LoyaltyGroupUser::whereIn('loyalty_group_id', $removable)->delete();
                    LoyaltyGroup::whereIn('id', $removable)->delete();
                }

                if ($spent->isNotEmpty()) {
                    LoyaltyGroup::whereIn('id', $spent)->update(['status' => 0]);
                }
            });

            if ($spent->isNotEmpty()) {
                return response()->json(new Response($request->token, [
                    'deleted' => $removable->values(),
                    'deactivated' => $spent->values(),
                    'message' => 'Groups that have already discounted an order were deactivated instead of deleted.'
                ]));
            }

            return response()->json(new Response($request->token, true));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Reconcile the member list without disturbing anyone already in the group.
     *
     * This is the fix for the feature's worst data bug. The old code ran
     *
     *     LoyaltyGroupUser::where('loyalty_group_id', $id)->delete();
     *
     * on every save and re-created the rows, so a member's joining date, the
     * only thing "30 days from joining" can count from, was reset every time an
     * admin opened the group and pressed save, and a spent one-time redemption
     * came back to life. Existing members are now left alone; only genuine
     * additions and removals are written.
     */
    private function syncMembers(LoyaltyGroup $group, array $users)
    {
        $existing = LoyaltyGroupUser::where('loyalty_group_id', $group->id)
            ->get()
            ->keyBy('user_id');

        $now = Carbon::now();

        // Dropped from the list, unless they already spent the benefit.
        foreach ($existing as $userId => $membership) {
            if (!in_array($userId, $users, true) && is_null($membership->used_at)) {
                $membership->delete();
            }
        }

        foreach ($users as $userId) {

            if ($existing->has($userId)) {
                /*
                 * Already a member. The group's validity rules may have changed
                 * under them, so recompute the expiry from their original
                 * joining date, never from today.
                 */
                $membership = $existing->get($userId);

                $membership->expires_at = $group->expiryFor(
                    $membership->joined_at ?? $membership->created_at
                );

                $membership->save();

                continue;
            }

            LoyaltyGroupUser::create([
                'loyalty_group_id' => $group->id,
                'user_id' => $userId,
                'joined_at' => $now,
                'expires_at' => $group->expiryFor($now)
            ]);
        }
    }

    private function nullableNumber($value)
    {
        return ($value === null || $value === '') ? null : (float)$value;
    }
}
