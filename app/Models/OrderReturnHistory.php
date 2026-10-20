<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderReturnHistory extends Model
{
    protected $table = 'order_return_history';

    protected $fillable = ['order_return_id', 'from_status', 'to_status', 'admin_id', 'note'];

    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }
}
