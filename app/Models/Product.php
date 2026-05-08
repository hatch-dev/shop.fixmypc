<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

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
        'description', 'overview', 'tags', 'tax_rule_id', 'shipping_rule_id', 'meta_title', 'meta_description',
        'meta_keywords',
        'review_count', 'rating', 'bundle_deal_id', 'slug', 'excludeVAT' , 'upsell_id', 'updated_upsell_id', 'procurement', 'back_order'
    ];

    protected $hidden = [];


    public function flash_sale_product()
    {
        return $this->hasMany(FlashSaleProduct::class, 'product_id', 'id')
            ->with('flash_sale');
    }


    public function tax_rules()
    {
        return $this->hasOne(TaxRules::class, 'id', 'tax_rule_id');
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

        // static::addGlobalScope('withWholesaleColumns', function ($query) {
        //     $query->addSelect([
        //         'products.wholesale_price',
        //         'products.wholesale_discount_type',
        //         'products.wholesale_discount_value'
        //     ]);
        // });
    }

    public function getRatingAttribute($value)
    {
        return $value ? round($value, 1) : 0;
    }

    // public function getSellingAttribute($value)
    // {
    //     $user = Auth::user();

    //     if ($user && $user->account_type === 'business' && $this->wholesale_price > 0) {
    //         return (float) $this->wholesale_price;
    //     }

    //     return (float) $value;
    // }

    // public function getOfferedAttribute($value)
    // {
    //     $user = Auth::user();

    //     if ($user && $user->account_type === 'business' && $this->wholesale_price > 0) {

    //         $price = (float) $this->wholesale_price;
    //         $discountValue = (float) $this->wholesale_discount_value;
    //         $discountType = $this->wholesale_discount_type;

    //         if ($discountType === 'percentage') {
    //             return (float) $price - ($price * $discountValue / 100);
    //         }

    //         if ($discountType === 'fixed') {
    //             return (float) $price - $discountValue;
    //         }

    //         return (float) $price;
    //     }

    //     return (float) $value;
    // }

	
}
