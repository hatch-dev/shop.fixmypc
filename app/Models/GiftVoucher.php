<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GiftVoucher extends Model
{
    use HasFactory;

    protected $table = 'gift_voucher';

    protected $fillable = [
        'title',
        'image',
        'description',
        'amounts',
        'min_quantity',
        'max_quantity',
    ];

    protected $casts = [
        'amounts' => 'array',
    ];
}
