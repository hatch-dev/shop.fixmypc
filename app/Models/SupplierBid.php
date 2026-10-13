<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupplierBid extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_notification_id',
        'supplier_id',
        'order_id',
        'product_id',
        'inventory_id',
        'price',
        'grade',
        'status',
        'decided_at',
        'decided_by',
        'decline_reason',
        'cost_before',
        'paid_at',
        'paid_by',
        'paid_amount',
        'payment_method',
        'payment_reference',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'cost_before' => 'decimal:2',
        'decided_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    /** Nobody has chosen yet. */
    const PENDING = 'pending';

    /** This is the supplier who will source the line. */
    const ACCEPTED = 'accepted';

    /** Another quote was taken, or this one was turned down. */
    const REJECTED = 'rejected';

    const STATUSES = [self::PENDING, self::ACCEPTED, self::REJECTED];

    /**
     * How a supplier is paid.
     *
     * Record-keeping, not a payment integration: the gateways this shop has
     * configured take money from customers. Paying a supplier happens in a
     * bank, and what belongs here is the note of it.
     */
    const PAYMENT_METHODS = ['Bank transfer', 'Card', 'Cash', 'Credit note', 'Other'];

    public function isAccepted()
    {
        return $this->status === self::ACCEPTED;
    }

    public function isPaid()
    {
        return $this->paid_at !== null;
    }

    /**
     * What this quote comes to for the whole line.
     *
     * price is per unit, the way the supplier was asked for it, so the
     * payable is only meaningful alongside the quantity ordered.
     */
    public function payableFor($quantity)
    {
        return round((float)$this->price * max(1, (int)$quantity), 2);
    }

    /**
     * The conditions a supplier may answer with.
     *
     * Kept here rather than in the Vue page so the server validates against
     * the same list the dropdown offers -- the page used to be the only thing
     * that decided what a grade was, and it decided it client-side.
     */
    const GRADES = [
        'Brand New',
        'Like New',
        'Grade A',
        'Grade B',
        'Grade C',
    ];

    public function notification()
    {
        return $this->belongsTo(SupplierNotification::class, 'supplier_notification_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function inventory()
    {
        return $this->belongsTo(UpdatedInventory::class);
    }
}
