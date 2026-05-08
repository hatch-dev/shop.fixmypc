<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SaveForLater extends Model
{
    use HasFactory;

    protected $table = 'save_for_later';

    protected $fillable = [
        'user_id',
        'product_id',
        'inventory_id',
        'quantity',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function inventory()
    {
        return $this->belongsTo(ProductInventory::class, 'inventory_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
