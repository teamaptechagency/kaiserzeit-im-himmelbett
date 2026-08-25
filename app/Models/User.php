<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Two kinds of person share this table.
 *
 * Guests get accounts because the design's booking flow signs them in and its
 * profile page shows them their history. Administrators run the site. `role`
 * is the only thing between the two, so everything that matters checks the
 * role rather than checking whether somebody is merely signed in.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;
    use Notifiable;

    public const ROLE_ADMIN  = 'admin';
    public const ROLE_EDITOR = 'editor';
    public const ROLE_GUEST  = 'guest';

    public const ROLES = [
        self::ROLE_ADMIN  => 'Administrator',
        self::ROLE_EDITOR => 'Editor',
        self::ROLE_GUEST  => 'Guest',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name', 'username', 'email', 'password', 'role',
        'phone', 'whatsapp', 'locale', 'is_active',
    ];

    /**
     * Names nobody may take.
     *
     * A guest signing up as "admin" and then being addressed as such in every
     * email the site sends is a small confusion with a long tail. "support"
     * and the rest are the ones people impersonate.
     */
    public const RESERVED_USERNAMES = [
        'admin', 'administrator', 'root', 'support', 'help', 'info', 'kontakt',
        'contact', 'system', 'kaiserzeit', 'staff', 'team', 'me', 'null', 'undefined',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at'       => 'datetime',
            'password'                => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at'           => 'datetime',
            'is_active'               => 'boolean',
            /* Encrypted at rest: a leaked backup should not hand over the
               second factor along with the first. */
            'two_factor_secret'         => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
        ];
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function passkeys(): HasMany
    {
        return $this->hasMany(Passkey::class)->latest("last_used_at");
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function activity(): HasMany
    {
        return $this->hasMany(ActivityLog::class)->latest('created_at');
    }

    /* ------------------------------------------------------------ sign-in */

    /**
     * Finds the account behind whatever was typed into the one sign-in field.
     *
     * An email or a username, told apart by the @ rather than by asking. Both
     * are matched folded to lower case: usernames are stored that way, and an
     * email address that only differs in case is the same mailbox.
     *
     * Deliberately not two queries with an early return — one lookup, so the
     * time it takes says nothing about which of the two was recognised.
     */
    public static function findForLogin(string $identifier): ?self
    {
        $identifier = mb_strtolower(trim($identifier));

        if ($identifier === '') {
            return null;
        }

        return static::whereRaw('LOWER(email) = ?', [$identifier])
            ->orWhere('username', $identifier)
            ->first();
    }

    /** Folded to lower case on the way in, so two cases cannot be two people. */
    public function setUsernameAttribute(?string $value): void
    {
        $this->attributes['username'] = $value === null ? null : mb_strtolower(trim($value));
    }

    /* --------------------------------------------------------------- roles */

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /** Who may reach the admin panel and edit the site. */
    public function isStaff(): bool
    {
        return in_array($this->role, [self::ROLE_ADMIN, self::ROLE_EDITOR], true);
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? $this->role;
    }

    /* ----------------------------------------------------- two-factor auth */

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /** Enrolment started but never finished — must not gate a sign-in. */
    public function twoFactorPending(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at === null;
    }

    /**
     * Spends one recovery code.
     *
     * Single use: a code that still worked after being used would be a
     * password with extra steps.
     */
    public function useRecoveryCode(string $code): bool
    {
        $codes = $this->two_factor_recovery_codes ?? [];
        $code  = strtoupper(trim($code));

        foreach ($codes as $index => $stored) {
            if (hash_equals(strtoupper((string) $stored), $code)) {
                unset($codes[$index]);
                $this->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    public function scopeStaff($query)
    {
        return $query->whereIn('role', [self::ROLE_ADMIN, self::ROLE_EDITOR]);
    }

    public function scopeGuests($query)
    {
        return $query->where('role', self::ROLE_GUEST);
    }
}
