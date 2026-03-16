<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UpdatedInventory extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id', 'quantity', 'price', 'sku', 'imei', 'barcode', 'is_active'
    ];

    public function inventory_attributes()
    {
        return $this->hasMany(InventoryAttribute::class, 'inventory_id', 'id')
            ->select( 'inventory_id', 'attribute_value_id');
    }


    // public function images()
    // {
    //     return $this->hasMany(ProductImage::class, 'inventory_id', 'id');
    // }

    public function images()
    {
        return $this->hasMany(InventoryImage::class,'inventory_id');
    }
}
