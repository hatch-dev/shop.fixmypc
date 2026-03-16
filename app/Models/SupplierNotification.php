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
}
