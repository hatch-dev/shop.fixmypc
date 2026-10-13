<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One order email, from the moment it is claimed to the moment it lands.
 *
 * Reads as a record; behaves as a lock. The row is created *before* anything
 * is sent, by an insert that the unique index on (order_id, event) may refuse
 * -- that refusal is how a duplicate trigger is dropped. See
 * OrderEmailDispatcher::claim().
 */
class OrderEmailLog extends Model
{
    protected $table = 'order_email_log';

    const STATUS_QUEUED = 'queued';
    const STATUS_SENT = 'sent';
    const STATUS_FAILED = 'failed';
    const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'order_id',
        'event',
        'status',
        'recipient',
        'attempts',
        'last_error',
        'sent_at',
        'failed_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'sent_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    /**
     * The recipient is a customer's address. It belongs in this table, where
     * it is needed to replay a send, and nowhere near a log file.
     */
    protected $hidden = ['recipient'];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function isSent()
    {
        return $this->status === self::STATUS_SENT;
    }

    /**
     * Rows a retry sweep should pick up: claimed, handed to the queue, and
     * still sitting there some time later. A row that is 'failed' is
     * deliberately excluded -- it was classified as permanent, and the point
     * of that classification is that nothing retries it on its own.
     */
    public function scopeStuck($query, $minutes = 30)
    {
        return $query->where('status', self::STATUS_QUEUED)
            ->where('updated_at', '<', now()->subMinutes($minutes));
    }
}
