<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use App\Models\Helper\ClearanceWindow;
use App\Models\Helper\FlashSaleWindow;

class Cart extends Model
{
    use HasFactory;

    /**
     * QA audit CART-01.
     *
     * 'price', 'original_price', 'voucher_code' and 'voucher_discount' were
     * fillable and populated from $request->all(), so the client chose the
     * numbers that were then copied into ordered_products_inventories and used
     * for reporting. They are still writable columns, but only ever through the
     * explicit setters below — never from request input.
     */
    protected $fillable = [
        'product_id', 'inventory_id', 'user_id', 'quantity', 'selected',
        'shipping_place_id', 'shipping_type', 'user_token', 'admin_id', 'upgrades',
        /*
         * Which Essentials Bundle this line came in under -- a REFERENCE, not
         * an amount, and safe to mass-assign for that reason: it buys nothing
         * on its own. The discount is re-derived from it by
         * Helper\BundleDiscount wherever money is worked out, and is only
         * granted while the basket still holds the whole set.
         */
        'bundle_deal_id',
        /*
         * A Buy Now checkout of one item rather than a basket line (see
         * scopeForCheckout). Only ever set by CartsController::buyNow, never
         * from request input.
         */
        'buy_now'
    ];

    protected $casts = [
        'upgrades' => 'array',
        'buy_now' => 'boolean',
    ];

    /**
     * The lines a checkout is for, from a query already scoped to one owner.
     *
     * Since 2026-10-09 Buy Now is a checkout of one item that never
     * touches the basket. While the owner has a Buy Now line, that line is the
     * whole checkout; otherwise the checkout is the ticked basket lines. Every
     * step that prices, ships, authorises or places an order reads its lines
     * through here, so they cannot disagree about what is being bought.
     *
     * A Buy Now line is cleared when the shopper goes back to the basket page
     * (cart/buy-now/cancel), when the order is placed, and by BasketUpkeep
     * after 30 idle minutes -- so a stale one cannot take over a later basket
     * checkout.
     */
    public function scopeForCheckout($query)
    {
        $hasBuyNow = (clone $query)->where('carts.buy_now', 1)->exists();

        return $hasBuyNow
            ? $query->where('carts.buy_now', 1)
            : $query->where('carts.selected', Config::get('constants.status.PUBLIC'))->where('carts.buy_now', 0);
    }

    /** The basket proper: everything except a Buy Now line. */
    public function scopeInBasket($query)
    {
        return $query->where('carts.buy_now', 0);
    }

    public function shipping_place()
    {
        return $this->hasOne(ShippingPlace::class, 'id', 'shipping_place_id');
    }


    public function product_inner()
    {
        return $this->hasOne(Product::class, 'id', 'product_id')
            ->with('bundle_deal')
            ->with('tax_rules')
            ->leftJoin('flash_sales', function ($join) {

                $join->on('products.id', '=', 'flash_sale_products.product_id');

                $join->leftJoin('flash_sale_products', function($join) {
                    $join->on('flash_sales.id', '=', 'flash_sale_products.flash_sale_id');
                });
                FlashSaleWindow::liveProduct($join);
            })
            /*
             * The clearance running on this product, if any -- carried the
             * same way the flash sale above is, because Pricing decides a
             * basket line from this record and nothing else.
             */
            ->tap(function ($query) { ClearanceWindow::join($query); })
            ->select('products.id',  'products.bundle_deal_id',
                'products.title', 'products.selling', 'products.offered',
                'products.image',
                'products.shipping_rule_id',
                'products.sell_countries',
                'products.tax_rule_id',
                'products.admin_id',
                'products.purchased',
                // Checkout decides whether to charge VAT from this column; it
                // was missing from the select, so reading it off the relation
                // silently yielded null and no tax was applied to any line.
                'products.excludeVAT',
                'flash_sale_products.price',
                'flash_sale_products.discount_type as flash_discount_type',
                'flash_sale_products.discount_value as flash_discount_value',
                'flash_sales.end_time')
            ->addSelect(ClearanceWindow::columns())

            ->where('products.status', Config::get('constants.status.PUBLIC'));
    }


    public function flash_product()
    {
        return $this->hasOne(Product::class, 'id', 'product_id')
            ->with('bundle_deal')
            ->with('tax_rules')
            ->leftJoin('flash_sales', function ($join) {

                $join->on('products.id', '=', 'flash_sale_products.product_id');

                $join->leftJoin('flash_sale_products', function($join) {
                    $join->on('flash_sales.id', '=', 'flash_sale_products.flash_sale_id');
                });
                FlashSaleWindow::liveProduct($join);
            })
            /*
             * The clearance running on this product, if any -- carried the
             * same way the flash sale above is, because Pricing decides a
             * basket line from this record and nothing else.
             */
            ->tap(function ($query) { ClearanceWindow::join($query); })

            ->select('products.id', 'products.bundle_deal_id', 'products.title', 'products.slug',
                'products.selling', 'products.offered', 'products.tax_rule_id',
                'products.image', 'products.shipping_rule_id', 'products.sell_countries',
                'flash_sale_products.price',
                'flash_sale_products.discount_type as flash_discount_type',
                'flash_sale_products.discount_value as flash_discount_value',
                'flash_sales.end_time','products.excludeVAT')
            ->addSelect(ClearanceWindow::columns());
    }


    public function product()
    {
        return $this->hasOne(Product::class, 'id', 'product_id')
            ->select(['id', 'title', 'image', 'selling', 'offered', 'shipping_rule_id', 'sell_countries', 'status']);
    }

    public function updated_inventory()
    {
        return $this->hasOne(UpdatedInventory::class, 'id', 'inventory_id');
    }

    public function product_images()
    {
        return $this->hasMany(ProductImageAttribute::class, 'product_id', 'product_id');
    }
	
	


    /*public function inventory()
    {
        return $this->hasOne(Inventory::class, 'id', 'inventory_id');
    }*/
}
