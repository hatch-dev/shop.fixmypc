<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

/**
 * One assignment: "Laptop Accessories" shows under THIS laptop.
 *
 * The row carries where a promotion shows, in what order among the product's
 * other promotions, and whether this particular placement is switched on. It
 * never carries what the promotion offers -- that is the promotion's own
 * business, and it is what makes editing "Laptop Accessories" once reach all
 * thirty laptops.
 *
 * `status` here is the ASSIGNMENT's switch, which is not the promotion's. An
 * admin can stop the accessories showing under one laptop without touching
 * the other twenty-nine; a promotion switched off at its own level is hidden
 * everywhere regardless of what this row says.
 */
class ProductCrossSell extends Model
{
    use HasFactory;

    protected $table = 'product_cross_sells';

    protected $casts = [
        'product_id' => 'integer',
        'cross_sell_id' => 'integer',
        'sort_order' => 'integer',
        'status' => 'integer',
    ];

    protected $fillable = [
        'product_id',
        'cross_sell_id',
        'sort_order',
        'status',
    ];

    /** The primary product -- the one being looked at or added. */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** The promotion shown under it. */
    public function crossSell()
    {
        return $this->belongsTo(CrossSell::class, 'cross_sell_id');
    }

    /** Assignments the admin has switched on. */
    public function scopeActive($query)
    {
        return $query->where('product_cross_sells.status', Config::get('constants.status.PUBLIC'));
    }

    /** The order the admin arranged, made deterministic. */
    public function scopeOrdered($query)
    {
        return $query->orderBy('product_cross_sells.sort_order', 'asc')
            ->orderBy('product_cross_sells.id', 'asc');
    }
}
