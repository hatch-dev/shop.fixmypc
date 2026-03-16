<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UpsellCategories extends Model
{
    use HasFactory;

    protected $table = 'upsell_categories';

    protected $fillable = [
        'upsell_id',
        'category_id',
    ];

    public $timestamps = true;

    public function upsell()
    {
        return $this->belongsTo(Upsell::class, 'upsell_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}
