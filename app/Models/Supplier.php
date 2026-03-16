<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory;

     protected $fillable = [
        'name',
        'email',
        'country_code',
        'phone',
        'address'
    ];

    public function categories()
    {
        return $this->belongsToMany(
            Category::class,
            'supplier_categories',
            'supplier_id',
            'category_id'
        )->withTimestamps();
    }
}
