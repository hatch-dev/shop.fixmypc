<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * A membership a customer holds or held.
 *
 * source 'stripe': a Stripe subscription, and every field below mirrors it
 * (Helper\StripeBilling::syncSubscription). status is Stripe's own word:
 * incomplete, incomplete_expired, trialing, active, past_due, unpaid,
 * canceled, paused.
 *
 * source 'admin': granted from the admin panel with no payment; status is
 * active or canceled, current_period_end is when it ends (null = no end).
 *
 * price/currency/interval are what THIS member pays, copied when the
 * subscription was made, so editing the plan never rewrites them.
 */
class UserMembership extends Model
{
    const SOURCE_STRIPE = 'stripe';
    const SOURCE_ADMIN = 'admin';

    /**
     * Statuses that carry benefits. past_due is in: Stripe is still retrying
     * the renewal, and taking free delivery away between retries punishes a
     * card that expired, not a member who left. When the retries run out
     * Stripe moves it to canceled or unpaid and the benefits stop.
     */
    const ENTITLED = ['active', 'trialing', 'past_due'];

    /** Not over: a new subscription must not be started on top of one of these. */
    const OPEN = ['active', 'trialing', 'past_due', 'unpaid', 'paused'];

    /**
     * How long a paid period is honoured past its end while the renewal is
     * on its way from Stripe. The webhook usually lands in seconds; this
     * covers a missed one until the daily membership:sync catches up.
     */
    const RENEWAL_LEEWAY_HOURS = 24;

    protected $fillable = [
        'user_id', 'membership_plan_id', 'source', 'status',
        'stripe_subscription_id', 'stripe_customer_id', 'stripe_checkout_session_id',
        'price', 'currency', 'interval', 'interval_count',
        'current_period_start', 'current_period_end', 'trial_ends_at',
        'cancel_at_period_end', 'canceled_at', 'ended_at',
        'granted_by_admin_id', 'note', 'last_synced_at',
    ];

    protected $casts = [
        'price' => 'float',
        'interval_count' => 'integer',
        'cancel_at_period_end' => 'boolean',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'trial_ends_at' => 'datetime',
        'canceled_at' => 'datetime',
        'ended_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function plan()
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }

    public function invoices()
    {
        return $this->hasMany(MembershipInvoice::class)->orderByDesc('period_start')->orderByDesc('id');
    }

    public function events()
    {
        return $this->hasMany(MembershipEvent::class)->orderByDesc('id');
    }

    /**
     * Memberships carrying benefits at $at. The period test is in SQL so a
     * lapsed row whose status was never updated (a missed webhook) cannot
     * keep paying out for ever.
     */
    public function scopeEntitled($query, $at = null)
    {
        $at = $at ? Carbon::parse($at) : Carbon::now();
        $leeway = $at->copy()->subHours(self::RENEWAL_LEEWAY_HOURS);

        return $query->where(function ($q) use ($at, $leeway) {
            $q->where(function ($q) use ($at) {
                $q->where('source', self::SOURCE_ADMIN)
                    ->where('status', 'active')
                    ->where(function ($q) use ($at) {
                        $q->whereNull('current_period_end')->orWhere('current_period_end', '>', $at);
                    });
            })->orWhere(function ($q) use ($leeway) {
                $q->where('source', self::SOURCE_STRIPE)
                    ->whereIn('status', self::ENTITLED)
                    ->where(function ($q) use ($leeway) {
                        $q->whereNull('current_period_end')->orWhere('current_period_end', '>', $leeway);
                    });
            });
        });
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', self::OPEN);
    }

    public function isEntitled($at = null)
    {
        return static::whereKey($this->id)->entitled($at)->exists();
    }

    public function isStripe()
    {
        return $this->source === self::SOURCE_STRIPE;
    }

    /** One word for a person: Active, Trial, Payment due, Ending, Ended... */
    public function statusLabel()
    {
        if ($this->status === 'trialing') {
            return 'Trial';
        }

        if (in_array($this->status, ['active', 'past_due'], true) && $this->cancel_at_period_end) {
            return 'Ending';
        }

        return [
            'active' => 'Active',
            'past_due' => 'Payment due',
            'unpaid' => 'Unpaid',
            'paused' => 'Paused',
            'incomplete' => 'Awaiting payment',
            'incomplete_expired' => 'Not completed',
            'canceled' => 'Ended',
        ][$this->status] ?? ucfirst((string)$this->status);
    }

    public function log($action, $detail = null, $adminId = null)
    {
        return MembershipEvent::create([
            'user_membership_id' => $this->id,
            'admin_id' => $adminId,
            'action' => $action,
            'detail' => $detail ? mb_substr($detail, 0, 255) : null,
        ]);
    }
}
