<?php

namespace App\Models\Helper;

use App\Models\MembershipInvoice;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\User;
use App\Models\UserMembership;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Membership billing through Stripe Subscriptions (2026-10-15).
 *
 *   syncPlan()           a plan is mirrored to a Stripe Product + Price; a
 *                        changed price/interval makes a new Price and
 *                        archives the old one (members keep what they pay)
 *   subscribeWithCard()  joining on the Savers Club page itself: the card
 *                        is taken by Stripe's card form there, the first
 *                        period charged now, 3-D Secure shown over the page
 *                        (finishSubscription() after it); nobody leaves
 *   updateCard()         a new card for renewals, checked with the bank
 *   billing()            the card on file and the payments, for the page
 *   setCancelAtPeriodEnd / cancelNow / changePlan   our own buttons
 *   syncSubscription()   the one writer of user_memberships for Stripe rows;
 *                        every path above and every webhook ends here, and
 *                        it always re-reads the subscription from Stripe, so
 *                        events arriving late or out of order cannot leave a
 *                        stale state behind
 *   handleEvent()        the webhook (StripeWebhookController)
 *
 * Nothing here trusts the browser: the plan, the price and the customer are
 * decided server-side, and a returning session must belong to the caller.
 *
 * Uses the Stripe keys on the payments row, like StripeCheckout. It works
 * whichever gateway takes cards at checkout.
 */
class StripeBilling
{
    /** metadata.purpose on everything made here. */
    const PURPOSE = 'membership';

    /** Is Stripe set up well enough to sell a membership? */
    public static function ready()
    {
        $payment = Payment::first();

        return $payment && trim((string)$payment->stripe_secret) !== '' && trim((string)$payment->stripe_key) !== '';
    }

    private static function client()
    {
        $payment = Payment::first();

        if (!$payment || trim((string)$payment->stripe_secret) === '') {
            throw new MembershipError('Memberships are not available at the moment.', 'stripe not configured');
        }

        \Stripe\Stripe::setApiKey($payment->stripe_secret);
    }

    // ----------------------------------------------------------------- plans

    /** price|currency|interval: what a Stripe Price is made for. */
    public static function priceKey(MembershipPlan $plan, $interval = 'month')
    {
        return number_format((float)$plan->priceFor($interval), 2, '.', '') . '|' . strtoupper($plan->currency)
            . '|' . $interval . '|1';
    }

    /** The plan columns holding the Stripe Price for one period. */
    private static function priceColumns($interval)
    {
        return $interval === 'year'
            ? ['stripe_yearly_price_id', 'stripe_yearly_price_key']
            : ['stripe_price_id', 'stripe_price_key'];
    }

    /**
     * Make sure the plan has a Stripe Product, and a Price for each period it
     * is offered at, at what it costs today. A period no longer offered has
     * its Price archived. Saves the ids on the plan. Throws MembershipError.
     */
    public static function syncPlan(MembershipPlan $plan)
    {
        self::client();

        try {
            /*
             * Ids saved under other keys (test while trying the site out, then
             * live) do not exist in this account. Forget them and make the
             * product and prices again, rather than failing every save.
             */
            if ($plan->stripe_product_id && !self::productExists($plan->stripe_product_id)) {
                Log::info('Membership plan Stripe ids belong to other keys; recreating', ['plan' => $plan->id]);

                $plan->fill([
                    'stripe_product_id' => null,
                    'stripe_price_id' => null, 'stripe_price_key' => null,
                    'stripe_yearly_price_id' => null, 'stripe_yearly_price_key' => null,
                ])->save();
            }

            if (!$plan->stripe_product_id) {
                $product = \Stripe\Product::create([
                    'name' => $plan->name,
                    'metadata' => ['purpose' => self::PURPOSE, 'plan_id' => (string)$plan->id],
                ]);

                $plan->stripe_product_id = $product->id;
                $plan->save();
            } else {
                \Stripe\Product::update($plan->stripe_product_id, ['name' => $plan->name, 'active' => true]);
            }

            foreach (MembershipPlan::INTERVALS as $interval) {
                [$idColumn, $keyColumn] = self::priceColumns($interval);
                $old = $plan->{$idColumn};

                if (!$plan->offers($interval)) {
                    if ($old) {
                        self::archivePrice($old);
                        $plan->{$idColumn} = null;
                        $plan->{$keyColumn} = null;
                        $plan->save();
                    }
                    continue;
                }

                $key = self::priceKey($plan, $interval);

                if ($old && $plan->{$keyColumn} === $key) {
                    continue;
                }

                $price = \Stripe\Price::create([
                    'product' => $plan->stripe_product_id,
                    'unit_amount' => Pricing::toMinorUnits($plan->priceFor($interval)),
                    'currency' => strtolower($plan->currency),
                    'recurring' => ['interval' => $interval, 'interval_count' => 1],
                    'metadata' => ['purpose' => self::PURPOSE, 'plan_id' => (string)$plan->id, 'interval' => $interval],
                ]);

                $plan->{$idColumn} = $price->id;
                $plan->{$keyColumn} = $key;
                $plan->save();

                // New subscribers get the new price; nobody is moved off the old one.
                if ($old) {
                    self::archivePrice($old);
                }
            }

            return $plan;

        } catch (MembershipError $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Membership plan not synced to Stripe', ['plan' => $plan->id, 'message' => $e->getMessage()]);

            throw new MembershipError('Stripe did not accept this plan: ' . $e->getMessage(), $e->getMessage());
        }
    }

