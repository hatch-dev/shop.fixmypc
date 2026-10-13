<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductSpecification extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'group_id',
        'label',
        'value',
        'sort_order'
    ];

    public function product()
    {
        return $this->belongsTo(
            Product::class,
            'product_id'
        );
    }

    public function group()
    {
        return $this->belongsTo(
            SpecificationGroup::class,
            'group_id'
        );
    }
}
