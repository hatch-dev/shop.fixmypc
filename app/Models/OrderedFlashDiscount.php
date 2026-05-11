<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderedFlashDiscount extends Model
{
    use HasFactory;

    protected $table = 'ordered_flash_discount';

    protected $fillable = [
        'order_id',
        'flash_discount_id',
        'discount',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function flashDiscount()
    {
        return $this->belongsTo(FlashDiscount::class, 'flash_discount_id');
    }
}
