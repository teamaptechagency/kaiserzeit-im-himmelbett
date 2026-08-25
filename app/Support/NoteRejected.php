<?php

namespace App\Support;

use RuntimeException;

/**
 * Thrown to roll a content transaction back for a note the client should be
 * told about — no text, or the 500-note ceiling reached. Carries the wording
 * the browser shows.
 */
class NoteRejected extends RuntimeException
{
}
