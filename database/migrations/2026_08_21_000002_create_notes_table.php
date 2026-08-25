<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pinned comments, one row per note.
 *
 * `note_key` is the id the browser generated. Keying on it rather than on an
 * auto-increment is what let two people comment at once in the JSON build
 * without overwriting each other, and the same holds here.
 *
 * Replies stay as a JSON column. They are only ever read and written with
 * their parent note, never queried across notes, so a second table would buy
 * nothing. The 60-reply cap from api/content.js is enforced in PHP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->string('note_key', 64)->unique();

            $table->string('page', 120)->default('');
            $table->string('el', 40)->default('root');

            /* Fractions of the element box, 0..1. Float is the right shape:
               these are positions on screen, never money. */
            $table->float('x')->default(0);
            $table->float('y')->default(0);

            $table->string('author', 80)->default('');
            $table->text('body');

            /* The browser's own millisecond timestamp. Kept separate from
               created_at because the client sorts on it and round-trips it. */
            $table->unsignedBigInteger('noted_at')->default(0);

            $table->boolean('resolved')->default(false);
            $table->json('replies');

            $table->timestamps();

            $table->index('page');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
