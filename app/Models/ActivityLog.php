<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in the audit trail.
 *
 * `user_label` repeats the name the account had at the time. The foreign key
 * goes null when somebody is deleted, and an audit trail that then reads
 * "someone changed the price" is worth very little.
 */
class ActivityLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'changes'    => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeFor($query, string $type, int $id)
    {
        return $query->where('subject_type', $type)->where('subject_id', $id);
    }

    /** "Apartment" rather than "App\Models\Apartment". */
    public function subjectName(): string
    {
        return $this->subject_type ? class_basename($this->subject_type) : '—';
    }
}
