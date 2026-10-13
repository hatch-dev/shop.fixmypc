<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SpecificationGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'sort_order'
    ];

    public function specifications()
    {
        return $this->hasMany(
            ProductSpecification::class,
            'group_id'
        );
    }
}
