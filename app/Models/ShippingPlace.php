<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShippingPlace extends Model
{
    use HasFactory;

    protected $fillable = [
        'id', 'country', 'state', 'price', 'weight_rates', 'weight_extra_per_kg', 'pickup_price',
        'pickup_point', 'shipping_rule_id', 'admin_id' , 'day_needed',
        'pickup_phone', 'pickup_address_line_1', 'pickup_address_line_2', 'pickup_zip',
        'pickup_state', 'pickup_city', 'pickup_country'
    ];

    // weight bands, lightest first (see Helper\Shipping::deliveryPrice)
    protected $casts = [
        'weight_rates' => 'array',
    ];

    protected $hidden = [
        'admin_id',  'created_at',  'updated_at'
    ];

    public function shipping_rule()
    {
        return $this->hasOne(ShippingRule::class, 'id', 'shipping_rule_id');
    }

}
