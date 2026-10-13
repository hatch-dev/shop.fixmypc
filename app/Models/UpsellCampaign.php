<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

/**
 * An upsell campaign: offer a better alternative to the product the customer
 * has or is looking at.
 *
 * An upsell REPLACES rather than accompanies. That is not a storefront detail
 * -- it decides what the admin screens ask for ("upgrade options", not
 * "related products") and it is why this is its own module rather than a row
 * type on something shared with cross-sell.
 *
 * Carries no money of its own. What an upgrade costs is decided by the
 * catalogue, the business bands, the flash sales and the clearances at the
 * moment it is asked for, exactly as it would be had the customer found the
 * product themselves.
 */
class UpsellCampaign extends Model
{
    use HasFactory;

    const CUSTOMER_TYPES = ['all', 'personal', 'business', 'trade'];

    protected $fillable = [
        'name',
        'description',
        'status',
        'customer_type',
        'minimum_cart_value',
        'show_on_product_page',
        'show_on_cart_page',
        'show_on_checkout_page',
        'start_date',
        'end_date',
        'priority',
        'created_by',
    ];

    protected $casts = [
        'status' => 'integer',
        'priority' => 'integer',
        'show_on_product_page' => 'boolean',
        'show_on_cart_page' => 'boolean',
        'show_on_checkout_page' => 'boolean',
        'minimum_cart_value' => 'float',
    ];

    public function sources()
    {
        return $this->hasMany(UpsellSource::class, 'upsell_campaign_id');
    }

    public function options()
    {
        return $this->hasMany(UpsellOption::class, 'upsell_campaign_id');
    }


    /* ------------------------------------------------------------------ *
     *  State
     * ------------------------------------------------------------------ */

    public function isActive()
    {
        return (int)$this->status === (int)Config::get('constants.status.PUBLIC');
    }

    /**
     * Where this campaign sits against its own dates.
     *
     * Deliberately separate from the on/off switch. A campaign an admin has
     * switched on but dated for next month is not broken, and saying
     * "Scheduled" is the difference between an admin understanding why nothing
     * is showing and opening a support ticket.
     *
     * Returns one of: always, running, scheduled, expired.
     */
    public function scheduleState($now = null)
    {
        $now = $now ?: date('Y-m-d H:i:s');

        if (empty($this->start_date) && empty($this->end_date)) {
            return 'always';
        }

        if (!empty($this->start_date) && $this->start_date > $now) {
            return 'scheduled';
        }

        if (!empty($this->end_date) && $this->end_date < $now) {
            return 'expired';
        }

        return 'running';
    }

    /**
     * Would a customer see this right now?
     *
     * Switched on AND inside its dates. The list shows this as the green dot,
     * because "Active" on its own has repeatedly been read as "customers can
     * see it" when the end date had passed a week earlier.
     */
    public function isLive($now = null)
    {
        return $this->isActive()
            && in_array($this->scheduleState($now), ['always', 'running'], true);
    }

    /**
     * Is this campaign aimed at this account?
     *
     * A guest ($customer null) matches 'all' and nothing else: the named types
     * are properties of an account, and there is no account to read.
     */
    public function appliesTo($customer = null)
    {
        if ($this->customer_type === 'all') {
            return true;
        }

        if (is_null($customer)) {
            return false;
        }

        return $this->customer_type === $customer->account_type;
    }


    /* ------------------------------------------------------------------ *
     *  Queries
     * ------------------------------------------------------------------ */

    /** Switched on and inside its dates, decided in SQL. */
    public function scopeLive($query, $now = null)
    {
        $now = $now ?: date('Y-m-d H:i:s');

        return $query
            ->where('status', Config::get('constants.status.PUBLIC'))
            ->where(function ($q) use ($now) {
                $q->whereNull('start_date')->orWhere('start_date', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $now);
            });
    }
}
