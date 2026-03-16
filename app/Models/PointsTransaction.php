<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PointsTransaction extends Model
{
    use HasFactory;

    protected $table = 'points_transactions';

    protected $fillable = [
        'user_id',
        'points',
        'type',
        'source',
        'reference_id'
    ];
}
