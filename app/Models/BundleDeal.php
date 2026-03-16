<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BundleDeal extends Model
{
    use HasFactory;

    protected $fillable = [
        'title', 'description', 'discount_type', 'discount_value', 'buy', 'free', 'admin_id'
    ];

    protected $hidden = [
        'admin_id'
    ];

    public function products()
    {
        return $this->belongsToMany(
            Product::class,
            'bundle_deal_products',
            'bundle_deal_id',
            'product_id'
        );
    }
}
