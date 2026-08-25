<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;

class Amenity extends Model
{
    use HasTranslations;

    protected $guarded = [];
    protected array $translatable = ['title', 'description'];
    protected $casts = ['is_visible' => 'boolean'];

    public function scopeVisible($query)
    {
        return $query->where('is_visible', true)->orderBy('sort');
    }
}
