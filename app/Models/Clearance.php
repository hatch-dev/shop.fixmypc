<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Clearance extends Model
{
    protected $fillable = [
        'product_id', 'title', 'subtitle', 'discount_type', 'discount_value',
        'description', 'highlights', 'end_time', 'status', 'sort_order', 'admin_id'
    ];

    protected $hidden = ['admin_id'];

    /**
     * The product attached to this clearance deal. Pricing, stock, rating and
     * media are all read from the product record (clearance only stores the
     * selection + a few page-level options).
     */
    public function product()
    {
        return $this->hasOne(Product::class, 'id', 'product_id')
            ->select(['id', 'title', 'short_heading', 'slug', 'image', 'selling', 'offered', 'purchased', 'badge', 'rating', 'review_count', 'description', 'overview', 'status']);
    }
}
