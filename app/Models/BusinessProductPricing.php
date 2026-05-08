<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BusinessProductPricing extends Model
{
    use HasFactory;

    protected $table = 'business_product_pricing';

    protected $fillable = [
        'product_id',
        'min_qty',
        'max_qty',
        'wholesale_price',
        'discount_type',
        'discount_value',
        'final_price',
    ];
}
