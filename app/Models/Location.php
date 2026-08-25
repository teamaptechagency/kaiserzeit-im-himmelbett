<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One of the two addresses, and the apartments in it. */
class Location extends Model
{
    use HasTranslations;

    protected $guarded = [];

    protected array $translatable = ['name', 'description'];

    protected $casts = [
        'is_published' => 'boolean',
        'latitude'     => 'float',
        'longitude'    => 'float',
    ];

    public function apartments(): HasMany
    {
        return $this->hasMany(Apartment::class)->orderBy('sort');
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_id');
    }

    public function addressLine(): string
    {
        return trim($this->street . ', ' . $this->postcode . ' ' . $this->city, ' ,');
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true)->orderBy('sort');
    }
}
