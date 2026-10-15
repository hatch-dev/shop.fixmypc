<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One members' new-product email run: what it announced and to how many. */
class NewProductAlert extends Model
{
    protected $fillable = ['products_count', 'recipients_count', 'product_ids'];
}
