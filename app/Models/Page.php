<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A page, its blocks, and everything a search engine reads about it. */
class Page extends Model
{
    use HasTranslations;

    protected $guarded = [];

    protected array $translatable = [
        'title', 'meta_title', 'meta_description', 'og_title', 'og_description',
    ];

    protected $casts = [
        'is_published'     => 'boolean',
        'noindex'          => 'boolean',
        'sitemap_priority' => 'float',
    ];

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class)->orderBy('sort');
    }

    public function ogImage(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'og_image_id');
    }

    /** The block with this key, or null. Pages render by asking for these. */
    public function section(string $key): ?Section
    {
        return $this->sections->firstWhere('key', $key);
    }

    /** '' is the home page, so it maps to the site root rather than to '/'. */
    public function path(): string
    {
        return '/' . ltrim($this->slug, '/');
    }

    /** meta_title if one was written, the page title if not. */
    public function seoTitle(?string $locale = null): string
    {
        return (string) ($this->t('meta_title', $locale) ?: $this->t('title', $locale));
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }
}
