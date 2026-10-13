<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use App\Models\Helper\FlashSaleWindow;

class ProductCollection extends Model
{
    use HasFactory;

    protected $fillable = [
        'title', 'status', 'admin_id', 'slug', 'in_frontend', 'image', 'sort_order'
    ];

    protected $hidden = [
        'admin_id'
    ];

    public function product_collections()
    {
        return $this->hasMany(CollectionWithProduct::class, 'product_collection_id', 'id')
            ->join('products', function ($join) {

                $join->on('products.id', '=', 'collection_with_products.product_id');

                $join->where('products.status', Config::get('constants.status.PUBLIC'));
                $join->leftJoin('flash_sales', function ($join) {

                    $join->on('products.id', '=', 'flash_sale_products.product_id');

                    $join->leftJoin('flash_sale_products', function ($join) {
                        $join->on('flash_sales.id', '=', 'flash_sale_products.flash_sale_id');
                    });
                    FlashSaleWindow::liveProduct($join);
                });

            })
            ->select('collection_with_products.*', 'products.id', 'products.title', 'products.badge',
                'products.selling', 'products.offered', 'products.slug',
                'products.image', 'products.shipping_rule_id',
                'flash_sale_products.price',
                'flash_sale_products.discount_type as flash_discount_type',
                'flash_sale_products.discount_value as flash_discount_value',
                'flash_sales.end_time');
    }


}
