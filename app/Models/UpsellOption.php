<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An upgrade the campaign offers in place of its source products.
 *
 * priority orders the options WITHIN one campaign -- 256GB before 512GB.
 * Lower first, ties broken by id so the order is deterministic either way.
 * It carries no price for the same reason the campaign does not.
 */
class UpsellOption extends Model
{
    use HasFactory;

    protected $fillable = [
        'upsell_campaign_id',
        'product_id',
        'inventory_id',
        'priority',
    ];

    protected $casts = [
        'upsell_campaign_id' => 'integer',
        'product_id' => 'integer',
        'inventory_id' => 'integer',
        'priority' => 'integer',
    ];

    public function campaign()
    {
        return $this->belongsTo(UpsellCampaign::class, 'upsell_campaign_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function inventory()
    {
        return $this->belongsTo(UpdatedInventory::class, 'inventory_id');
    }
}
