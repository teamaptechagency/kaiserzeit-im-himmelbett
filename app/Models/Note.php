<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A pinned comment and its replies. */
class Note extends Model
{
    protected $fillable = [
        'note_key', 'page', 'el', 'x', 'y', 'author', 'body', 'noted_at', 'resolved', 'replies',
    ];

    protected $casts = [
        'x'        => 'float',
        'y'        => 'float',
        'noted_at' => 'integer',
        'resolved' => 'boolean',
        'replies'  => 'array',
    ];

    /**
     * The wire shape the browser reads and writes.
     *
     * Field names are the client's, not the table's: `text` rather than
     * `body`, `at` rather than `noted_at`. notes.js is kept untouched, so the
     * translation happens here.
     */
    public function toWire(): array
    {
        return [
            'page'     => $this->page,
            'el'       => $this->el,
            'x'        => $this->x,
            'y'        => $this->y,
            'author'   => $this->author,
            'text'     => $this->body,
            'at'       => $this->noted_at,
            'resolved' => $this->resolved,
            'replies'  => $this->replies ?? [],
        ];
    }
}
