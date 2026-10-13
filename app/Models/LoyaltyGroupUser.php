<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoyaltyGroupUser extends Model
{
    use HasFactory;

    protected $table = 'loyalty_group_users';

    protected $fillable = [
        'loyalty_group_id',
        'user_id',
        'joined_at',
        'expires_at',
        'used_at',
        'used_order_id'
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'expires_at' => 'datetime',
        'used_at' => 'datetime'
    ];

    public function loyaltyGroup()
    {
        return $this->belongsTo(LoyaltyGroup::class, 'loyalty_group_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Can this membership still pay for an order?
     *
     * A 'one_time' membership is spent the moment it is attached to a paid
     * order; the others expire on a date. Both were advertised in the admin UI
     * and neither was enforced anywhere.
     */
    public function isRedeemable($at = null)
    {
        $at = $at ? Carbon::parse($at) : Carbon::now();

        if (!is_null($this->used_at)) {
            return false;
        }

        if ($this->expires_at && $at->gt($this->expires_at)) {
            return false;
        }

        $group = $this->relationLoaded('loyaltyGroup')
            ? $this->loyaltyGroup
            : $this->loyaltyGroup()->first();

        return $group ? $group->isRunning($at) : false;
    }

    public function scopeRedeemable($query, $at = null)
    {
        $at = $at ? Carbon::parse($at) : Carbon::now();

        return $query->whereNull('used_at')
            ->where(function ($q) use ($at) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>=', $at);
            });
    }
}
