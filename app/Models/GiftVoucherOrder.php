<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GiftVoucherOrder extends Model
{
    use HasFactory;

    protected $table = 'gift_voucher_orders';

    protected $fillable = [
        'user_id',
        'voucher_id',
        'amount',
        'quantity',
        'total',
        'payment_method',
        'reference',
        'status',
    ];
}
