<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A one-time 6-digit code emailed to a customer so they can sign in without
 * their password. Codes are stored hashed and are good for one use.
 */
class LoginCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'purpose',
        'code',
        'expires_at',
        'used_at',
        'attempts',
        'ip_address'
    ];

    /**
     * What a code may be spent on.
     *
     * Every row here used to be interchangeable, and verifyLoginCode turns a
     * row into a session. A code emailed to confirm a checkout address must
     * not be able to do that, so each flow only ever queries its own purpose.
     */
    public const PURPOSE_LOGIN = 'login';
    public const PURPOSE_GUEST_CHECKOUT = 'guest_checkout';

    /**
     * A texted second factor, and a code sent to prove a mobile number.
     *
     * Both are separate from PURPOSE_LOGIN for the reason above: the SMS
     * second factor used to be written with no purpose at all and read back
     * with no filter, so the newest usable code of *any* kind on that address
     * — including one emailed by the passwordless sign-in — satisfied it.
     * A second factor has to be the thing that was texted to the phone.
     */
    public const PURPOSE_TWO_FACTOR = 'two_factor';
    public const PURPOSE_PHONE_VERIFY = 'phone_verify';

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'attempts' => 'integer'
    ];

    /** Minutes a code stays valid for. */
    public const LIFETIME_MINUTES = 10;

    /** Wrong guesses allowed before the code is burned. */
    public const MAX_ATTEMPTS = 5;

    /** Codes one email address may request within the throttle window. */
    public const MAX_PER_WINDOW = 5;

    /** Length of the throttle window, in minutes. */
    public const THROTTLE_MINUTES = 60;

    public function scopeUsable($query)
    {
        return $query->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->where('attempts', '<', self::MAX_ATTEMPTS);
    }

    public function scopeForPurpose($query, $purpose)
    {
        return $query->where('purpose', $purpose);
    }
}
