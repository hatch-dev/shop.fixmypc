<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupplierNotification extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'product_id',
        'inventory_id',
        'order_id'
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function inventory()
    {
        return $this->belongsTo(UpdatedInventory::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * The supplier's answer, if they have given one. One per notified line --
     * see the unique index on supplier_bids.
     */
    public function bid()
    {
        return $this->hasOne(SupplierBid::class, 'supplier_notification_id');
    }
}
