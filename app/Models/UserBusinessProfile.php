<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The extra detail a business or trade account gives us at signup.
 * Personal accounts never have one of these.
 */
class UserBusinessProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'company_name',
        'vat_number',
        'cro_number',
        'billing_address',
        'sector',
        'requires_po',
        'credit_application',
        'trade',
        'expected_spend',
        'website',
        'resale_confirmed'
    ];

    protected $casts = [
        'requires_po' => 'boolean',
        'credit_application' => 'boolean',
        'resale_confirmed' => 'boolean'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
