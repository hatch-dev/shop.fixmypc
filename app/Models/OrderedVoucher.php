<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderedVoucher extends Model
{
    use HasFactory;

    protected $table = 'ordered_voucher';

    protected $fillable = [
        'order_id',
        'voucher_id',
        'discount',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }
}
