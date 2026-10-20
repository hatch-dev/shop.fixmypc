<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderReturnItem extends Model
{
    protected $fillable = [
        'order_return_id', 'ordered_product_id', 'product_id', 'inventory_id', 'exchange_inventory_id', 'quantity',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'restock' => 'boolean',
        'restocked_qty' => 'integer',
        'line_refund' => 'float',
        'gift_card_amount' => 'float',
        'exchange_unit_price' => 'float',
    ];

    public function orderReturn()
    {
        return $this->belongsTo(OrderReturn::class, 'order_return_id');
    }

    /** The variant this unit is swapped for, on an exchange. */
    public function exchange_inventory()
    {
        return $this->belongsTo(UpdatedInventory::class, 'exchange_inventory_id');
    }

    public function ordered_product()
    {
        return $this->belongsTo(OrderedProduct::class, 'ordered_product_id');
    }
}
