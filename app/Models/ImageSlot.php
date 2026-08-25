<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The index of uploaded photos. The files themselves are on disk. */
class ImageSlot extends Model
{
    protected $fillable = ['slot', 'extension', 'mime', 'bytes'];

    /** Public URL, with the cache buster the editor relies on. */
    public function url(): string
    {
        return '/storage/slots/' . $this->slot . '.' . $this->extension
             . '?v=' . ($this->updated_at?->getTimestamp() ?? 0);
    }
}
