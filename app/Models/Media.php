<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;

/**
 * An uploaded file.
 *
 * Supersedes ImageSlot: that table only ever answered "which photo is in this
 * slot", and a CMS needs the same file usable as an apartment photo, an
 * og:image and a slot fill at once.
 */
class Media extends Model
{
    use HasTranslations;

    protected $table = 'media';
    protected $guarded = [];
    protected array $translatable = ['alt'];

    /** Public URL, with the cache buster the editor relies on. */
    public function url(): string
    {
        return '/storage/' . ltrim($this->path, '/')
             . '?v=' . ($this->updated_at?->getTimestamp() ?? 0);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }
}
