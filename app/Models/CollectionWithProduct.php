<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use App\Models\Helper\FlashSaleWindow;

class CollectionWithProduct extends Model
{

    use HasFactory;

    protected $fillable = [
        'product_collection_id', 'product_id'
    ];

    public function product(){
        return $this->hasOne(Product::class, 'id', 'product_id')
            ->where('products.status', Config::get('constants.status.PUBLIC'))
            ->leftJoin('flash_sales', function ($join) {

                $join->on('products.id', '=', 'flash_sale_products.product_id');

                $join->leftJoin('flash_sale_products', function($join) {
                    $join->on('flash_sales.id', '=', 'flash_sale_products.flash_sale_id');
                });
                FlashSaleWindow::liveProduct($join);
            })
            ->select('products.id', 'products.title', 'products.badge',
                'products.selling', 'products.offered', 'products.slug',
                'products.image', 'products.shipping_rule_id',
                'flash_sale_products.price',
                'flash_sale_products.discount_type as flash_discount_type',
                'flash_sale_products.discount_value as flash_discount_value',
                'flash_sales.end_time');
    }

    public function product_collection()
    {
        return $this->belongsTo(
            \App\Models\ProductCollection::class,
            'product_collection_id'
        );
    }

    public function collection(){
        return $this->hasOne(ProductCollection::class, 'id', 'product_collection_id')
            ->select(['id', 'title', 'image']);
    }


}
