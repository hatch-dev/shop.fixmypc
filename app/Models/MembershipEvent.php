<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Who did what to a membership: the history on the admin member page. */
class MembershipEvent extends Model
{
    protected $fillable = ['user_membership_id', 'admin_id', 'action', 'detail'];

    public function membership()
    {
        return $this->belongsTo(UserMembership::class, 'user_membership_id');
    }

    public function admin()
    {
        return $this->belongsTo(Admin::class);
    }
}