    /** Is this product in the account the current keys belong to? Other errors are thrown. */
    private static function productExists($productId)
    {
        try {
            \Stripe\Product::retrieve($productId);

            return true;
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            if ($e->getStripeCode() === 'resource_missing' || stripos($e->getMessage(), 'No such') !== false) {
                return false;
            }

            throw $e;
        }
    }

    private static function archivePrice($priceId)
    {
        try {
            \Stripe\Price::update($priceId, ['active' => false]);
        } catch (\Throwable $e) {
            Log::warning('Old membership price not archived', ['price' => $priceId, 'message' => $e->getMessage()]);
        }
    }

    // -------------------------------------------------------------- customers

    /** The user's Stripe customer, made on first use. */
    public static function customerFor(User $user, $fresh = false)
    {
        self::client();

        if ($user->stripe_customer_id && !$fresh) {
            return $user->stripe_customer_id;
        }

        $customer = \Stripe\Customer::create(array_filter([
            'email' => $user->email,
            'name' => $user->name,
            'metadata' => ['user_id' => (string)$user->id],
        ]));

        $user->stripe_customer_id = $customer->id;
        $user->save();

        return $customer->id;
    }

    // ------------------------------------------------------- joining, in page

    /**
     * API version for the calls that read a subscription's first payment.
     * latest_invoice.payment_intent is gone from newer versions; pinning these
     * requests keeps that path working whatever the account's default is.
     */
    const PAYMENT_API_VERSION = '2024-06-20';

    /**
     * Start a membership on a card the customer entered on the Savers Club
     * page (a PaymentMethod, pm_..., from Stripe's card form). Nothing leaves
     * the page: Stripe charges the first period here and now.
     *
     * Returns ['status' => 'active', 'membership' => UserMembership]
     *      or ['status' => 'requires_action', 'client_secret', 'subscription']
     *         when the bank wants 3-D Secure (the page runs it, then finish())
     * Throws MembershipError with the bank's reason when the card is refused.
     */
    public static function subscribeWithCard(User $user, MembershipPlan $plan, $interval, $paymentMethod)
    {
        if (!$plan->status) {
            throw new MembershipError('This plan is not available.');
        }

        if (!$plan->offers($interval)) {
            throw new MembershipError('This plan is not offered ' . ($interval === 'year' ? 'yearly' : 'monthly') . '.');
        }

        if (strpos((string)$paymentMethod, 'pm_') !== 0) {
            throw new MembershipError('Card details were not received. Please try again.');
        }

        self::syncPlan($plan);

        $priceId = $plan->stripePriceIdFor($interval);

        try {
            $customer = self::attachCard($user, $paymentMethod);

            $subscription = \Stripe\Subscription::create([
                'customer' => $customer,
                'items' => [['price' => $priceId]],
                'default_payment_method' => $paymentMethod,
                // charge now; if the bank wants a challenge the subscription waits for it
                'payment_behavior' => 'allow_incomplete',
                'metadata' => [
                    'purpose' => self::PURPOSE,
                    'user_id' => (string)$user->id,
                    'plan_id' => (string)$plan->id,
                    'interval' => $interval,
                ],
                'expand' => ['latest_invoice.payment_intent'],
            ], [
                'stripe_version' => self::PAYMENT_API_VERSION,
                // the same card for the same price is one subscription, however often the button is pressed
                'idempotency_key' => 'member-join-' . sha1($user->id . '|' . $paymentMethod . '|' . $priceId),
            ]);
        } catch (\Stripe\Exception\CardException $e) {
            throw new MembershipError($e->getMessage(), $e->getMessage());
        } catch (MembershipError $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Membership not started', ['user' => $user->id, 'plan' => $plan->id, 'message' => $e->getMessage()]);

            throw new MembershipError('The membership payment could not be started. Please try again.', $e->getMessage());
        }

        return self::joinOutcome($subscription);
    }

