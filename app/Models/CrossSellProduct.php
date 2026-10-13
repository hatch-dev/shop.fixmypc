<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One product inside a cross-sell promotion: "Laptop Accessories" offers the
 * wireless mouse, third in the list.
 *
 * It is a membership, not a price and not an eligibility. Whether the mouse
 * can actually be shown -- published, in stock, not already in the basket,
 * not the product being looked at -- is decided at read time by
 * CrossSellService, because every one of those moves underneath a saved row.
 */
class CrossSellProduct extends Model
{
    use HasFactory;

    protected $table = 'cross_sell_products';

    protected $casts = [
        'cross_sell_id' => 'integer',
        'product_id' => 'integer',
        'sort_order' => 'integer',
    ];

    protected $fillable = [
        'cross_sell_id',
        'product_id',
        'sort_order',
    ];

    public function crossSell()
    {
        return $this->belongsTo(CrossSell::class, 'cross_sell_id');
    }

    /** The product being offered. */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * The order the admin arranged, made deterministic.
     *
     * sort_order is not unique, so a tie breaks on id or two products can
     * swap places between requests -- which, once the display cap bites,
     * means one of them is intermittently never shown.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('cross_sell_products.sort_order', 'asc')
            ->orderBy('cross_sell_products.id', 'asc');
    }
}
