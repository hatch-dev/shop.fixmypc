<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A single-use handle emailed as a reset link. Only its hash is stored, so the
 * table on its own is not enough to take over an account.
 */
class PasswordResetToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'token',
        'expires_at',
        'used_at',
        'ip_address'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime'
    ];

    /** Matches the "valid for 60 minutes" promise in the email and on screen. */
    public const LIFETIME_MINUTES = 60;

    /** Links one address may request within the throttle window. */
    public const MAX_PER_WINDOW = 5;

    public const THROTTLE_MINUTES = 60;

    public function scopeUsable($query)
    {
        return $query->whereNull('used_at')
            ->where('expires_at', '>', now());
    }

    /**
     * Issue a link token, retiring any the address already had outstanding —
     * asking for a new link should invalidate the old one.
     */
    public static function issueFor($email, $ip = null)
    {
        self::where('email', $email)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $token = Str::random(64);

        self::create([
            'email' => $email,
            'token' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(self::LIFETIME_MINUTES),
            'ip_address' => $ip
        ]);

        return $token;
    }

    public static function findUsable($token, $email = null)
    {
        if (!$token) {
            return null;
        }

        $query = self::where('token', hash('sha256', $token))->usable();

        if ($email) {
            $query->where('email', $email);
        }

        return $query->latest('id')->first();
    }
}
