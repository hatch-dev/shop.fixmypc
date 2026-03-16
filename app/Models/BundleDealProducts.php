<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BundleDealProducts extends Model
{
    use HasFactory;

    protected $fillable = [
        'bundle_deal_id', 'product_id'
    ];
}
