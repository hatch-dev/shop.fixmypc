<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UpdatedUpsell extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'status',
    ];

    public function items()
    {
        return $this->hasMany(UpdatedUpsellProductService::class, 'updated_upsells_id');
    }
}
