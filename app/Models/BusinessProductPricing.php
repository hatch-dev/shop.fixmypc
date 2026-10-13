<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BusinessProductPricing extends Model
{
    use HasFactory;

    protected $table = 'business_product_pricing';

    protected $fillable = [
        'product_id',
        'min_qty',
        'max_qty',
        'wholesale_price',
        'discount_type',
        'discount_value',
        'final_price',
    ];

    protected $casts = [
        'min_qty' => 'integer',
        'max_qty' => 'integer',
        'wholesale_price' => 'float',
        'discount_value' => 'float',
        'final_price' => 'float',
    ];

    /**
     * The price a band charges, decided from its own inputs.
     *
     * final_price is a stored convenience column written by the admin screen.
     * It is never the authority: deriving it here means a hand-edited or
     * stale row cannot undercharge, and it keeps the admin screen, this model
     * and the storefront agreeing on one formula instead of three.
     */
    public function computedFinalPrice()
    {
        $base = (float)$this->wholesale_price;
        $discount = (float)$this->discount_value;

        if ($this->discount_type === 'percentage') {
            $base -= ($base * $discount) / 100;
        } else {
            $base -= $discount;
        }

        return Helper\Pricing::round(max(0, $base));
    }

    /**
     * Does this band cover the quantity being bought?
     *
     * A max_qty of 0 (or below min) is read as open-ended, which is how the
     * admin screen expresses a final "20 and over" band.
     */
    public function coversQuantity($quantity)
    {
        $quantity = (int)$quantity;

        if ($quantity < (int)$this->min_qty) {
            return false;
        }

        $max = (int)$this->max_qty;

        return $max <= 0 || $quantity <= $max;
    }

    /**
     * Is this caller entitled to band pricing?
     *
     * Anything that is not a customer account gets nothing. The check used to
     * be a bare `$user->hasBusinessPricing()`, which is a fatal error rather
     * than a refusal when the authenticated model is an Admin or a Supplier —
     * and these methods are reached from the storefront's listing and product
     * endpoints, which resolve whoever happens to be signed in. Failing closed
     * is both the safe answer and the correct one.
     */
    private static function isEntitled($user)
    {
        return !is_null($user)
            && method_exists($user, 'hasBusinessPricing')
            && $user->hasBusinessPricing();
    }


    /**
     * The bands for a product, or an empty set when the customer is not
     * entitled to them. Callers pass the result to Pricing, which stays free of
     * database access.
     *
     * $user is nullable because guests and admin-placed orders both reach the
     * pricing path; neither gets business rates.
     */
    public static function forCustomer($user, $productId)
    {
        if (!self::isEntitled($user)) {
            return collect();
        }

        return static::where('product_id', $productId)->get();
    }

    /**
     * Bands for a whole cart, grouped by product id.
     *
     * Checkout prices every line twice — once to reach a subtotal, once to
     * charge — so loading per line would mean two queries per product inside
     * the order transaction. One query, then both loops read the same map.
     */
    public static function tierMap($user, $productIds)
    {
        if (!self::isEntitled($user)) {
            return collect();
        }

        $productIds = collect($productIds)->filter()->unique()->values();

        if ($productIds->isEmpty()) {
            return collect();
        }

        return static::whereIn('product_id', $productIds)->get()->groupBy('product_id');
    }
}
