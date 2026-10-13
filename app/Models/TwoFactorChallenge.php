<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The gap between "your password was right" and "here is your session".
 * Holds no credentials — just a one-use handle bound to a user.
 */
class TwoFactorChallenge extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'token',
        'expires_at',
        'used_at',
        'attempts',
        'ip_address'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'attempts' => 'integer'
    ];

    public const LIFETIME_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUsable($query)
    {
        return $query->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->where('attempts', '<', self::MAX_ATTEMPTS);
    }

    /**
     * Issue a fresh challenge, retiring any the user already had open.
     * Returns the plaintext handle; only its hash is stored.
     */
    public static function issueFor(User $user, $ip = null)
    {
        self::where('user_id', $user->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $token = Str::random(48);

        self::create([
            'user_id' => $user->id,
            'token' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(self::LIFETIME_MINUTES),
            'ip_address' => $ip
        ]);

        return $token;
    }

    public static function findUsable($token)
    {
        if (!$token) {
            return null;
        }

        return self::where('token', hash('sha256', $token))
            ->usable()
            ->latest('id')
            ->first();
    }
}
