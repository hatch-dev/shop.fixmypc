<?php

namespace App\Console\Commands;

use App\Models\Helper\StripeBilling;
use App\Models\UserMembership;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Re-read every open membership subscription from Stripe.
 *
 * The webhook keeps memberships current within seconds. This is the net
 * under it: an event Stripe gave up delivering, a site without a webhook
 * yet (a local copy), a period that ended while nothing was listening. It
 * also ends granted memberships whose end date has passed, so the admin
 * list stops showing them as active.
 */
class SyncMemberships extends Command
{
    protected $signature = 'membership:sync {--all : also re-read subscriptions that already ended}';

    protected $description = 'Re-read membership subscriptions from Stripe and close lapsed granted memberships';

    public function handle()
    {
        $now = Carbon::now();

        $expired = UserMembership::where('source', UserMembership::SOURCE_ADMIN)
            ->where('status', 'active')
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', $now)
            ->get();

        foreach ($expired as $membership) {
            $membership->update(['status' => 'canceled', 'ended_at' => $membership->current_period_end]);
            $membership->log('expired', 'granted membership reached its end date');
        }

        $this->info($expired->count() . ' granted membership(s) ended.');

        if (!StripeBilling::ready()) {
            $this->warn('Stripe is not configured; subscriptions not checked.');

            return 0;
        }

        $query = UserMembership::where('source', UserMembership::SOURCE_STRIPE)->whereNotNull('stripe_subscription_id');

        if (!$this->option('all')) {
            $query->whereNotIn('status', ['canceled', 'incomplete_expired']);
        }

        $synced = 0;
        $failed = 0;

        foreach ($query->cursor() as $membership) {
            try {
                if ($fresh = StripeBilling::syncSubscription($membership->stripe_subscription_id)) {
                    StripeBilling::syncInvoices($fresh);
                    $synced++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->error('#' . $membership->id . ': ' . $e->getMessage());
            }
        }

        $this->info("{$synced} subscription(s) synced, {$failed} not.");

        return 0;
    }
}
