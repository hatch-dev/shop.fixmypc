<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use App\Models\Helper\ClearanceWindow;
use App\Models\Helper\FlashSaleWindow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Models\UserWishlist;

class Product extends Model
{
    use HasFactory;


    protected $casts = [
        'purchased' => 'float',
        'offered' => 'float',
        'selling' => 'float',
        'review_count' => 'integer',
        'rating' => 'integer',
		'upsell_id' => 'integer',
    ];



    protected $fillable = [
        'id', 'title', 'short_heading', 'purchased', 'selling', 'wholesale_price', 'wholesale_discount_type', 'wholesale_discount_value', 'vatInclusivePrice', 'discount_type', 'discount_value', 'offered','vatInclusiveOfferedPrice', 'image', 'unit', 'video', 'video_thumb', 'badge',
        'status', 'admin_id', 'subcategory_id', 'category_id', 'brand_id', 'warranty', 'refundable',
        'description', 'overview', 'tags', 'tax_rule_id', 'shipping_rule_id', 'sell_countries', 'meta_title', 'meta_description',
        'meta_keywords',
        'review_count', 'rating', 'bundle_deal_id', 'slug', 'excludeVAT' , 'upsell_id', 'procurement',
        /*
         * back_order is vestigial. Backorder is decided per VARIANT by
         * updated_inventories.is_active -- that is what the basket, checkout,
         * the upgrade engine and the cross-sell blocks all read. This column
         * is written by nothing, read by nothing and has no input anywhere in
         * the admin. Left in place rather than dropped so an old row keeps its
         * value; do not wire it up expecting it to mean anything.
         */
        'back_order',
        // Shipping dimensions and the purchase note. The admin form has shown
        // these inputs all along, bound to local state that was never sent.
        'length', 'width', 'height', 'weight', 'purchase_note'
    ];

    protected $hidden = [];

    protected $appends = ['is_wishlisted', 'collection_background_image', 'is_new'];

    public function flash_sale_product()
    {
        return $this->hasMany(FlashSaleProduct::class, 'product_id', 'id')
            ->with('flash_sale');
    }


    public function tax_rules()
    {
        return $this->hasOne(TaxRules::class, 'id', 'tax_rule_id');
    }


    /**
     * A published product with its bundle, tax rule and any running flash sale
     * price attached — the same shape Cart::product_inner() produces.
     *
     * Pricing needs this shape outside a cart context too (add-to-cart has no
     * saved cart row yet), and duplicating the join is how the price ladders
     * drifted apart in the first place (QA audit PRC-01).
     */
    public static function pricingRecord($productId)
    {
        return self::pricingQuery()
            ->where('products.id', $productId)
            ->first();
    }


    /**
     * The same record, for many products at once, keyed by product id.
     *
     * Anything that prices a LIST of products needs this. Calling
     * pricingRecord() once per product costs three queries each — the row plus
     * its two eager loads — and a recommendation block is exactly where that
     * bites, because it is the one place that prices a set of products the
     * page never asked for. Eight recommendations were twenty-four queries.
     *
     * It shares its query with pricingRecord() rather than restating it. A
     * record that differs by a single column between the two is a block that
     * quotes one price and a basket that charges another, which is the whole
     * defect this shape exists to prevent.
     */
    public static function pricingRecords($productIds)
    {
        $ids = collect($productIds)->filter()->map(function ($id) { return (int)$id; })->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return self::pricingQuery()
            ->whereIn('products.id', $ids)
            ->get()
            /*
             * A product in more than one live flash sale joins to more than
             * one row. pricingRecord() takes whichever comes back first;
             * unique() keeps the first too, so a product priced on its own and
             * the same product priced in a batch cannot disagree.
             */
            ->unique('id')
            ->keyBy('id');
    }


    /** The join and the column list both of the above are built from. */
    private static function pricingQuery()
    {
        return self::with('bundle_deal')
            ->with('tax_rules')
            ->leftJoin('flash_sales', function ($join) {

                $join->on('products.id', '=', 'flash_sale_products.product_id');

                $join->leftJoin('flash_sale_products', function ($join) {
                    $join->on('flash_sales.id', '=', 'flash_sale_products.flash_sale_id');
                });
                FlashSaleWindow::liveProduct($join);
            })
            /*
             * The clearance running on this product, if any. Pricing reads
             * it exactly as it reads the flash-sale columns above, so a
             * deal the storefront advertises is a deal the till charges.
             */
            ->tap(function ($query) { ClearanceWindow::join($query); })
            ->select('products.id', 'products.bundle_deal_id',
                'products.title', 'products.selling', 'products.offered',
                'products.image', 'products.shipping_rule_id',
                'products.tax_rule_id', 'products.admin_id', 'products.purchased',
                'products.excludeVAT', 'products.status',
                'flash_sale_products.price',
                'flash_sale_products.discount_type as flash_discount_type',
                'flash_sale_products.discount_value as flash_discount_value', 'flash_sales.end_time')
            ->addSelect(ClearanceWindow::columns());
    }


    public function category()
    {
        return $this->hasOne(Category::class, 'id', 'category_id')->select(['id', 'title', 'slug']);
    }


    public function product_categories()
    {
        return $this->hasMany(ProductCategory::class, 'product_id', 'id')
            ->orderBy('primary', 'DESC');
    }



    public function shipping_rule()
    {
        return $this->hasOne(ShippingRule::class, 'id', 'shipping_rule_id')
            ->select(['id', 'title', 'single_price']);
    }


