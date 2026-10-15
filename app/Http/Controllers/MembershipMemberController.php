<?php

namespace App\Http\Controllers;

use App\Models\Helper\ControllerHelper;
use App\Models\Helper\MembershipError;
use App\Models\Helper\Response;
use App\Models\Helper\StripeBilling;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use App\Models\MembershipPlan;
use App\Models\User;
use App\Models\UserMembership;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Members in the admin panel: who holds which plan, their invoices and
 * history, and what an admin can do about it - cancel, resume, change plan,
 * re-read from Stripe, or grant a membership with no payment.
 *
 * A paid membership is changed on Stripe and mirrored back, so the admin
 * panel and the customer's Stripe account cannot disagree. Every action is
 * written to membership_events with the admin who took it.
 */
class MembershipMemberController extends ControllerHelper
{
    const FILTERS = ['entitled', 'ending', 'past_due', 'ended', 'admin'];

    public function all(Request $request)
    {
        try {
            if ($can = Utils::userCan($this->user, 'membership.view')) {
                return $can;
            }

            $query = UserMembership::query()
                ->join('users', 'users.id', '=', 'user_memberships.user_id')
                ->join('membership_plans', 'membership_plans.id', '=', 'user_memberships.membership_plan_id')
                ->select('user_memberships.*', 'users.name as user_name', 'users.email as user_email',
                    'membership_plans.name as plan_name')
                // abandoned checkouts are not members
                ->whereNotIn('user_memberships.status', ['incomplete', 'incomplete_expired'])
                ->orderByDesc('user_memberships.id');

            if ($request->q) {
                $q = $request->q;
                $query->where(function ($w) use ($q) {
                    $w->where('users.name', 'LIKE', "%{$q}%")->orWhere('users.email', 'LIKE', "%{$q}%");
                });
            }

            if ($request->plan_id) {
                $query->where('user_memberships.membership_plan_id', (int)$request->plan_id);
            }

            switch ($request->filter) {
                case 'entitled':
                    $query->whereIn('user_memberships.id', UserMembership::entitled()->select('id'));
                    break;
                case 'ending':
                    $query->where('user_memberships.cancel_at_period_end', 1)
                        ->whereIn('user_memberships.status', UserMembership::ENTITLED);
                    break;
                case 'past_due':
                    $query->whereIn('user_memberships.status', ['past_due', 'unpaid']);
                    break;
                case 'ended':
                    $query->whereNotIn('user_memberships.id', UserMembership::entitled()->select('id'));
                    break;
                case 'admin':
                    $query->where('user_memberships.source', UserMembership::SOURCE_ADMIN);
                    break;
            }

            $data = $query->paginate(Config::get('constants.api.PAGINATION'));

            $entitled = UserMembership::entitled()->whereIn('id', $data->pluck('id'))->pluck('id')->flip();

            foreach ($data as $row) {
                $row->status_label = $row->statusLabel();
                $row->entitled = $entitled->has($row->id);
            }

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /** Headline numbers for the members page. */
    public function stats(Request $request)
    {
        if ($can = Utils::userCan($this->user, 'membership.view')) {
            return $can;
        }

        $entitled = UserMembership::entitled()->get(['id', 'source', 'price', 'interval', 'interval_count', 'cancel_at_period_end', 'status']);

        // what the paying members bring in a month, at what they each pay
        $monthly = $entitled->where('source', UserMembership::SOURCE_STRIPE)->sum(function ($m) {
            $months = ($m->interval === 'year' ? 12 : 1) * max(1, (int)$m->interval_count);

            return (float)$m->price / $months;
        });

        return response()->json(new Response($request->token, [
            'members' => $entitled->count(),
            'paying' => $entitled->where('source', UserMembership::SOURCE_STRIPE)->count(),
            'granted' => $entitled->where('source', UserMembership::SOURCE_ADMIN)->count(),
            'ending' => $entitled->where('cancel_at_period_end', true)->count(),
            'past_due' => UserMembership::whereIn('status', ['past_due', 'unpaid'])->count(),
            'monthly_revenue' => round($monthly, 2),
            'discount_given' => round((float)DB::table('ordered_loyalty_discount')
                ->join('orders', 'orders.id', '=', 'ordered_loyalty_discount.order_id')
                ->whereNull('orders.deleted_at')
                ->whereNotNull('ordered_loyalty_discount.user_membership_id')
                ->sum('ordered_loyalty_discount.discount'), 2),
            'stripe_ready' => StripeBilling::ready(),
        ]));
    }

    public function find(Request $request, $id)
    {
        if ($can = Utils::userCan($this->user, 'membership.view')) {
            return $can;
        }

        $membership = UserMembership::with(['plan.benefits', 'invoices', 'events.admin:id,name'])->find($id);

        if (!$membership) {
            return response()->json(Validation::error($request->token, 'No data found'));
        }

        // invoices as Stripe has them now, not only those the webhook reported
        if ($membership->isStripe()) {
            StripeBilling::syncInvoices($membership);
            $membership->load('invoices');
        }

        return response()->json(new Response($request->token, $this->detail($membership)));
    }

    /**
     * One action on one membership:
     *   cancel_at_period_end, resume, cancel_now, change_plan (plan_id),
     *   sync (re-read from Stripe), extend (ends_at, granted memberships).
     */
    public function action(Request $request, $id)
    {
        if ($can = Utils::userCan($this->user, 'membership.edit')) {
            return $can;
        }

        $membership = UserMembership::find($id);

        if (!$membership) {
            return response()->json(Validation::error($request->token, 'No data found'));
        }

        $admin = optional($this->user)->id;
        $action = (string)$request->action;

        try {
            if ($membership->isStripe()) {
                switch ($action) {
                    case 'cancel_at_period_end':
                    case 'resume':
                        $membership = StripeBilling::setCancelAtPeriodEnd($membership, $action === 'cancel_at_period_end');
                        break;
                    case 'cancel_now':
                        $membership = StripeBilling::cancelNow($membership);
                        break;
                    case 'change_plan':
                        $plan = MembershipPlan::find((int)$request->plan_id);
                        if (!$plan) {
                            throw new MembershipError('Choose a plan.');
                        }
                        $membership = StripeBilling::changePlan($membership, $plan,
                            in_array($request->interval, MembershipPlan::INTERVALS, true) ? $request->interval : null);
                        break;
                    case 'sync':
                        $membership = StripeBilling::syncSubscription($membership->stripe_subscription_id) ?? $membership;
                        break;
                    default:
                        throw new MembershipError('That cannot be done to a paid membership.');
                }
            } else {
                $membership = $this->actOnGranted($membership, $action, $request);
            }
        } catch (MembershipError $e) {
            return response()->json(Validation::error($request->token, $e->getMessage()));
        }

        $membership->log('admin_' . $action, trim(($request->plan_id ? 'plan ' . $request->plan_id : '') . ' ' . ($request->interval ?: '')) ?: null, $admin);

        return response()->json(new Response($request->token,
            $this->detail($membership->fresh(['plan.benefits', 'invoices', 'events.admin:id,name']))));
    }

    /** Give a customer a membership with no payment: staff, a prize, an apology. */
    public function grant(Request $request)
    {
        if ($can = Utils::userCan($this->user, 'membership.create')) {
            return $can;
        }

        $user = User::find((int)$request->user_id);
        $plan = MembershipPlan::find((int)$request->plan_id);

        if (!$user) {
            return response()->json(Validation::fieldError($request->token, 'user_id', 'Choose a customer.'));
        }

        if (!$plan) {
            return response()->json(Validation::fieldError($request->token, 'plan_id', 'Choose a plan.'));
        }

        $endsAt = $this->endDate($request->ends_at);

        if ($endsAt === false) {
            return response()->json(Validation::fieldError($request->token, 'ends_at', 'The end date must be in the future.'));
        }

        if (UserMembership::where('user_id', $user->id)->entitled()->exists()) {
            return response()->json(Validation::error($request->token, 'This customer already has a membership.'));
        }

        $membership = UserMembership::create([
            'user_id' => $user->id,
            'membership_plan_id' => $plan->id,
            'source' => UserMembership::SOURCE_ADMIN,
            'status' => 'active',
            'price' => 0,
            'currency' => $plan->currency,
            'current_period_start' => Carbon::now(),
            'current_period_end' => $endsAt,
            'granted_by_admin_id' => optional($this->user)->id,
            'note' => mb_substr(trim((string)$request->note), 0, 255) ?: null,
        ]);

        $membership->log('granted', $plan->name . ($endsAt ? ' until ' . $endsAt->toDateString() : ', no end date'), optional($this->user)->id);

        return response()->json(new Response($request->token,
            $this->detail($membership->fresh(['plan.benefits', 'invoices', 'events.admin:id,name']))));
    }

    /** Customers to grant to, by name or email. */
    public function customers(Request $request)
    {
        if ($can = Utils::userCan($this->user, 'membership.view')) {
            return $can;
        }

        $q = trim((string)$request->q);

        $users = User::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    $w->where('name', 'LIKE', "%{$q}%")->orWhere('email', 'LIKE', "%{$q}%");
                });
            })
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'email']);

        return response()->json(new Response($request->token, $users));
    }

    private function actOnGranted(UserMembership $membership, $action, Request $request)
    {
        switch ($action) {
            case 'cancel_now':
                $membership->update([
                    'status' => 'canceled',
                    'canceled_at' => Carbon::now(),
                    'ended_at' => Carbon::now(),
                ]);
                break;
            case 'extend':
                $endsAt = $this->endDate($request->ends_at);
                if ($endsAt === false) {
                    throw new MembershipError('The end date must be in the future.');
                }
                $membership->update(['current_period_end' => $endsAt, 'status' => 'active', 'ended_at' => null, 'canceled_at' => null]);
                break;
            case 'change_plan':
                $plan = MembershipPlan::find((int)$request->plan_id);
                if (!$plan) {
                    throw new MembershipError('Choose a plan.');
                }
                $membership->update(['membership_plan_id' => $plan->id]);
                break;
            default:
                throw new MembershipError('That cannot be done to a granted membership.');
        }

        return $membership;
    }

    /** null = no end date; false = not a usable date. Ends at the close of that day. */
    private function endDate($value)
    {
        if (is_null($value) || trim((string)$value) === '') {
            return null;
        }

        try {
            $date = Carbon::parse($value)->endOfDay();
        } catch (\Throwable $e) {
            return false;
        }

        return $date->isFuture() ? $date : false;
    }

    private function detail(UserMembership $membership)
    {
        $user = User::select('id', 'name', 'email', 'status')->find($membership->user_id);
        $testMode = strpos((string)optional(\App\Models\Payment::first())->stripe_secret, 'sk_test_') === 0;

        return array_merge(MembershipController::describe($membership, $user), [
            'user' => $user ? $user->only(['id', 'name', 'email', 'status']) : null,
            'plan_id' => $membership->membership_plan_id,
            'note' => $membership->note,
            'created_at' => optional($membership->created_at)->toISOString(),
            'current_period_start' => optional($membership->current_period_start)->toISOString(),
            'canceled_at' => optional($membership->canceled_at)->toISOString(),
            'last_synced_at' => optional($membership->last_synced_at)->toISOString(),
            'stripe_subscription_id' => $membership->stripe_subscription_id,
            'stripe_url' => $membership->stripe_subscription_id
                ? 'https://dashboard.stripe.com/' . ($testMode ? 'test/' : '') . 'subscriptions/' . $membership->stripe_subscription_id
                : null,
            'invoices' => $membership->invoices->map(function ($invoice) {
                return [
                    'id' => $invoice->id,
                    'number' => $invoice->number,
                    'status' => $invoice->status,
                    'amount_due' => $invoice->amount_due,
                    'amount_paid' => $invoice->amount_paid,
                    'currency' => $invoice->currency,
                    'period_start' => optional($invoice->period_start)->toISOString(),
                    'period_end' => optional($invoice->period_end)->toISOString(),
                    'paid_at' => optional($invoice->paid_at)->toISOString(),
                    'url' => $invoice->hosted_invoice_url,
                ];
            })->values(),
            'events' => $membership->events->map(function ($event) {
                return [
                    'action' => $event->action,
                    'detail' => $event->detail,
                    'admin' => optional($event->admin)->name,
                    'at' => optional($event->created_at)->toISOString(),
                ];
            })->values(),
            'plans' => MembershipPlan::ordered()->get(['id', 'name', 'status', 'price', 'yearly_price']),
        ]);
    }
}
