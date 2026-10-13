<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A value an admin has explicitly chosen to offer, in manual mode.
 *
 * An attribute VALUE rather than a variant: "offer 512GB" survives the
 * compatibility filter and holds for the black one and the blue one alike,
 * where "offer variant 162" would mean the grey 512GB and nothing else.
 */
class ProductUpgradeGroupOption extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_upgrade_group_id',
        'attribute_value_id',
        'display_order',
    ];

    protected $casts = [
        'product_upgrade_group_id' => 'integer',
        'attribute_value_id' => 'integer',
        'display_order' => 'integer',
    ];

    public function group()
    {
        return $this->belongsTo(ProductUpgradeGroup::class, 'product_upgrade_group_id');
    }

    public function attribute_value()
    {
        return $this->hasOne(AttributeValue::class, 'id', 'attribute_value_id');
    }
}
