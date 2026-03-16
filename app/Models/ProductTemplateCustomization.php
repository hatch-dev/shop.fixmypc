<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductTemplateCustomization extends Model
{
    use HasFactory;

    protected $fillable = ['product_id', 'template_id', 'name', 'custom_content'];

    public function template()
    {
        return $this->belongsTo(Template::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class); 
    }
}

?>