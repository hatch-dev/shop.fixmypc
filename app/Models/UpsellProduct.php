<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UpsellProduct extends Model
{
    use HasFactory;
	
	protected $casts = [
    'upsell_id' => 'integer',
    'product_id' => 'integer',
    'price' => 'float',
	];

    protected $fillable = [
        'product_id', 'upsell_id', 'price', 'admin_id'
    ];

    protected $hidden = [
        'admin_id'
    ];

    public function public_upsell()
    {
        return $this->hasOne(Upsell::class, 'id', 'upsell_id');
    }


    public function upsell()
    {
        return $this->hasOne(Upsell::class, 'id', 'upsell_id');
    }


   public function product()
	{
		return $this->belongsTo(Product::class, 'product_id');
	}
		
}
