<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One customer-initiated change of account type. See AccountUpgrade for the
 * rules and the migration for why the details wait here until approval.
 */
class AccountUpgradeRequest extends Model
{
    /** Waiting for staff. Only trade applications are ever pending. */
    const STATUS_PENDING = 'pending';
    /** Applied on the spot, no review: personal -> business. */
    const STATUS_COMPLETED = 'completed';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    /** Cancelled by the customer before anyone decided it. */
    const STATUS_WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'user_id', 'from_type', 'to_type', 'status', 'details',
        'review_note', 'reviewed_by', 'reviewed_at'
    ];

    protected $casts = [
        'details' => 'array',
        'reviewed_at' => 'datetime'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(Admin::class, 'reviewed_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function isPending()
    {
        return $this->status === self::STATUS_PENDING;
    }
}
