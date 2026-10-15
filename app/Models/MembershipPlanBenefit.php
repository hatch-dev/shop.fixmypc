<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One thing a plan gives its members.
 *
 *   free_shipping     delivery costs nothing (collection is unchanged);
 *                     min_order is an optional floor on the goods total
 *   percent_discount  percent off the goods, taken at the till as the order's
 *                     loyalty/member discount; min_order and max_discount
 *                     as on a loyalty group; sale-priced and business-band
 *                     lines only count when the flags say so
 *   early_access      members are emailed when new products go on sale
 *                     (Helper\NewProductAlerts); anyone can buy them
 *   display           copy only - "Priority support" - shown
 *                     on the Savers Club page, enforced by nothing
 */
class MembershipPlanBenefit extends Model
{
    const FREE_SHIPPING = 'free_shipping';
    const PERCENT_DISCOUNT = 'percent_discount';
    const EARLY_ACCESS = 'early_access';
    const DISPLAY = 'display';

    const TYPES = [self::FREE_SHIPPING, self::PERCENT_DISCOUNT, self::EARLY_ACCESS, self::DISPLAY];

    protected $fillable = [
        'membership_plan_id', 'type', 'title', 'subtitle', 'icon', 'highlight',
        'percent', 'min_order', 'max_discount',
        'include_sale_items', 'include_business_prices', 'sort_order',
    ];

    protected $casts = [
        'highlight' => 'boolean',
        'percent' => 'float',
        'min_order' => 'float',
        'max_discount' => 'float',
        'include_sale_items' => 'boolean',
        'include_business_prices' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function plan()
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }

    public function toPublic()
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'icon' => $this->icon,
            'highlight' => $this->highlight,
            'percent' => $this->type === self::PERCENT_DISCOUNT ? $this->percent : null,
            'min_order' => in_array($this->type, [self::FREE_SHIPPING, self::PERCENT_DISCOUNT], true) ? $this->min_order : null,
        ];
    }
}
