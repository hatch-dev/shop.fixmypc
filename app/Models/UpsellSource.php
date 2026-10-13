<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A product a campaign applies to: what the customer has, or is looking at.
 *
 * inventory_id null means the whole product -- every variant of it. Set means
 * one variant, which is how "iPhone 15 Pro 128GB" is named when 128GB, 256GB
 * and 512GB are variants of one catalogue product rather than three products.
 */
class UpsellSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'upsell_campaign_id',
        'product_id',
        'inventory_id',
    ];

    protected $casts = [
        'upsell_campaign_id' => 'integer',
        'product_id' => 'integer',
        'inventory_id' => 'integer',
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
