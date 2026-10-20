<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A return case against a delivered order (phase 1: opened by staff).
 *
 * The order itself is never edited by a return. What came back, and what was
 * paid back for it, is recorded here and on the items; see
 * App\Models\Helper\OrderReturns for the moves and ReturnRefundCalculator for
 * the money.
 */
class OrderReturn extends Model
{
    // Asked for by the customer; staff approve (-> awaiting_return) or reject it.
    const REQUESTED = 'requested';
    const AWAITING = 'awaiting_return';
    const RECEIVED = 'received';
    // Held only while the gateway call is in flight, so a double click cannot refund twice.
    const REFUNDING = 'refunding';
    const REFUNDED = 'refunded';
    // Finished with no money moved (refund method "none").
    const CLOSED = 'closed';
    const REJECTED = 'rejected';
    // An exchange that was completed: the replacement order exists.
    const EXCHANGED = 'exchanged';
    // A dearer exchange whose difference was sent to the customer as a pay link.
    const AWAITING_PAYMENT = 'awaiting_payment';
    // Withdrawn by the customer before anything was sent back.
    const CANCELLED = 'cancelled';

    const TYPE_RETURN = 'return';
    const TYPE_EXCHANGE = 'exchange';

    /** Statuses whose quantities are spoken for: everything but a rejection. */
    const LIVE = [self::REQUESTED, self::AWAITING, self::RECEIVED, self::REFUNDING, self::REFUNDED, self::CLOSED,
        self::EXCHANGED, self::AWAITING_PAYMENT];

    /** Finished: every unit on the case has been dealt with. */
    const DONE = [self::REFUNDED, self::CLOSED, self::EXCHANGED];

    /**
     * Finished with the goods given up. Only these make an order fully
     * RETURNED -- an exchanged unit was swapped, and the sale still stands.
     */
    const GIVEN_BACK = [self::REFUNDED, self::CLOSED];

    /** Statuses whose refund_amount is money that went back to the customer. */
    const MONEY_BACK = [self::REFUNDED, self::EXCHANGED];

    const REASONS = [
        'changed_mind' => 'Changed their mind',
        'faulty' => 'Faulty',
        'damaged' => 'Arrived damaged',
        'wrong_item' => 'Wrong item sent',
        'not_as_described' => 'Not as described',
        'other' => 'Other',
    ];

    const STATUS_LABELS = [
        self::REQUESTED => 'Requested',
        self::AWAITING_PAYMENT => 'Awaiting payment',
        self::CANCELLED => 'Cancelled',
        self::AWAITING => 'Awaiting return',
        self::RECEIVED => 'Received',
        self::REFUNDING => 'Refunding',
        self::REFUNDED => 'Refunded',
        self::CLOSED => 'Closed',
        self::REJECTED => 'Rejected',
        self::EXCHANGED => 'Exchanged',
    ];

    protected $fillable = [
        'rma_number', 'type', 'order_id', 'user_id', 'status', 'reason', 'lang', 'customer_note', 'staff_note',
        'created_by',
    ];

    protected $casts = [
        'refund_amount' => 'float',
        'shipping_refund' => 'float',
        'exchange_difference' => 'float',
        'amount_charged' => 'float',
        'gift_card_amount' => 'float',
        'points_returned' => 'integer',
        'points_taken' => 'integer',
        'received_at' => 'datetime',
        'refunded_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(OrderReturnItem::class, 'order_return_id');
    }

    public function history()
    {
        return $this->hasMany(OrderReturnHistory::class, 'order_return_id')->orderBy('id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function isExchange()
    {
        return $this->type === self::TYPE_EXCHANGE;
    }

    public function replacementOrder()
    {
        return $this->belongsTo(Order::class, 'replacement_order_id');
    }

    public function statusLabel()
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function reasonLabel()
    {
        return self::REASONS[$this->reason] ?? $this->reason;
    }

    /**
     * RMA + 7 random digits, the same shape as an order number (ORD + 7).
     * Random rather than sequential so a customer cannot guess another's.
     */
    public static function newRmaNumber()
    {
        do {
            $number = 'RMA' . random_int(1000000, 9999999);
        } while (static::where('rma_number', $number)->exists());

        return $number;
    }
}
