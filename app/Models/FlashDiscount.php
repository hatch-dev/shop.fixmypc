<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

/**
 * A store-wide discount: a window, and a percentage or a fixed amount off the
 * whole basket. One is live at a time -- Validation::flashDiscount refuses a
 * window that overlaps another live one.
 */
class FlashDiscount extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'value',
        'min_cart_value',
        'max_discount',
        'start_time',
        'end_time',
        'is_active'
    ];

    /*
     * The times are serialised with a T.
     *
     * 'datetime:Y-m-d H:i:s' produced "2026-09-18 10:08:07", which
     * <input type="datetime-local"> cannot parse -- so the admin edit form
     * opened with both date fields blank and saving then failed on
     * "Start & End time required". The T form is what the control reads back
     * and what it posts, and MySQL accepts it on the way in.
     *
     * is_active is a real boolean so the form's checkbox round-trips instead
     * of binding against 1 and posting back true.
     */
    protected $casts = [
        'start_time' => 'datetime:Y-m-d\TH:i:s',
        'end_time' => 'datetime:Y-m-d\TH:i:s',
        'is_active' => 'boolean',
    ];

    /**
     * Live now, newest first.
     *
     * The ordering is the point. ->first() with no order returned whichever
     * row the database handed back, so with two overlapping promotions which
     * one a customer got was row order -- and the basket banner and the order
     * could pick differently. Overlaps are now refused at the door, but the
     * ordering stays: rows predating that rule can still overlap, and the most
     * recently created promotion is the one an admin last meant to run.
     */
    public function scopeActive($query)
    {
        return $query
            ->where('is_active', 1)
            ->where('start_time', '<=', now())
            ->where('end_time', '>=', now())
            ->orderByDesc('id');
    }

    /**
     * The one live discount, for the storefront banner and for the checkout.
     *
     * Both go through here so what is advertised is what is charged.
     */
    public static function current()
    {
        return static::active()->first();
    }

    /** Whether this row is running right now, for the admin list. */
    public function isLive()
    {
        if (!$this->is_active) {
            return false;
        }

        return Carbon::now()->betweenIncluded($this->start_time, $this->end_time);
    }
}
