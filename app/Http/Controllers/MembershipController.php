<?php

namespace App\Http\Controllers;

use App\Models\Helper\Membership;
use App\Models\Helper\MembershipError;
use App\Models\Helper\Response;
use App\Models\Helper\StripeBilling;
use App\Models\Helper\Validation;
use App\Models\MembershipPlan;
use App\Models\MembershipPlanBenefit;
use App\Models\User;
use App\Models\Helper\MailBrand;
use App\Models\UserMembership;
use Illuminate\Http\Request;

/**
 * The customer's side of paid membership: the Savers Club box.
 *
 * Joining and paying happen on Stripe's own Checkout page; the card is
 * managed in Stripe's customer portal. Cancel, resume and change plan are
 * our buttons, carried out on the subscription and mirrored back
 * (Helper\StripeBilling). Everything here is behind auth:user and acts on
 * the caller's own membership only.
 */
class MembershipController extends Controller
{
    /** Plans on offer, the caller's membership and what it has saved them. */
    public function show(Request $request)
    {
        return response()->json(new Response($request->token, $this->payload($request->user('user'))));
    }

    /**
     * Join with a card entered on this page. Answers with the page's payload
     * once the first payment is through, or with the bank's challenge
     * ({requires_action, client_secret, subscription}) for the page to show
     * and then send back to joinFinish.
     */
    public function subscribe(Request $request)
    {
        $user = $request->user('user');

        return $this->attempt($request, function () use ($request, $user) {
            $plan = MembershipPlan::active()->find((int)$request->plan_id);

            if (!$plan) {
                throw new MembershipError('This plan is not available.');
            }

            if (UserMembership::where('user_id', $user->id)->entitled()->exists()
                || UserMembership::where('user_id', $user->id)->where('source', UserMembership::SOURCE_STRIPE)->open()->exists()) {
                throw new MembershipError('You already have a membership. You can change your plan instead.');
            }

            // the billing period the customer picked; the first one offered if none
            $interval = in_array($request->interval, MembershipPlan::INTERVALS, true)
                ? $request->interval
                : ($plan->intervals()[0] ?? 'month');

            return $this->joinAnswer($user, StripeBilling::subscribeWithCard($user, $plan, $interval, $request->payment_method));
        });
    }

    /** After the bank's challenge on the page. */
    public function joinFinish(Request $request)
    {
        $user = $request->user('user');

        return $this->attempt($request, function () use ($request, $user) {
            return $this->joinAnswer($user, StripeBilling::finishSubscription($user, $request->subscription));
        });
    }

    private function joinAnswer($user, array $result)
    {
        if ($result['status'] === 'requires_action') {
            return [
                'requires_action' => true,
                'client_secret' => $result['client_secret'],
                'subscription' => $result['subscription'],
            ];
        }

        return array_merge($this->payload($user), [
            'requires_action' => false,
            // paid, Stripe has not quite finished: the page reloads in a moment
            'processing' => $result['status'] === 'processing',
        ]);
    }

    /** The card on file and a page of the payments made, for the Manage panel (?page=). */
    public function billing(Request $request)
    {
        return $this->onOwnSubscription($request, function ($membership) {
            return null;
        }, function ($membership) use ($request) {
            return StripeBilling::billing($membership, (int)$request->query('page', 1));
        });
    }

    /** A new card for renewals, from this page; the bank's challenge as for joining. */
    public function card(Request $request)
    {
        return $this->cardAnswer($request, function ($membership) use ($request) {
            return StripeBilling::updateCard($membership, $request->payment_method);
        });
    }

    public function cardFinish(Request $request)
    {
        return $this->cardAnswer($request, function ($membership) use ($request) {
            return StripeBilling::finishCardUpdate($membership, $request->setup_intent);
        });
    }

    private function cardAnswer(Request $request, callable $action)
    {
        $result = null;

        return $this->onOwnSubscription($request, function ($membership) use ($action, &$result) {
            $result = $action($membership);
        }, function ($membership, $payload) use (&$result) {
            return $result['status'] === 'requires_action'
                ? ['requires_action' => true, 'client_secret' => $result['client_secret'], 'setup_intent' => $result['setup_intent']]
                : array_merge($payload, ['requires_action' => false]);
        });
    }

    /** Cancel at the end of the paid period. */
    public function cancel(Request $request)
    {
        return $this->onOwnSubscription($request, function ($membership) {
            if ($membership->cancel_at_period_end) {
                return $membership;
            }

            return StripeBilling::setCancelAtPeriodEnd($membership, true);
        });
    }

    /** Keep a membership that was set to end. */
    public function resume(Request $request)
    {
        return $this->onOwnSubscription($request, function ($membership) {
            if (!$membership->cancel_at_period_end) {
                return $membership;
            }

            return StripeBilling::setCancelAtPeriodEnd($membership, false);
        });
    }

    /**
     * Move to another plan or billing period now (prorated by Stripe).
     * plan_id defaults to the current plan, interval to the current period, so
     * {interval: 'year'} alone is "switch to yearly".
     */
    public function change(Request $request)
    {
        return $this->onOwnSubscription($request, function ($membership) use ($request) {
            $plan = MembershipPlan::active()->find((int)($request->plan_id ?: $membership->membership_plan_id));

            if (!$plan) {
                throw new MembershipError('This plan is not available.');
            }

            $interval = in_array($request->interval, MembershipPlan::INTERVALS, true)
                ? $request->interval
                : ($membership->interval ?: 'month');

            if ((int)$plan->id === (int)$membership->membership_plan_id && $interval === $membership->interval) {
                throw new MembershipError('You are already on this plan.');
            }

            if (!in_array($membership->status, ['active', 'trialing'], true)) {
                throw new MembershipError('Your plan can be changed once your payment is up to date.');
            }

            return StripeBilling::changePlan($membership, $plan, $interval);
        });
    }