    public function product_collections()
    {
        return $this->hasMany(CollectionWithProduct::class, 'product_id', 'id')
            ->select(['id', 'product_id', 'product_collection_id']);
    }


    public function product_inventories()
    {
        return $this->hasMany(UpdatedInventory::class, 'product_id', 'id');
    }


    public function product_images()
    {
        return $this->hasMany(ProductImage::class, 'product_id', 'id');
    }


    public function product_image_names()
    {
        return $this->hasMany(ProductImage::class, 'product_id', 'id');
    }
	
	public function customizations()
    {
        return $this->hasMany(ProductTemplateCustomization::class, 'product_id', 'id');
    }

    public function store()
    {
        return $this->hasOne(Store::class, 'admin_id', 'admin_id');
    }


    public function bundle_deal()
    {
        return $this->hasOne(BundleDeal::class, 'id', 'bundle_deal_id')
            ->select(['id', 'buy', 'free', 'title']);
    }


    public function brand()
    {
        return $this->hasOne(Brand::class, 'id', 'brand_id')
            ->select(['title', 'id']);
    }


    public function admin()
    {
        return $this->hasOne(Admin::class, 'id', 'admin_id');
    }
	
	public function categories()
    {
        return $this->belongsToMany(Category::class, 'product_categories');
    }
	
	public function upsells()
	{
		return $this->belongsToMany(Upsell::class)
			->withPivot('discount');
	}
	
	public function upsell_products()
	{
		return $this->hasMany(UpsellProduct::class, 'upsell_id', 'upsell_id');
	}

    /**
     * The cross-sell PROMOTIONS assigned to this product -- "Laptop
     * Accessories" under a laptop. The pivot carries the assignment's own
     * order and switch.
     *
     * Spelled out rather than called `relatedProducts` or `recommendations`:
     * upsell campaigns, bundle deals and cross-sell all point products at
     * other products in this catalogue, and a reader who finds `->related`
     * cannot tell which of the three they have.
     *
     * Unfiltered on purpose. The admin screen needs every assignment, the
     * switched-off ones included; the storefront asks CrossSellService, which
     * applies the live filters itself.
     */
    public function crossSells()
    {
        return $this->belongsToMany(CrossSell::class, 'product_cross_sells', 'product_id', 'cross_sell_id')
            ->withPivot(['id', 'sort_order', 'status'])
            ->withTimestamps();
    }

    /** The assignment rows themselves, for when the pivot is what is edited. */
    public function crossSellAssignments()
    {
        return $this->hasMany(ProductCrossSell::class, 'product_id', 'id');
    }

    public function ratingReviews()
    {
        return $this->hasMany(RatingReview::class, 'product_id');
    }

    public function collection_with_products()
    {
        return $this->hasMany(
            \App\Models\CollectionWithProduct::class,
            'product_id'
        );
    }

    public function vouchers()
    {
        return $this->belongsToMany(
            Voucher::class,
            'voucher_products'
        );
    }

    protected static function booted()
    {
        static::addGlobalScope('withReviews', function ($query) {
            $query->withCount(['ratingReviews as review_count'])
                ->withAvg('ratingReviews as rating', 'rating');
        });
    }

    public function getRatingAttribute($value)
    {
        return $value ? round($value, 1) : 0;
    }

    public function specifications()
    {
        return $this->hasMany(ProductSpecification::class);
    }

    /**
     * The translated titles and copy for this product.
     *
     * Most queries reach product_langs through a leftJoin aliased `pl`. That
     * is fine where the join is present, but a search filtering on `pl.title`
     * in a query that never joined it fails the whole statement -- which is
     * exactly what broke the bulk editor's search box. A relation cannot drift
     * out of step with a join that is not there.
     */
    public function langs()
    {
        return $this->hasMany(ProductLang::class, 'product_id', 'id');
    }

    /** The subcategory rows chosen for this product, under their parents. */
    public function subcategories()
    {
        return $this->hasMany(ProductSubcategory::class, 'product_id', 'id');
    }

    public function getIsWishlistedAttribute()
    {
        $userId = Auth::user()->id ?? null;

        $query = UserWishlist::where('product_id', $this->id);

        if ($userId) {

            $query->where('user_id', $userId);

        } else {

            // A guest owns wishlist rows through the token the server issued
            // it, so the heart still reads as filled before signing in.
            $userToken = request()->input('user_token') ?: request()->query('user_token');

            if (!$userToken) {
                return false;
            }

            $query->whereNull('user_id')->where('user_token', $userToken);
        }

        return $query->exists();
    }

    public function feature_images()
    {
        return $this->hasMany(
            ProductFeatureImage::class
        )->orderBy('sort_order');
    }

    public function getCollectionBackgroundImageAttribute()
    {
        $collection = CollectionWithProduct::query()
            ->with('collection')
            ->where('product_id', $this->id)
            ->whereHas('collection', function ($query) {

                $query->whereNotNull('image')
                    ->where('image', '!=', '');
            })
            ->latest()
            ->first();

        return $collection?->collection?->image;
    }

    public function getIsNewAttribute()
    {
        $createdAt = $this->created_at;

        if (!$createdAt) {
            $createdAt = self::query()->where(
                'id',
                $this->id
            )->value('created_at');
        }

        if (empty($createdAt)) {
            return false;
        }

        return \Carbon\Carbon::parse($createdAt)
            ->greaterThanOrEqualTo(
                \Carbon\Carbon::now()->subDays(7)
            );
    }
	
}
