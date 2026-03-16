<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoyaltyGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'discount_type',
        'discount_value',
        'validity',
        'period_days',
        'start_date',
        'end_date'
    ];

    public function users()
    {
        return $this->belongsToMany(
            User::class,
            'loyalty_group_users',
            'loyalty_group_id',
            'user_id'
        );
    }
}
