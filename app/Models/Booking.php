<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Booking extends Model
{
    protected $guarded = [];

    protected $casts = [
        'check_in'     => 'date',
        'check_out'    => 'date',
        'confirmed_at' => 'datetime',
    ];

    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The reference the guest is told to quote.
     *
     * Year plus six characters from an unambiguous alphabet — no O/0 or I/1,
     * because this gets read down a phone line and typed back in.
     */
    public static function makeReference(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $reference = 'KZ' . date('y') . '-' . collect(range(1, 6))
                ->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])
                ->implode('');
        } while (static::where('reference', $reference)->exists());

        return $reference;
    }

    public function nights(): int
    {
        return max(1, $this->check_in->diffInDays($this->check_out));
    }

    public function totalLabel(): string
    {
        return number_format($this->total / 100, 2, ',', '.') . ' €';
    }

    public function scopeUpcoming($query)
    {
        return $query->where('check_out', '>=', now()->toDateString())->orderBy('check_in');
    }
}
