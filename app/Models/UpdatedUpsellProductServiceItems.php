<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UpdatedUpsellProductServiceItems extends Model
{
    use HasFactory;

    protected $table = 'updated_upsell_product_service_items';

    protected $fillable = [
        'updated_upsell_product_service_id',
        'name',
        'price',
        'product_id',
        'inventory_id',
    ];
}
