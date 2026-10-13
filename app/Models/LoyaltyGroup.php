<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoyaltyGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'customer_label',
        'status',
        'discount_type',
        'discount_value',
        'min_cart_value',
        'max_discount',
        'validity',
        'period_days',
        'start_date',
        'end_date'
    ];

    protected $casts = [
        'status' => 'boolean',
        'discount_value' => 'float',
        'min_cart_value' => 'float',
        'max_discount' => 'float',
        'period_days' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date'
    ];

    public function users()
    {
        return $this->belongsToMany(
            User::class,
            'loyalty_group_users',
            'loyalty_group_id',
            'user_id'
        )->withPivot(['id', 'joined_at', 'expires_at', 'used_at', 'used_order_id'])
         ->withTimestamps();
    }

    public function members()
    {
        return $this->hasMany(LoyaltyGroupUser::class, 'loyalty_group_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 1);
    }

    /**
     * The line the shopper reads on their order summary.
     *
     * Never falls back to `title`: that is the admin's internal name for the
     * group, and printing it is what put "Group 1" on a customer's basket.
     */
    public function displayLabel()
    {
        $label = trim((string)$this->customer_label);

        return $label !== '' ? $label : 'Loyalty Discount';
    }

    /**
     * The benefit in one short phrase, e.g. "10% off" or "EUR 25.00 off",
     * with the condition appended when there is one. Used for the storefront
     * nudge, so the shopper knows what they are being offered.
     */
    public function benefitSummary($currency = '')
    {
        $value = (float)$this->discount_value;

        $benefit = $this->discount_type === 'percentage'
            ? rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . '% off'
            : $currency . number_format($value, 2) . ' off';

        if ($this->discount_type === 'percentage'
            && !is_null($this->max_discount)
            && (float)$this->max_discount > 0) {

            $benefit .= ' (up to ' . $currency . number_format((float)$this->max_discount, 2) . ')';
        }

        return $benefit;
    }

    /**
     * Is the group itself live right now?
     *
     * 'date_range' is the only validity that bounds the *group*. 'one_time'
     * and 'period' bound the individual membership, and are answered by
     * LoyaltyGroupUser::isRedeemable().
     */
    public function isRunning($at = null)
    {
        if (!$this->status) {
            return false;
        }

        if ($this->validity !== 'date_range') {
            return true;
        }

        $at = $at ? Carbon::parse($at) : Carbon::now();

        if ($this->start_date && $at->lt($this->start_date->startOfDay())) {
            return false;
        }

        if ($this->end_date && $at->gt($this->end_date->endOfDay())) {
            return false;
        }

        return true;
    }

    /**
     * When a membership created at $joinedAt stops being usable, or null when
     * nothing time-bounds it.
     *
     * A 'period' group counts from the member's own joining date, so two
     * members of the same group can expire on different days. A 'date_range'
     * group closes on the group's date for everyone. A 'one_time' membership
     * has no deadline at all: it lasts until it is spent.
     */
    public function expiryFor($joinedAt = null)
    {
        if ($this->validity === 'period' && $this->period_days > 0) {
            return Carbon::parse($joinedAt)->copy()->addDays($this->period_days);
        }

        if ($this->validity === 'date_range' && $this->end_date) {
            return $this->end_date->copy()->endOfDay();
        }

        return null;
    }
}
