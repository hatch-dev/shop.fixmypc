<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One Stripe invoice for a membership period, as Stripe last reported it. */
class MembershipInvoice extends Model
{
    protected $fillable = [
        'user_membership_id', 'stripe_invoice_id', 'number', 'status',
        'amount_due', 'amount_paid', 'currency',
        'period_start', 'period_end', 'paid_at', 'hosted_invoice_url',
    ];

    protected $casts = [
        'amount_due' => 'float',
        'amount_paid' => 'float',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function membership()
    {
        return $this->belongsTo(UserMembership::class, 'user_membership_id');
    }
}
