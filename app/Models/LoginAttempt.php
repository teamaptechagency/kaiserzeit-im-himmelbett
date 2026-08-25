<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Every attempt to sign in, successful or not.
 *
 * The security screen reads this: repeated failures against one address are
 * the first sign of somebody working through a password list, and there is no
 * way to notice that without writing the failures down.
 *
 * The address is hashed, not stored. Counting attempts from one source does
 * not require knowing where it is.
 */
class LoginAttempt extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'successful' => 'boolean',
        'created_at' => 'datetime',
    ];

    public static function record(string $email, bool $successful, ?string $reason = null): void
    {
        $request = request();

        static::create([
            'email'      => mb_substr(strtolower($email), 0, 190),
            'ip_hash'    => hash('sha256', $request->ip() . config('app.key')),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'successful' => $successful,
            'reason'     => $reason,
            'created_at' => now(),
        ]);
    }

    public function scopeFailed($query)
    {
        return $query->where('successful', false);
    }

    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }
}
