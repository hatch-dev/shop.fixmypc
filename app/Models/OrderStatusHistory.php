<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Audit trail for order status changes.
 *
 * QA audit: orders.status was a single column that got overwritten, so there
 * was no record of who moved an order to DELIVERED or when — nothing to check
 * after a customer dispute.
 */
class OrderStatusHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id', 'from_status', 'to_status', 'changed_by', 'changed_by_type', 'note'
    ];

    protected $casts = [
        'from_status' => 'integer',
        'to_status' => 'integer',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }


    /**
     * The admin who made the change.
     *
     * `changed_by` is only an admins.id while `changed_by_type` says 'admin';
     * on any other type it belongs to a different table, so callers must check
     * the type before trusting this. It is not constrained here because
     * changed_by_type is a column on this row, not on `admins` — putting it in
     * the relation would send it to the wrong table.
     */
    public function admin()
    {
        return $this->belongsTo(Admin::class, 'changed_by', 'id');
    }


    /** True when `changed_by` really is an admin id. */
    public function byAdmin()
    {
        return $this->changed_by_type === 'admin' && $this->changed_by;
    }
}
