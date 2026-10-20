<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** See App\Models\Helper\PayLinks. */
class PayLink extends Model
{
    const PENDING = 'pending';
    const PAID = 'paid';
    const CANCELLED = 'cancelled';

    protected $fillable = [
        'token', 'order_return_id', 'order_id', 'amount', 'status', 'currency', 'gateway', 'stock_reserved',
        'expires_at', 'created_by',
    ];

    protected $casts = [
        'amount' => 'float',
        'gateway' => 'integer',
        'stock_reserved' => 'boolean',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected $hidden = ['token'];

    public function isExpired()
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function orderReturn()
    {
        return $this->belongsTo(OrderReturn::class, 'order_return_id');
    }
}
