<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactMessage extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_handled' => 'boolean',
        'read_at'    => 'datetime',
    ];

    public function scopeUnhandled($query)
    {
        return $query->where('is_handled', false)->latest();
    }
}
