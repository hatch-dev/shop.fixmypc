<?php

namespace App\Http\Controllers;

use App\Models\Helper\ControllerHelper;
use App\Models\Helper\MembershipError;
use App\Models\Helper\Response;
use App\Models\Helper\StripeBilling;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use App\Models\MembershipPlan;
use App\Models\MembershipPlanBenefit;
use App\Models\Setting;
use App\Models\UserMembership;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Membership plans in the admin panel: what is on sale, at what price, and
 * what it gives. A plan that is switched on is mirrored to Stripe on save.
 */
class MembershipPlanController extends ControllerHelper
{
    private const SORTABLE = ['sort_order', 'created_at', 'name', 'price', 'status'];

    /**
     * The shop offers one plan for now (2026-10-15). A second is refused here,
     * not just hidden in the admin, so no request can create one. Raise this
     * when several plans are wanted; the storefront already lists them all.
     */
    const MAX_PLANS = 1;

    public function all(Request $request)
    {
        try {
            if ($can = Utils::userCan($this->user, 'membership.view')) {
                return $can;
            }

            $orderBy = in_array($request->orderby, self::SORTABLE, true) ? $request->orderby : 'sort_order';
            $orderType = strtolower((string)$request->type) === 'desc' ? 'desc' : 'asc';

            $query = MembershipPlan::withCount([
                'memberships as members_count' => function ($q) {
                    $q->entitled();
                },
                'benefits',
            ])->orderBy($orderBy, $orderType)->orderBy('id');

            if ($request->q) {
                $query->where('name', 'LIKE', '%' . $request->q . '%');
            }

            if (!is_null($request->status) && $request->status !== '') {
                $query->where('status', (int)$request->status);
            }

            $data = $query->paginate(Config::get('constants.api.PAGINATION'))->toArray();
            // the list hides Add once this many plans exist
            $data['max_plans'] = self::MAX_PLANS;
            $data['plan_count'] = MembershipPlan::count();

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function find(Request $request, $id)
    {
        try {
            if ($can = Utils::userCan($this->user, 'membership.view')) {
                return $can;
            }

            $plan = MembershipPlan::with('benefits')
                ->withCount(['memberships as members_count' => function ($q) {
                    $q->entitled();
                }])
                ->find($id);

            if (!$plan) {
                return response()->json(Validation::error($request->token, 'No data found'));
            }

            $plan->stripe_ready = StripeBilling::ready();

            return response()->json(new Response($request->token, $plan));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function action(Request $request, $id = null)
    {
        try {
            if ($can = Utils::userCan($this->user, $id ? 'membership.edit' : 'membership.create')) {
                return $can;
            }

            $plan = $id ? MembershipPlan::find($id) : null;

            if ($id && !$plan) {
                return response()->json(Validation::error($request->token, 'No data found'));
            }

            if (!$id && MembershipPlan::count() >= self::MAX_PLANS) {
                return response()->json(Validation::error($request->token,
                    'Only one membership plan can be offered. Edit the existing plan instead.'));
            }

            if ($invalid = $this->invalid($request)) {
                return response()->json($invalid);
            }

            $plan = DB::transaction(function () use ($request, $plan) {
                $plan = $plan ?: new MembershipPlan();

                $plan->fill([
                    'name' => trim($request->name),
                    'tagline' => $this->blankToNull($request->tagline),
                    // monthly and yearly; a blank one is not offered
                    'price' => $this->money($request->price),
                    'yearly_price' => $this->money($request->yearly_price),
                    'currency' => optional(Setting::first())->currency ?: 'EUR',
                    'interval' => 'month',
                    // once a month or once a year; the form has no multi-period option
                    'interval_count' => 1,
                    // no free trials and one plan to order: neither is offered in the form
                    'trial_days' => 0,
                    'status' => $request->boolean('status'),
                    'sort_order' => 0,
                ]);

                if (!$plan->slug) {
                    $plan->slug = $this->uniqueSlug($plan->name);
                }

                $plan->save();

                $this->syncBenefits($plan, $request->input('benefits', []));

                return $plan;
            });

            $warning = null;

            if ($plan->status) {
                $warning = $this->pushToStripe($plan);
            }

            $plan = MembershipPlan::with('benefits')->find($plan->id);
            $plan->stripe_ready = StripeBilling::ready();
            $plan->stripe_warning = $warning;

            return response()->json(new Response($request->token, $plan));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /** Retry the Stripe mirror of a plan (the edit page's "Sync with Stripe"). */
    public function sync(Request $request, $id)
    {
        if ($can = Utils::userCan($this->user, 'membership.edit')) {
            return $can;
        }

        $plan = MembershipPlan::find($id);

        if (!$plan) {
            return response()->json(Validation::error($request->token, 'No data found'));
        }

        if ($warning = $this->pushToStripe($plan)) {
            return response()->json(Validation::error($request->token, $warning));
        }

        return response()->json(new Response($request->token, MembershipPlan::with('benefits')->find($plan->id)));
    }

    /**
     * A plan anyone has ever held is switched off, not deleted: memberships
     * and receipts point at it. Only a plan nobody bought goes.
     */
    public function delete(Request $request, $id)
    {
        try {
            if ($can = Utils::userCan($this->user, 'membership.delete')) {
                return $can;
            }

            $ids = collect(explode(',', $id))->map(function ($i) {
                return (int)trim($i);
            })->filter()->unique()->values();

            $found = MembershipPlan::whereIn('id', $ids)->pluck('id');

            if ($found->isEmpty()) {
                return response()->json(Validation::error($request->token, 'No data found'));
            }

            $held = UserMembership::whereIn('membership_plan_id', $found)->pluck('membership_plan_id')->unique();
            $removable = $found->diff($held);

            DB::transaction(function () use ($removable, $held) {
                if ($removable->isNotEmpty()) {
                    MembershipPlan::whereIn('id', $removable)->delete();
                }

                if ($held->isNotEmpty()) {
                    MembershipPlan::whereIn('id', $held)->update(['status' => 0]);
                }
            });

            if ($held->isNotEmpty()) {
                return response()->json(new Response($request->token, [
                    'deleted' => $removable->values(),
                    'deactivated' => $held->values(),
                    'message' => 'Plans that have members were switched off instead of deleted. Their members keep their membership.',
                ]));
            }

            return response()->json(new Response($request->token, true));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    private function invalid(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'tagline' => 'nullable|string|max:255',
            // Stripe will not bill less than 0.50 in euro
            // Stripe will not bill less than 0.50 in euro
            'price' => 'nullable|numeric|min:0.5|max:10000',
            'yearly_price' => 'nullable|numeric|min:0.5|max:100000',
            'benefits' => 'nullable|array|max:20',
            'benefits.*.type' => 'required|in:' . implode(',', MembershipPlanBenefit::TYPES),
            'benefits.*.title' => 'required|string|max:100',
            'benefits.*.subtitle' => 'nullable|string|max:255',
            'benefits.*.icon' => 'nullable|string|max:60',
            'benefits.*.percent' => 'nullable|numeric|min:0.01|max:100',
            'benefits.*.min_order' => 'nullable|numeric|min:0',
            'benefits.*.max_discount' => 'nullable|numeric|min:0',
        ], [
            'price.min' => 'Stripe cannot bill less than 0.50.',
            'yearly_price.min' => 'Stripe cannot bill less than 0.50.',
            'benefits.*.title.required' => 'Every benefit needs a title.',
            'benefits.*.percent.max' => 'A discount cannot be more than 100%.',
        ]);

        if ($fail = Validation::validationResponse($validator, $request->token)) {
            return $fail;
        }

        if (!(float)$request->price && !(float)$request->yearly_price) {
            return Validation::fieldError($request->token, 'price', 'Enter a monthly price, a yearly price, or both.');
        }

        $benefits = collect($request->input('benefits', []));
        $labels = [
            MembershipPlanBenefit::FREE_SHIPPING => 'free delivery',
            MembershipPlanBenefit::PERCENT_DISCOUNT => 'percentage discount',
            MembershipPlanBenefit::EARLY_ACCESS => 'early access',
        ];

        foreach ($labels as $type => $label) {
            if ($benefits->where('type', $type)->count() > 1) {
                return Validation::fieldError($request->token, 'benefits', 'A plan can have only one ' . $label . ' benefit.');
            }
        }

        foreach ($benefits->where('type', MembershipPlanBenefit::PERCENT_DISCOUNT) as $benefit) {
            if (!isset($benefit['percent']) || $benefit['percent'] === '' || (float)$benefit['percent'] <= 0) {
                return Validation::fieldError($request->token, 'benefits', 'Enter the percentage for the discount benefit.');
            }
        }

        return null;
    }

    /** Keep benefit ids that are still listed, so nothing else is disturbed. */
    private function syncBenefits(MembershipPlan $plan, $benefits)
    {
        $keep = [];

        foreach (array_values((array)$benefits) as $index => $input) {
            $type = $input['type'];
            $discount = $type === MembershipPlanBenefit::PERCENT_DISCOUNT;

            $data = [
                'membership_plan_id' => $plan->id,
                'type' => $type,
                'title' => trim($input['title']),
                'subtitle' => $this->blankToNull($input['subtitle'] ?? null),
                'icon' => $this->blankToNull($input['icon'] ?? null),
                'highlight' => !empty($input['highlight']),
                'percent' => $discount ? round((float)$input['percent'], 2) : null,
                'min_order' => in_array($type, [MembershipPlanBenefit::FREE_SHIPPING, MembershipPlanBenefit::PERCENT_DISCOUNT], true) ? $this->money($input['min_order'] ?? null) : null,
                'max_discount' => $discount ? $this->money($input['max_discount'] ?? null) : null,
                'include_sale_items' => $discount && !empty($input['include_sale_items']),
                'include_business_prices' => $discount && !empty($input['include_business_prices']),
                'sort_order' => $index,
            ];

            $benefit = !empty($input['id'])
                ? MembershipPlanBenefit::where('membership_plan_id', $plan->id)->find((int)$input['id'])
                : null;

            if ($benefit) {
                $benefit->update($data);
            } else {
                $benefit = MembershipPlanBenefit::create($data);
            }

            $keep[] = $benefit->id;
        }

        MembershipPlanBenefit::where('membership_plan_id', $plan->id)->whereNotIn('id', $keep ?: [0])->delete();
    }

    /** Null when Stripe took it, else what to tell the admin. */
    private function pushToStripe(MembershipPlan $plan)
    {
        if (!StripeBilling::ready()) {
            return 'Saved, but the Stripe keys are not set in Payment Setting, so this plan cannot be sold yet.';
        }

        try {
            StripeBilling::syncPlan($plan);

            return null;
        } catch (MembershipError $e) {
            return 'Saved, but ' . lcfirst($e->getMessage()) . ' Customers cannot join until it syncs.';
        }
    }

    private function uniqueSlug($name)
    {
        $base = Str::slug($name) ?: 'plan';
        $slug = $base;
        $n = 2;

        while (MembershipPlan::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $n++;
        }

        return $slug;
    }

    private function money($value)
    {
        return ($value === null || $value === '' || (float)$value <= 0) ? null : round((float)$value, 2);
    }

    private function blankToNull($value)
    {
        $value = trim((string)$value);

        return $value === '' ? null : $value;
    }
}
