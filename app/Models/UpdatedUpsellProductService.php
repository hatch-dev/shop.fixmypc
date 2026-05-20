<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UpdatedUpsellProductService extends Model
{
    use HasFactory;

    protected $table = 'updated_upsells_product_service';

    protected $fillable = [
        'updated_upsells_id',
        'type',
        'title',
        'image',
        'description',
        'service_price',
    ];

    public function upsell()
    {
        return $this->belongsTo(UpdatedUpsell::class, 'updated_upsells_id');
    }

    public function upgradeOptions()
    {
        return $this->hasMany(
            UpdatedUpsellProductServiceItems::class,
            'updated_upsell_product_service_id'
        );
    }
}
