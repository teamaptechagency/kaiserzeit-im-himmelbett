<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One block on a page — the hero, the intro, the host note.
 *
 * `content` is the block's own fields keyed by locale, because a hero and a
 * map have no fields in common and a column per field would be a table nobody
 * could change without a migration.
 */
class Section extends Model
{
    protected $guarded = [];

    protected $casts = [
        'content'    => 'array',
        'is_visible' => 'boolean',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /** One field out of this block, in one language, with a fallback. */
    public function get(string $field, mixed $default = null, ?string $locale = null): mixed
    {
        $locale ??= app()->getLocale();
        $fallback = config('app.fallback_locale', 'de');

        return data_get($this->content, $locale . '.' . $field)
            ?? data_get($this->content, $fallback . '.' . $field)
            ?? $default;
    }

    public function put(string $locale, string $field, mixed $value): static
    {
        $content = $this->content ?? [];
        $content[$locale][$field] = $value;
        $this->content = $content;

        return $this;
    }
}
