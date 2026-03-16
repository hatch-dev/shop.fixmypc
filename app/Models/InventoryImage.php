<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'inventory_id',
        'product_image_id'
    ];

    public function inventory()
    {
        return $this->belongsTo(UpdatedInventory::class);
    }

    public function image()
    {
        return $this->belongsTo(ProductImage::class, 'product_image_id');
    }
}
