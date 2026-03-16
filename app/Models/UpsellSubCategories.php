<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UpsellSubCategories extends Model
{
    use HasFactory;

    protected $table = 'upsell_subcategories';

    protected $fillable = [
        'upsell_id',
        'subcategory_id',
    ];

    public $timestamps = true;

    public function upsell()
    {
        return $this->belongsTo(Upsell::class, 'upsell_id');
    }

    public function subcategory()
    {
        return $this->belongsTo(Category::class, 'subcategory_id');
    }
}
