<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The Essentials Bundle discount as it was granted on one order.
 *
 * The amount that actually came off, not the rule that produced it: the
 * bundle's percentage can be edited and the bundle itself deleted, and neither
 * may change what a past order says it charged. Same reason OrderedVoucher
 * exists beside `vouchers`.
 */
class OrderedBundleDiscount extends Model
{
    use HasFactory;

    protected $table = 'ordered_bundle_discount';

    protected $fillable = [
        'order_id',
        'bundle_deal_id',
        'title',
        'discount',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function bundleDeal()
    {
        return $this->belongsTo(BundleDeal::class, 'bundle_deal_id');
    }
}
