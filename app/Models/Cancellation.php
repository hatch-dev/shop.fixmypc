<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cancellation extends Model
{

    protected $casts = [
        'refunded' => 'integer',
        'refunded_at' => 'datetime',
        'refunded_amount' => 'decimal:2'
    ];


    /**
     * QA audit RFN-02.
     *
     * 'refunded' was fillable and cancelOrder() called
     * Cancellation::create($request->all()) on a customer-facing endpoint, so a
     * customer could post "refunded": 1 and have their own cancellation show up
     * in the admin queue as already settled. The refund columns are written
     * only by CancellationsController::refund(), after the gateway confirms.
     */
    protected $fillable = [
        'order_id', 'user_id', 'title', 'message', 'user_token'
    ];

    use HasFactory;
}
