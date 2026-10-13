<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A cross-sell promotion: a named, reusable set of complementary products.
 *
 * "Laptop Accessories" holds the bag, the mouse, the cable and the stand, and
 * is then ASSIGNED to every laptop it goes with. The promotion knows what it
 * offers; the assignment (ProductCrossSell) knows where it shows.
 *
 * It carries no price. See the table's migration for why a figure banked
 * against a recommendation goes stale, and CrossSellService for where the
 * number actually comes from.
 */
class CrossSell extends Model
{
    use HasFactory;

    protected $table = 'cross_sells';

    /*
     * 1 and 2 match constants.status.PUBLIC / .PRIVATE, as every other
     * promotion here reads them. 3 is what a promotion is until somebody says
     * it is ready -- and only 1 ever reaches a shopper.
     */
    const STATUS_ACTIVE = 1;
    const STATUS_INACTIVE = 2;
    const STATUS_DRAFT = 3;

    const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_DRAFT];

    /* The display cap, clamped. Past about a dozen nobody reads a list. */
    const MIN_PRODUCTS_SHOWN = 1;
    const MAX_PRODUCTS_SHOWN = 12;

    /* How many products one promotion may hold. */
    const MAX_PRODUCTS = 50;

    protected $casts = [
        'status' => 'integer',
        'max_products' => 'integer',
        'created_by' => 'integer',
    ];

    protected $fillable = [
        'name',
        'description',
        'status',
        'max_products',
        'created_by',
    ];

    /** What the promotion offers, in the admin's order. */
    public function products()
    {
        return $this->hasMany(CrossSellProduct::class, 'cross_sell_id', 'id')
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc');
    }

    /** The PRIMARY products it has been assigned to. */
    public function assignedProducts()
    {
        return $this->belongsToMany(Product::class, 'product_cross_sells', 'cross_sell_id', 'product_id')
            ->withPivot(['id', 'sort_order', 'status'])
            ->withTimestamps();
    }

    /** The assignment rows themselves. */
    public function assignments()
    {
        return $this->hasMany(ProductCrossSell::class, 'cross_sell_id', 'id');
    }

    /** Promotions a shopper may be shown. Draft and inactive never are. */
    public function scopeActive($query)
    {
        return $query->where('cross_sells.status', self::STATUS_ACTIVE);
    }

    public function isActive()
    {
        return (int)$this->status === self::STATUS_ACTIVE;
    }

    /**
     * The display cap as the storefront should read it.
     *
     * Clamped on read as well as on write, so a value put straight into the
     * database by hand cannot render a hundred tiles.
     */
    public function displayLimit()
    {
        $limit = (int)$this->max_products;

        if ($limit < self::MIN_PRODUCTS_SHOWN) {
            return 4;
        }

        return min($limit, self::MAX_PRODUCTS_SHOWN);
    }
}
