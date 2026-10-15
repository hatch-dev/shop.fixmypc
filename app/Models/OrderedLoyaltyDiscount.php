<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderedLoyaltyDiscount extends Model
{
    use HasFactory;

    protected $table = 'ordered_loyalty_discount';

    protected $fillable = [
        'order_id',
        'loyalty_group_id',
        'loyalty_group_user_id',
        // set when a membership, not a loyalty group, paid (Helper\Membership)
        'user_membership_id',
        'label',
        'discount'
    ];

    protected $casts = [
        'discount' => 'float'
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function loyaltyGroup()
    {
        return $this->belongsTo(LoyaltyGroup::class, 'loyalty_group_id');
    }

    public function userMembership()
    {
        return $this->belongsTo(UserMembership::class, 'user_membership_id');
    }

    public function membership()
    {
        return $this->belongsTo(LoyaltyGroupUser::class, 'loyalty_group_user_id');
    }
}
