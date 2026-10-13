<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GuestUser extends Model
{
    use HasFactory;


    protected $casts = [
        'viewed' => 'integer',
        'email_verified_at' => 'datetime'
    ];

    protected $fillable = [
        'name', 'email', 'phone', 'user_token', 'default_address', 'viewed',
        'verified_email', 'email_verified_at'
    ];

    /**
     * Whether the address currently on the record is the one that was proved.
     *
     * Comparing the two rather than reading a flag is what makes editing the
     * email on the checkout form drop the confirmation: the guest proved
     * one@example.com, so two@example.com is not verified, and the receipt and
     * account credentials would otherwise go somewhere nobody confirmed.
     */
    public function hasVerifiedEmail()
    {
        return $this->email
            && $this->email_verified_at
            && strcasecmp((string) $this->verified_email, (string) $this->email) === 0;
    }
}
