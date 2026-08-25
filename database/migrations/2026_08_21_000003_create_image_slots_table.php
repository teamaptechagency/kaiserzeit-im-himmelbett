<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per uploaded photo.
 *
 * On Vercel the listing came from asking Blob for everything under `slots/`.
 * There is no such listing service behind a local disk that is cheap to call
 * on every page load, so the index that was implicit there is a table here.
 * The file itself lives at storage/app/public/slots/<slot>.<extension>.
 *
 * `updated_at` doubles as the cache buster the old code took from the blob's
 * uploadedAt, so replacing a photo still busts the browser cache.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_slots', function (Blueprint $table) {
            $table->id();
            $table->string('slot', 96)->unique();
            $table->string('extension', 8);
            $table->string('mime', 64);
            $table->unsignedInteger('bytes')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_slots');
    }
};
