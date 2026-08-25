<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One fingerprint, face or Windows Hello key.
 *
 * Only the public half is here. Nothing in this row can sign in — losing the
 * whole table to an attacker gives them a list of what exists, and no way to
 * use any of it.
 */
class Passkey extends Model
{
    protected $guarded = [];

    protected $hidden = ['public_key'];

    protected $casts = [
        'sign_count'   => 'integer',
        'last_used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Passkeys this browser registered, and so may be offered. */
    public function scopeForDevice($query, ?string $token)
    {
        return $query->where('device_token', $token);
    }

    /**
     * A readable name for the make of authenticator.
     *
     * The AAGUID identifies the model. Only the handful worth naming are
     * listed; anything else is described by what it is rather than guessed
     * at, because a wrong name here is worse than none.
     */
    public function authenticatorName(): string
    {
        return match ($this->aaguid) {
            '08987058cadc4b81b6e130de50dcbe96',
            '9ddd1817af5a4672a2b93e3dd95000a9' => 'Windows Hello',
            'adce000235bcc60a648b0b25f1f05503' => 'Chrome on this device',
            'fbfc3007154e4ecc8c0b6e020557d7bd',
            '00000000000000000000000000000000' => 'iCloud Keychain',
            default => $this->algorithm === 'RS256' ? 'Security device' : 'This device',
        };
    }

    public function displayLabel(): string
    {
        return $this->label !== '' ? $this->label : $this->authenticatorName();
    }
}
