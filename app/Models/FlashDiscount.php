<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

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

    protected $casts = [
        'start_time' => 'datetime:Y-m-d H:i:s',
        'end_time' => 'datetime:Y-m-d H:i:s',
    ];

    public function scopeActive($query)
    {
        return $query
            ->where('is_active', 1)
            ->where('start_time', '<=', now())
            ->where('end_time', '>=', now());
    }

}
