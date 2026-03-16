<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

class Upsell extends Model
{
    use HasFactory;

    protected $fillable = [
        'title', 'status', 'created_at', 'updated_at', 'admin_id'
    ];

    protected $hidden = [
        'admin_id'
    ];


    public function public_products()
    {
        return $this->hasMany(Upsell::class, 'upsell_id', 'id')
            ->join('products as p', function($join) {
                $join->on('p.id', '=', 'upsell_products.product_id');
                $join->where('p.status', Config::get('constants.status.PUBLIC'));
            })
            ->groupBy('p.id')
            ->select('upsell_products.*', 'p.id', 'p.slug', 'p.title',  'p.badge', 'p.selling', 'p.offered',
                'p.image')
            ->offset(0)
            ->limit(Config::get('constants.pagination.FRONTEND_SEARCH'));
    }


    public function products()
    {
        return $this->hasMany(UpsellProduct::class, 'upsell_id', 'id');
    }

    public function categories()
    {
        return $this->belongsToMany(
            Category::class,
            'upsell_categories',
            'upsell_id',
            'category_id'
        );
    }

    public function subcategories()
    {
        return $this->belongsToMany(
            Category::class,
            'upsell_subcategories',
            'upsell_id',
            'subcategory_id'
        );
    }
    
}
