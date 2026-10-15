<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A membership customers can buy, billed by Stripe (see Helper\StripeBilling).
 *
 * Offered monthly (price), yearly (yearly_price), or both; the customer picks
 * the period when they join. Each period has its own Stripe Price. Changing a
 * price makes a new Stripe Price; people already subscribed stay on the one
 * they bought, which is why user_memberships keeps its own copy.
 *
 * interval/interval_count are left from when a plan had one period and no
 * longer decide anything.
 */
class MembershipPlan extends Model
{
    const INTERVALS = ['month', 'year'];

    protected $fillable = [
        'name', 'slug', 'tagline', 'price', 'yearly_price', 'currency', 'interval', 'interval_count',
        'trial_days', 'status', 'sort_order',
        'stripe_product_id', 'stripe_price_id', 'stripe_price_key',
        'stripe_yearly_price_id', 'stripe_yearly_price_key',
    ];

    protected $casts = [
        'price' => 'float',
        'yearly_price' => 'float',
        'interval_count' => 'integer',
        'trial_days' => 'integer',
        'status' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function benefits()
    {
        return $this->hasMany(MembershipPlanBenefit::class)->orderBy('sort_order')->orderBy('id');
    }

    public function memberships()
    {
        return $this->hasMany(UserMembership::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 1);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /** The price for 'month' or 'year', null when that period is not offered. */
    public function priceFor($interval)
    {
        $price = $interval === 'year' ? $this->yearly_price : $this->price;

        return (float)$price > 0 ? (float)$price : null;
    }

    public function offers($interval)
    {
        return !is_null($this->priceFor($interval));
    }

    /** The Stripe Price currently sold for that period. */
    public function stripePriceIdFor($interval)
    {
        return $interval === 'year' ? $this->stripe_yearly_price_id : $this->stripe_price_id;
    }

    /** Periods on offer, monthly first. */
    public function intervals()
    {
        return array_values(array_filter(self::INTERVALS, function ($interval) {
            return $this->offers($interval);
        }));
    }

    /** Whole percent a year costs less than twelve months, or null. */
    public function yearlySaving()
    {
        $monthly = $this->priceFor('month');
        $yearly = $this->priceFor('year');

        if (!$monthly || !$yearly) {
            return null;
        }

        $saving = (int)floor((1 - $yearly / ($monthly * 12)) * 100);

        return $saving > 0 ? $saving : null;
    }

    /** "per month", "every 3 months", "per year". */
    public static function describeInterval($interval, $count = 1)
    {
        $interval = $interval ?: 'month';
        $count = max(1, (int)$count);

        return $count === 1 ? 'per ' . $interval : 'every ' . $count . ' ' . $interval . 's';
    }

    /** The benefit of a given type, or null. A plan has at most one of each money type. */
    public function benefit($type)
    {
        return $this->benefits->firstWhere('type', $type);
    }

    /** What the storefront may see of a plan. */
    public function toPublic()
    {
        $prices = array_map(function ($interval) {
            return [
                'interval' => $interval,
                'price' => $this->priceFor($interval),
                'interval_label' => self::describeInterval($interval),
                // a year's price spread over twelve months, for "€8.25/month billed yearly"
                'per_month' => $interval === 'year' ? round($this->priceFor('year') / 12, 2) : $this->priceFor('month'),
            ];
        }, $this->intervals());

        $first = $prices[0] ?? null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'tagline' => $this->tagline,
            'prices' => $prices,
            'yearly_saving' => $this->yearlySaving(),
            // the first period on offer, for anything that shows one price
            'price' => $first ? $first['price'] : null,
            'currency' => $this->currency,
            'interval' => $first ? $first['interval'] : null,
            'interval_label' => $first ? $first['interval_label'] : null,
            'trial_days' => $this->trial_days,
            'benefits' => $this->benefits->map(function ($b) {
                return $b->toPublic();
            })->values(),
        ];
    }
}
