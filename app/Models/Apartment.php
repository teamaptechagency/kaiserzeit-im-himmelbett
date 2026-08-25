<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Apartment extends Model
{
    use HasTranslations;

    protected $guarded = [];

    protected array $translatable = ['name', 'brief', 'description', 'features'];

    protected $casts = [
        'is_published'    => 'boolean',
        'price_per_night' => 'integer',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true)->orderBy('sort');
    }

    /**
     * Free for this stay?
     *
     * Two stays overlap unless one ends on or before the other begins — the
     * changeover day itself is not a clash, because one guest leaves in the
     * morning and the next arrives in the afternoon.
     */
    public function isFreeBetween(string $checkIn, string $checkOut, ?int $ignoreBookingId = null): bool
    {
        return ! $this->bookings()
            ->whereIn('status', ['requested', 'confirmed'])
            ->when($ignoreBookingId, fn ($q) => $q->where('id', '!=', $ignoreBookingId))
            ->where('check_in', '<', $checkOut)
            ->where('check_out', '>', $checkIn)
            ->exists();
    }

    /** Cents to "120,00 €" — German conventions, because the site is German. */
    public function priceLabel(): string
    {
        return number_format($this->price_per_night / 100, 2, ',', '.') . ' €';
    }
}
