<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class Admin extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens, HasRoles;

    /** A reset code is good for this long, then it is worthless. */
    const CODE_LIFETIME_MINUTES = 15;

    /** Wrong guesses tolerated before the code is discarded entirely. */
    const MAX_CODE_ATTEMPTS = 5;

    protected $casts = [
        'remember_token' => 'integer',
        'verified' => 'integer',
        'active' => 'integer',
        'viewed' => 'integer',
        'code_expires_at' => 'datetime',
        'code_attempts' => 'integer'
    ];

    protected $fillable = [
        'name',
        'username',
        'email',
        'image',
        'commission',
        'password',
        'code',
        'code_expires_at',
        'code_attempts',
        'verified',
        'viewed',
        'active'
    ];

    /**
     * Strict comparison of a submitted verification code.
     *
     * Both sides are cast to string and compared with ===, so a NULL stored
     * code can never match integer 0, "0", false or an empty submission — the
     * loose != that used to guard this handed out password resets on any admin
     * account that had never requested one (QA audit SEC-01).
     */
    public static function codeMatches($admin, $submitted)
    {
        if (is_null($admin) || is_null($admin->code) || $admin->code === '') {
            return false;
        }

        if (is_null($submitted) || is_array($submitted) || $submitted === '') {
            return false;
        }

        if ($admin->code_expires_at && $admin->code_expires_at->isPast()) {
            return false;
        }

        if ((int)$admin->code_attempts >= self::MAX_CODE_ATTEMPTS) {
            return false;
        }

        return hash_equals((string)$admin->code, (string)$submitted);
    }

    protected $hidden = [
        'password',
        'remember_token',
    ];

}