    /** Turn the new-products email on or off (Savers Club). */
    public function digest(Request $request)
    {
        $user = $request->user('user');
        $user->member_digest_opt_out = !$request->boolean('enabled');
        $user->save();

        return response()->json(new Response($request->token, $this->payload($user)));
    }

    /**
     * The link in the new-products email and its List-Unsubscribe target.
     * Signed, so it cannot be pointed at someone else; turns that email off
     * and nothing else.
     */
    public function digestUnsubscribe(Request $request)
    {
        $user = $request->hasValidSignature() ? User::find((int)$request->query('user')) : null;

        if ($user) {
            $user->member_digest_opt_out = true;
            $user->save();
        }

        if ($request->isMethod('post')) {
            return response($user ? 'OK' : 'Failed', $user ? 200 : 403)->header('Content-Type', 'text/plain');
        }

        return response()->view('mail_templates.unsubscribed', [
            'ok' => (bool)$user,
            'email' => optional($user)->email,
            'what' => 'You will no longer get emails when new products arrive. Your membership and its other benefits are unchanged, and you can turn these emails back on from Savers Club.',
            'brand' => MailBrand::make(),
        ], $user ? 200 : 403);
    }

    /**
     * Act on the caller's own paid membership. Answers with the page's payload,
     * or with what $respond($membership, $payload) makes of it.
     */
    private function onOwnSubscription(Request $request, callable $action, callable $respond = null)
    {
        $user = $request->user('user');

        return $this->attempt($request, function () use ($user, $action, $respond) {
            $membership = UserMembership::where('user_id', $user->id)
                ->where('source', UserMembership::SOURCE_STRIPE)
                ->open()
                ->orderByDesc('id')
                ->first();

            if (!$membership) {
                throw new MembershipError('There is no membership to change.');
            }

            $action($membership);

            if ($respond) {
                return $respond($membership->fresh(), $this->payload($user));
            }

            return $this->payload($user);
        });
    }

    private function attempt(Request $request, callable $action)
    {
        try {
            return response()->json(new Response($request->token, $action()));
        } catch (MembershipError $e) {
            return response()->json(Validation::error($request->token, $e->getMessage()));
        }
    }

    private function payload($user)
    {
        $plans = MembershipPlan::active()->ordered()->with('benefits')->get();
        $membership = Membership::latestFor($user);

        return [
            // false hides Join: no plan on sale, or Stripe not set up
            'available' => StripeBilling::ready() && $plans->isNotEmpty(),
            // the publishable key for the card form on the page (never the secret)
            'stripe_key' => optional(\App\Models\Payment::first())->stripe_key,
            'plans' => $plans->map(function ($plan) {
                return $plan->toPublic();
            })->values(),
            'membership' => $membership ? self::describe($membership, $user) : null,
            // the new-products email: offered when the member's plan has early access
            'digest' => [
                'available' => $membership && $membership->isEntitled()
                    && (bool)optional($membership->plan)->benefit(MembershipPlanBenefit::EARLY_ACCESS),
                'enabled' => !$user->member_digest_opt_out,
            ],
            'saved' => Membership::savedBy($user),
        ];
    }

    private static function otherPeriod(UserMembership $membership, $stripe)
    {
        $plan = $membership->plan;

        if (!$stripe || !$plan || !$plan->status || $membership->cancel_at_period_end
            || !in_array($membership->status, ['active', 'trialing'], true)) {
            return null;
        }

        $other = $membership->interval === 'year' ? 'month' : 'year';

        if (!$plan->offers($other)) {
            return null;
        }

        return [
            'interval' => $other,
            'price' => $plan->priceFor($other),
            'interval_label' => MembershipPlan::describeInterval($other),
            'saving' => $other === 'year' ? $plan->yearlySaving() : null,
        ];
    }

    /** One membership as the account page needs it. */
    public static function describe(UserMembership $membership, $user = null)
    {
        $entitled = $membership->isEntitled() && !optional($user ?? $membership->user)->isSuspended();
        $stripe = $membership->isStripe();
        $open = in_array($membership->status, UserMembership::OPEN, true);

        return [
            'id' => $membership->id,
            'source' => $membership->source,
            'status' => $membership->status,
            'status_label' => $membership->statusLabel(),
            'entitled' => $entitled,
            'plan' => $membership->plan ? $membership->plan->toPublic() : null,
            // what this member pays, not what the plan costs today
            'price' => $membership->price,
            'currency' => $membership->currency,
            'interval_label' => MembershipPlan::describeInterval($membership->interval, $membership->interval_count),
            'current_period_end' => optional($membership->current_period_end)->toISOString(),
            'trial_ends_at' => optional($membership->trial_ends_at)->toISOString(),
            'cancel_at_period_end' => (bool)$membership->cancel_at_period_end,
            'ended_at' => optional($membership->ended_at)->toISOString(),
            'can_cancel' => $stripe && $open && !$membership->cancel_at_period_end,
            'can_resume' => $stripe && $membership->cancel_at_period_end
                && in_array($membership->status, UserMembership::ENTITLED, true),
            'interval' => $membership->interval,
            'can_change_plan' => $stripe && in_array($membership->status, ['active', 'trialing'], true),
            // the other billing period of the same plan, when it is on offer
            'switch_to' => self::otherPeriod($membership, $stripe),
            'can_manage_billing' => $stripe && (bool)$membership->stripe_customer_id,
        ];
    }
}
