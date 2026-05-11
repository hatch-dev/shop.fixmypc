<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderedProductsInventory extends Model
{
    use HasFactory;

    protected $table = 'ordered_products_inventories';

    protected $fillable = [
        'product_id',
        'inventory_id',
        'order_id',
        'quantity',
        'actual_price',
        'price',
        'shipping_price',
        'product_voucher_discount_applied',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function inventory()
    {
        return $this->belongsTo(UpdatedInventory::class, 'inventory_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
