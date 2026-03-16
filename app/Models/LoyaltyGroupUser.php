<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoyaltyGroupUser extends Model
{
    use HasFactory;

    protected $table = 'loyalty_group_users';

    protected $fillable = [
        'loyalty_group_id',
        'user_id'
    ];

    public function loyaltyGroup()
    {
        return $this->belongsTo(LoyaltyGroup::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
