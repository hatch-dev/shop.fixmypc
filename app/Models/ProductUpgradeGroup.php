<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

/**
 * One upgradeable attribute of one product: "Storage Upgrade".
 *
 * Holds the rules, never the upgrades. Which variants qualify is worked out
 * from the product's own variants every time it is asked, by
 * ProductUpgradeService.
 */
class ProductUpgradeGroup extends Model
{
    use HasFactory;

    const MODE_AUTOMATIC = 'automatic';
    const MODE_MANUAL = 'manual';

    const OUT_OF_STOCK_HIDE = 'hide';
    const OUT_OF_STOCK_DISABLE = 'disable';

    const CUSTOMER_TYPES = ['all', 'personal', 'business', 'trade'];

    protected $fillable = [
        'product_id',
        'name',
        'attribute_id',
        'mode',
        'status',
        'display_order',
        'out_of_stock',
        'customer_type',
        'max_options',
        'admin_id',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'attribute_id' => 'integer',
        'status' => 'integer',
        'display_order' => 'integer',
        'max_options' => 'integer',
    ];

    public function options()
    {
        return $this->hasMany(ProductUpgradeGroupOption::class, 'product_upgrade_group_id');
    }

    public function attribute()
    {
        return $this->hasOne(Attribute::class, 'id', 'attribute_id')
            ->select(['id', 'title']);
    }

    public function isActive()
    {
        return (int)$this->status === (int)Config::get('constants.status.PUBLIC');
    }

    public function isManual()
    {
        return $this->mode === self::MODE_MANUAL;
    }

    /**
     * Is this group aimed at this account?
     *
     * A guest ($customer null) matches 'all' and nothing else -- the named
     * types are properties of an account, and there is no account to read.
     */
    public function appliesTo($customer = null)
    {
        if ($this->customer_type === 'all') {
            return true;
        }

        if (is_null($customer)) {
            return false;
        }

        return $this->customer_type === $customer->account_type;
    }

    /** Does an unbuyable option stay on screen, greyed, or disappear? */
    public function showsUnavailable()
    {
        return $this->out_of_stock === self::OUT_OF_STOCK_DISABLE;
    }
}
