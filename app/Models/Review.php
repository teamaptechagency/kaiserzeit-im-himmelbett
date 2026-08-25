<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A guest review.
 *
 * Nothing a guest writes reaches the site before somebody has read it:
 * `status` starts at pending for anything submitted through the site, and
 * only the hosts' own entries are approved on creation.
 */
class Review extends Model
{
    use HasTranslations;

    protected $guarded = [];

    protected array $translatable = ['quote'];

    protected $casts = [
        'is_featured' => 'boolean',
        'stayed_at'   => 'date',
        'replied_at'  => 'datetime',
        'rating'      => 'integer',
    ];

    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending')->latest();
    }

    /** What the home page shows. */
    public function scopeFeatured($query)
    {
        return $query->approved()->where('is_featured', true)->orderBy('sort');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /** Five stars as characters, for a list that has no room for a widget. */
    public function stars(): string
    {
        $rating = max(0, min(5, (int) $this->rating));

        return str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);
    }
}