    /** After the bank's challenge on the page: finish what subscribeWithCard started. */
    public static function finishSubscription(User $user, $subscriptionId)
    {
        self::client();

        try {
            $subscription = \Stripe\Subscription::retrieve(
                ['id' => (string)$subscriptionId, 'expand' => ['latest_invoice.payment_intent']],
                ['stripe_version' => self::PAYMENT_API_VERSION]
            );
        } catch (\Throwable $e) {
            throw new MembershipError('That payment could not be found.', $e->getMessage());
        }

        $meta = self::plainMetadata(self::get($subscription, 'metadata'));

        if (($meta['purpose'] ?? null) !== self::PURPOSE || (string)($meta['user_id'] ?? '') !== (string)$user->id) {
            throw new MembershipError('That payment could not be found.', 'subscription belongs to someone else');
        }

        return self::joinOutcome($subscription);
    }

    /**
     * active: mirrored and done. incomplete + requires_action: hand the page
     * the challenge. Paid but not yet flipped to active: report processing,
     * the webhook or sync finishes it. Anything else was refused: the
     * subscription is cancelled so it never bills, and the bank's reason is
     * thrown.
     */
    private static function joinOutcome($subscription)
    {
        $status = (string)self::get($subscription, 'status');
        $intent = self::path($subscription, ['latest_invoice', 'payment_intent']);
        $intentStatus = (string)self::get($intent, 'status');

        if (in_array($status, ['active', 'trialing'], true)) {
            $membership = self::syncSubscription((string)$subscription->id);

            if ($membership) {
                self::syncInvoices($membership);
            }

            return ['status' => 'active', 'membership' => $membership];
        }

        if ($status === 'incomplete' && in_array($intentStatus, ['requires_action', 'requires_confirmation'], true)) {
            return [
                'status' => 'requires_action',
                'client_secret' => self::get($intent, 'client_secret'),
                'subscription' => $subscription->id,
            ];
        }

        if (in_array($intentStatus, ['succeeded', 'processing'], true)) {
            // paid a moment ago; Stripe moves the subscription to active right after
            for ($i = 0; $i < 3; $i++) {
                usleep(700000);
                $membership = self::syncSubscription((string)$subscription->id);

                if ($membership && in_array($membership->status, ['active', 'trialing'], true)) {
                    return ['status' => 'active', 'membership' => $membership];
                }
            }

            return ['status' => 'processing'];
        }

        $reason = self::path($intent, ['last_payment_error', 'message']) ?: 'Your card was declined. Please try another card.';

        try {
            \Stripe\Subscription::retrieve((string)$subscription->id)->cancel();
        } catch (\Throwable $e) {
            // an incomplete subscription expires by itself within a day
        }

        throw new MembershipError($reason, 'payment ' . ($intentStatus ?: $status));
    }

    /** Attach a card to the user's Stripe customer (made on first use). Returns the customer id. */
    private static function attachCard(User $user, $paymentMethod)
    {
        try {
            $customer = self::customerFor($user);
            \Stripe\PaymentMethod::retrieve($paymentMethod)->attach(['customer' => $customer]);
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            // a customer id from other keys (test vs live) or deleted in Stripe: make a new one, once
            if (stripos($e->getMessage(), 'No such customer') === false) {
                throw $e;
            }

            $customer = self::customerFor($user, true);
            \Stripe\PaymentMethod::retrieve($paymentMethod)->attach(['customer' => $customer]);
        }

        return $customer;
    }

