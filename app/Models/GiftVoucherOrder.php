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
        // who the codes are for, when bought as a gift
        'recipient_name',
        'recipient_email',
        'sender_name',
        'message',
    ];

    public function codes()
    {
        return $this->hasMany(GiftVoucherCode::class, 'gift_voucher_order_id');
    }

    public function design()
    {
        return $this->belongsTo(GiftVoucher::class, 'voucher_id');
    }

    public function buyer()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