    // ------------------------------------------------------ the card on file

    /**
     * Put a new card on a membership, from the Savers Club page. Checked with
     * the bank now (a SetupIntent), so renewals do not fail on a challenge
     * nobody is there to answer. A renewal that already failed is retried on
     * the new card straight away.
     *
     * Returns ['status' => 'updated'] or ['status' => 'requires_action',
     * 'client_secret', 'setup_intent']; throws MembershipError when refused.
     */
    public static function updateCard(UserMembership $membership, $paymentMethod)
    {
        self::requireStripe($membership);

        if (strpos((string)$paymentMethod, 'pm_') !== 0) {
            throw new MembershipError('Card details were not received. Please try again.');
        }

        try {
            $intent = \Stripe\SetupIntent::create([
                'customer' => $membership->stripe_customer_id,
                'payment_method' => $paymentMethod,
                'payment_method_types' => ['card'],
                'usage' => 'off_session',
                'confirm' => true,
                'metadata' => ['purpose' => self::PURPOSE, 'membership_id' => (string)$membership->id],
            ]);
        } catch (\Stripe\Exception\CardException $e) {
            throw new MembershipError($e->getMessage(), $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Membership card not updated', ['membership' => $membership->id, 'message' => $e->getMessage()]);

            throw new MembershipError('Your card could not be saved. Please try again.', $e->getMessage());
        }

        return self::setupOutcome($membership, $intent);
    }

    /** After the bank's challenge on the page: finish what updateCard started. */
    public static function finishCardUpdate(UserMembership $membership, $setupIntentId)
    {
        self::requireStripe($membership);

        try {
            $intent = \Stripe\SetupIntent::retrieve((string)$setupIntentId);
        } catch (\Throwable $e) {
            throw new MembershipError('That card could not be found.', $e->getMessage());
        }

        if (self::id(self::get($intent, 'customer')) !== $membership->stripe_customer_id) {
            throw new MembershipError('That card could not be found.', 'setup intent belongs to someone else');
        }

        return self::setupOutcome($membership, $intent);
    }

    private static function setupOutcome(UserMembership $membership, $intent)
    {
        $status = (string)self::get($intent, 'status');

        if ($status === 'succeeded') {
            self::useCard($membership, self::id(self::get($intent, 'payment_method')));

            return ['status' => 'updated'];
        }

        if (in_array($status, ['requires_action', 'requires_confirmation'], true)) {
            return ['status' => 'requires_action', 'client_secret' => self::get($intent, 'client_secret'), 'setup_intent' => $intent->id];
        }

        throw new MembershipError(
            self::path($intent, ['last_setup_error', 'message']) ?: 'Your card was declined. Please try another card.',
            'setup ' . $status
        );
    }

    /** Make a confirmed card the one renewals use, and retry a renewal that failed. */
    private static function useCard(UserMembership $membership, $paymentMethod)
    {
        \Stripe\Subscription::update($membership->stripe_subscription_id, ['default_payment_method' => $paymentMethod]);
        \Stripe\Customer::update($membership->stripe_customer_id, ['invoice_settings' => ['default_payment_method' => $paymentMethod]]);

        $membership->log('card_updated');

        if (in_array($membership->status, ['past_due', 'unpaid'], true)) {
            try {
                $subscription = \Stripe\Subscription::retrieve(['id' => $membership->stripe_subscription_id, 'expand' => ['latest_invoice']]);
                $invoice = self::get($subscription, 'latest_invoice');

                if ($invoice && self::get($invoice, 'status') === 'open') {
                    $invoice->pay(['payment_method' => $paymentMethod]);
                }
            } catch (\Throwable $e) {
                // the new card is saved either way; Stripe retries the invoice itself
                Log::warning('Membership renewal retry on the new card failed', ['membership' => $membership->id, 'message' => $e->getMessage()]);
            }
        }

        self::syncSubscription($membership->stripe_subscription_id);
    }

    /** Payments per page in the Manage panel. */
    const HISTORY_PER_PAGE = 6;

    /**
     * The card on file and one page of payments, for the Manage panel.
     * card: brand, last4, exp_month, exp_year, or null if Stripe has none.
     * Page 1 also re-reads the card and the invoices from Stripe; later pages
     * only page through what that read stored.
     */
    public static function billing(UserMembership $membership, $page = 1)
    {
        $card = null;
        $page = max(1, (int)$page);

        // straight from Stripe, so the history does not depend on the webhook arriving
        if ($page === 1) {
            self::syncInvoices($membership);
        }

        if ($page === 1 && $membership->isStripe() && $membership->stripe_subscription_id) {
            try {
                self::client();
                $subscription = \Stripe\Subscription::retrieve([
                    'id' => $membership->stripe_subscription_id,
                    'expand' => ['default_payment_method'],
                ]);
                $details = self::path($subscription, ['default_payment_method', 'card']);

                if ($details) {
                    $card = [
                        'brand' => (string)self::get($details, 'brand'),
                        'last4' => (string)self::get($details, 'last4'),
                        'exp_month' => (int)self::get($details, 'exp_month'),
                        'exp_year' => (int)self::get($details, 'exp_year'),
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning('Membership card not read', ['membership' => $membership->id, 'message' => $e->getMessage()]);
            }
        }

        $paginator = MembershipInvoice::whereIn('user_membership_id',
                UserMembership::where('user_id', $membership->user_id)->pluck('id'))
            ->whereIn('status', ['paid', 'open', 'uncollectible'])
            ->orderByDesc('period_start')->orderByDesc('id')
            ->paginate(self::HISTORY_PER_PAGE, ['*'], 'page', $page);

        $invoices = collect($paginator->items())
            ->map(function ($invoice) {
                return [
                    'number' => $invoice->number,
                    'status' => $invoice->status,
                    'amount' => $invoice->status === 'paid' ? $invoice->amount_paid : $invoice->amount_due,
                    'currency' => $invoice->currency,
                    'period_start' => optional($invoice->period_start)->toISOString(),
                    'period_end' => optional($invoice->period_end)->toISOString(),
                    'paid_at' => optional($invoice->paid_at)->toISOString(),
                ];
            })->values();

        return [
            'card' => $card,
            'invoices' => $invoices,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    // ---------------------------------------------------------- our buttons

    /** Cancel at the end of the paid period (true), or take that back (false). */
    public static function setCancelAtPeriodEnd(UserMembership $membership, $cancel)
    {
        self::requireStripe($membership);

        try {
            $subscription = \Stripe\Subscription::update($membership->stripe_subscription_id, [
                'cancel_at_period_end' => (bool)$cancel,
            ]);
        } catch (\Throwable $e) {
            Log::error('Membership cancel_at_period_end not changed', ['membership' => $membership->id, 'message' => $e->getMessage()]);

            throw new MembershipError('Your membership could not be changed. Please try again.', $e->getMessage());
        }

        return self::syncSubscription($subscription);
    }

    /** End it now. No refund is made here; Stripe's dashboard does refunds. */
    public static function cancelNow(UserMembership $membership)
    {
        self::requireStripe($membership);

        try {
            $subscription = \Stripe\Subscription::retrieve($membership->stripe_subscription_id);

            if ($subscription->status !== 'canceled') {
                $subscription = $subscription->cancel();
            }
        } catch (\Throwable $e) {
            Log::error('Membership not cancelled', ['membership' => $membership->id, 'message' => $e->getMessage()]);

            throw new MembershipError('The membership could not be cancelled: ' . $e->getMessage(), $e->getMessage());
        }

        return self::syncSubscription($subscription);
    }

    /**
     * Move a member to another plan or billing period now. Stripe prorates:
     * the unused part of what they paid is credited against the new price.
     * A change of period (monthly to yearly) starts the new period today and
     * is invoiced straight away.
     */
    public static function changePlan(UserMembership $membership, MembershipPlan $plan, $interval = null)
    {
        self::requireStripe($membership);

        $interval = $interval ?: ($membership->interval ?: 'month');

        if (!$plan->status) {
            throw new MembershipError('This plan is not available.');
        }

        if (!$plan->offers($interval)) {
            throw new MembershipError('This plan is not offered ' . ($interval === 'year' ? 'yearly' : 'monthly') . '.');
        }

        self::syncPlan($plan);

        try {
            $subscription = \Stripe\Subscription::retrieve($membership->stripe_subscription_id);
            $item = $subscription->items->data[0] ?? null;

            if (!$item) {
                throw new MembershipError('This membership cannot be changed.', 'subscription has no items');
            }

            $metadata = array_merge(self::plainMetadata($subscription->metadata), ['plan_id' => (string)$plan->id, 'interval' => $interval]);

            $subscription = \Stripe\Subscription::update($subscription->id, [
                'items' => [['id' => $item->id, 'price' => $plan->stripePriceIdFor($interval)]],
                'proration_behavior' => 'create_prorations',
                'cancel_at_period_end' => false,
                'metadata' => $metadata,
            ]);
        } catch (MembershipError $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Membership plan not changed', ['membership' => $membership->id, 'message' => $e->getMessage()]);

            throw new MembershipError('Your plan could not be changed. Please try again.', $e->getMessage());
        }

        $membership = self::syncSubscription($subscription);

        return $membership;
    }

    private static function requireStripe(UserMembership $membership)
    {
        if (!$membership->isStripe() || !$membership->stripe_subscription_id) {
            throw new MembershipError('This membership is not billed by card.');
        }

        self::client();
    }

    // ------------------------------------------------------------- mirroring

    /**
     * Mirror one Stripe subscription onto user_memberships. Takes the object
     * or its id (re-read from Stripe). Returns null for a subscription that
     * is not a membership of ours.
     */
    public static function syncSubscription($subscription)
    {
        self::client();

        if (is_string($subscription)) {
            try {
                $subscription = \Stripe\Subscription::retrieve($subscription);
            } catch (\Throwable $e) {
                Log::warning('Stripe subscription not read', ['subscription' => $subscription, 'message' => $e->getMessage()]);
                return null;
            }
        }

        if (!$subscription || !self::get($subscription, 'id')) {
            return null;
        }

        $existing = UserMembership::where('stripe_subscription_id', $subscription->id)->first();
        $meta = self::plainMetadata(self::get($subscription, 'metadata'));

        if (!$existing && ($meta['purpose'] ?? null) !== self::PURPOSE) {
            return null;
        }

        /*
         * A join still waiting for the bank, or one that was refused, is not
         * a membership: no row until it is paid. One that was ever a member
         * is kept up to date whatever happens to it.
         */
        if (!$existing && in_array((string)self::get($subscription, 'status'), ['incomplete', 'incomplete_expired', 'canceled'], true)) {
            return null;
        }

        $item = self::path($subscription, ['items', 'data', 0]);
        $price = $item ? self::get($item, 'price') : null;
        $priceMeta = $price ? self::plainMetadata(self::get($price, 'metadata')) : [];

        $plan = MembershipPlan::find($priceMeta['plan_id'] ?? null)
            ?? MembershipPlan::find($meta['plan_id'] ?? null)
            ?? ($price ? MembershipPlan::where('stripe_price_id', $price->id)->orWhere('stripe_yearly_price_id', $price->id)->first() : null)
            ?? optional($existing)->plan;

        $user = User::find($existing ? $existing->user_id : ($meta['user_id'] ?? null));
        $customer = self::id(self::get($subscription, 'customer'));

        if (!$plan || !$user) {
            Log::warning('Stripe membership with no plan or customer here', ['subscription' => $subscription->id]);
            return null;
        }

        // A new row must be for the account that owns the Stripe customer.
        if (!$existing && $user->stripe_customer_id && $customer && $customer !== $user->stripe_customer_id) {
            Log::warning('Stripe membership customer does not match the account', ['subscription' => $subscription->id, 'user' => $user->id]);
            return null;
        }

        $status = (string)self::get($subscription, 'status');

        $data = [
            'user_id' => $user->id,
            'membership_plan_id' => $plan->id,
            'source' => UserMembership::SOURCE_STRIPE,
            'status' => $status,
            'stripe_customer_id' => $customer,
            'price' => $price ? ((int)self::get($price, 'unit_amount')) / 100 : $plan->price,
            'currency' => strtoupper((string)($price ? self::get($price, 'currency') : $plan->currency)),
            'interval' => self::path($price, ['recurring', 'interval']) ?? 'month',
            'interval_count' => (int)(self::path($price, ['recurring', 'interval_count']) ?? $plan->interval_count),
            // on the subscription in older API versions, on the item since 2025-03
            'current_period_start' => self::ts(self::get($subscription, 'current_period_start') ?? self::get($item, 'current_period_start')),
            'current_period_end' => self::ts(self::get($subscription, 'current_period_end') ?? self::get($item, 'current_period_end')),
            'trial_ends_at' => self::ts(self::get($subscription, 'trial_end')),
            'cancel_at_period_end' => (bool)self::get($subscription, 'cancel_at_period_end')
                || (self::get($subscription, 'cancel_at') && $status !== 'canceled'),
            'canceled_at' => self::ts(self::get($subscription, 'canceled_at')),
            'ended_at' => self::ts(self::get($subscription, 'ended_at')),
            'last_synced_at' => Carbon::now(),
        ];

        $before = $existing ? [$existing->status, (bool)$existing->cancel_at_period_end, (int)$existing->membership_plan_id, $existing->interval] : null;

        try {
            $membership = $existing ?: new UserMembership();
            $membership->fill($data);
            $membership->stripe_subscription_id = $subscription->id;
            $membership->save();
        } catch (\Illuminate\Database\QueryException $e) {
            // The webhook and the returning shopper wrote it at the same moment.
            $membership = UserMembership::where('stripe_subscription_id', $subscription->id)->firstOrFail();
            $membership->fill($data);
            $membership->save();
            $before = $before ?? [$membership->getOriginal('status'), (bool)$membership->getOriginal('cancel_at_period_end'), (int)$membership->getOriginal('membership_plan_id'), $membership->getOriginal('interval')];
        }

        self::logChanges($membership, $before, $plan);

        if (!$user->stripe_customer_id && $customer) {
            $user->stripe_customer_id = $customer;
            $user->save();
        }

        return $membership->fresh(['plan.benefits']);
    }

    private static function logChanges(UserMembership $membership, $before, MembershipPlan $plan)
    {
        if (is_null($before)) {
            $membership->log('subscribed', $plan->name . ', ' . number_format($membership->price, 2) . ' ' . $membership->currency
                . ' ' . MembershipPlan::describeInterval($membership->interval, $membership->interval_count));
            return;
        }

        [$status, $cancelling, $planId, $interval] = $before;

        if ($status !== $membership->status) {
            $membership->log('status', $status . ' -> ' . $membership->status);
        }

        if ($cancelling !== (bool)$membership->cancel_at_period_end) {
            $membership->log($membership->cancel_at_period_end ? 'cancel_scheduled' : 'cancel_withdrawn');
        }

        if ($planId !== (int)$membership->membership_plan_id) {
            $membership->log('plan_changed', 'to ' . $plan->name);
        }

        if ($interval && $interval !== $membership->interval) {
            $membership->log('billing_changed', ($membership->interval === 'year' ? 'to yearly' : 'to monthly')
                . ', ' . number_format($membership->price, 2) . ' ' . $membership->currency);
        }
    }

    /**
     * Record this membership's invoices as Stripe has them now: the first
     * payment, renewals, failures. The webhook does the same as they happen;
     * this covers a site Stripe cannot reach and any event it gave up on.
     */
    public static function syncInvoices(UserMembership $membership)
    {
        if (!$membership->isStripe() || !$membership->stripe_subscription_id) {
            return;
        }

        try {
            self::client();

            $invoices = \Stripe\Invoice::all(['subscription' => $membership->stripe_subscription_id, 'limit' => 100]);

            foreach ($invoices->autoPagingIterator() as $invoice) {
                self::recordInvoice($invoice);
            }
        } catch (\Throwable $e) {
            Log::warning('Membership invoices not read from Stripe', ['membership' => $membership->id, 'message' => $e->getMessage()]);
        }
    }

    /** Record (or update) the invoice for a membership period. */
    public static function recordInvoice($invoice)
    {
        $subscriptionId = self::subscriptionOf($invoice);

        if (!$subscriptionId) {
            return null;
        }

        $membership = UserMembership::where('stripe_subscription_id', $subscriptionId)->first()
            ?? self::syncSubscription($subscriptionId);

        if (!$membership) {
            return null;
        }

        $line = self::path($invoice, ['lines', 'data', 0]);

        return MembershipInvoice::updateOrCreate(
            ['stripe_invoice_id' => $invoice->id],
            [
                'user_membership_id' => $membership->id,
                'number' => self::get($invoice, 'number'),
                'status' => (string)self::get($invoice, 'status'),
                'amount_due' => ((int)self::get($invoice, 'amount_due')) / 100,
                'amount_paid' => ((int)self::get($invoice, 'amount_paid')) / 100,
                'currency' => strtoupper((string)self::get($invoice, 'currency')),
                'period_start' => self::ts(self::path($line, ['period', 'start']) ?? self::get($invoice, 'period_start')),
                'period_end' => self::ts(self::path($line, ['period', 'end']) ?? self::get($invoice, 'period_end')),
                'paid_at' => self::ts(self::path($invoice, ['status_transitions', 'paid_at'])),
                'hosted_invoice_url' => self::get($invoice, 'hosted_invoice_url'),
            ]
        );
    }

    // ---------------------------------------------------------------- webhook

    const SUBSCRIPTION_EVENTS = [
        'customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted',
        'customer.subscription.paused', 'customer.subscription.resumed',
    ];

    const INVOICE_EVENTS = [
        'invoice.paid', 'invoice.payment_succeeded', 'invoice.payment_failed', 'invoice.finalized',
        'invoice.voided', 'invoice.marked_uncollectible',
    ];

    /** Act on one verified Stripe event. Returns 'synced' or 'ignored'. */
    public static function handleEvent($event)
    {
        $type = (string)self::get($event, 'type');
        $object = self::path($event, ['data', 'object']);

        if (!$object) {
            return 'ignored';
        }

        if (in_array($type, self::SUBSCRIPTION_EVENTS, true)) {
            // re-read: an event can arrive after a newer one
            return self::syncSubscription((string)$object->id) ? 'synced' : 'ignored';
        }

        if (in_array($type, self::INVOICE_EVENTS, true)) {
            $invoice = self::recordInvoice($object);

            if (!$invoice) {
                return 'ignored';
            }

            if ($type === 'invoice.payment_failed') {
                $invoice->membership->log('payment_failed', 'invoice ' . ($invoice->number ?: $invoice->stripe_invoice_id));
            }

            // the period moves on when a renewal is paid; the status when it fails
            self::syncSubscription(self::subscriptionOf($object));

            return 'synced';
        }

        return 'ignored';
    }

    // ---------------------------------------------------------------- helpers

    private static function subscriptionOf($invoice)
    {
        return self::id(self::get($invoice, 'subscription'))
            ?: self::id(self::path($invoice, ['parent', 'subscription_details', 'subscription']));
    }

    /** A property of a Stripe object (or array), null when absent - no notices. */
    private static function get($object, $key)
    {
        if (is_null($object)) {
            return null;
        }

        if (is_array($object)) {
            return $object[$key] ?? null;
        }

        return isset($object->{$key}) ? $object->{$key} : null;
    }

    private static function path($object, array $keys)
    {
        foreach ($keys as $key) {
            if (is_null($object)) {
                return null;
            }

            $object = is_int($key)
                ? (is_array($object) || $object instanceof \ArrayAccess ? ($object[$key] ?? null) : null)
                : self::get($object, $key);
        }

        return $object;
    }

    /** An id whether Stripe sent the id or the expanded object. */
    private static function id($value)
    {
        if (is_string($value)) {
            return $value;
        }

        return $value ? self::get($value, 'id') : null;
    }

    private static function plainMetadata($metadata)
    {
        if (is_null($metadata)) {
            return [];
        }

        return is_array($metadata) ? $metadata : $metadata->toArray();
    }

    private static function ts($value)
    {
        return $value ? Carbon::createFromTimestamp((int)$value) : null;
    }
}
