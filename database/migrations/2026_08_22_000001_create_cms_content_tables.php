<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The content the site is built from.
 *
 * Translatable columns hold JSON keyed by locale — {"de": "...", "en": "..."}
 * — rather than living in a parallel _translations table per entity. The site
 * has two languages and a handful of editors; a second table for each of six
 * entities would triple the joins on every page render and give the admin
 * panel a second form to keep in step, in exchange for a flexibility nobody
 * here has asked for. HasTranslations does the reading.
 *
 * Every entity keeps a stable `key` alongside its id. The design handoff
 * refers to apartments and sections by name in its markup, and a key survives
 * a reordering or a rename in a way an id in a template never does.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* Site-wide values: contact details, social links, analytics ids. */
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('group', 40)->default('general');
            $table->string('key', 100)->unique();
            $table->json('value');
            $table->timestamps();
        });

        /* Interface strings: nav, form labels, buttons, the booking flow's
           wording. Not content anyone writes as prose — content the design
           needs in both languages. */
        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->string('group', 40);            // nav, form, auth, payment, footer…
            $table->string('key', 120);
            $table->json('values');                 // {"de": "…", "en": "…"}
            $table->timestamps();
            $table->unique(['group', 'key']);
        });

        Schema::create('media', function (Blueprint $table) {
            $table->id();
            /* The slot id the design refers to this image by, where it has
               one. Null for anything uploaded outside a slot. */
            $table->string('slot', 96)->nullable()->unique();
            $table->string('path', 255);
            $table->string('mime', 64);
            $table->unsignedInteger('bytes')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->json('alt')->nullable();        // translatable
            $table->timestamps();
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();    // blumenstrasse, kanal
            $table->string('slug', 120)->unique();
            $table->string('street', 160)->default('');
            $table->string('postcode', 16)->default('');
            $table->string('city', 80)->default('Leipzig');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->foreignId('image_id')->nullable()->constrained('media')->nullOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_published')->default(true);
            $table->json('name');                   // translatable
            $table->json('description')->nullable();
            $table->timestamps();
        });

        Schema::create('apartments', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();    // residenz, cozy, belle-etage, nest
            $table->string('slug', 120)->unique();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('image_id')->nullable()->constrained('media')->nullOnDelete();
            /* Cents, not a float. A nightly rate is money and money does not
               survive binary floating point intact. */
            $table->unsignedInteger('price_per_night')->default(0);
            $table->unsignedTinyInteger('max_guests')->default(2);
            $table->unsignedTinyInteger('bedrooms')->default(1);
            $table->unsignedSmallInteger('size_sqm')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_published')->default(true);
            $table->json('name');                   // translatable
            $table->json('brief')->nullable();
            $table->json('description')->nullable();
            $table->json('features')->nullable();   // {"de": [...], "en": [...]}
            $table->timestamps();
        });

        Schema::create('amenities', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('icon', 40)->default('');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->json('title');
            $table->json('description')->nullable();
            $table->timestamps();
        });

        Schema::create('testimonials', function (Blueprint $table) {
            $table->id();
            $table->string('author', 120)->default('');
            $table->string('author_note', 160)->default('');
            $table->unsignedTinyInteger('rating')->default(5);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->json('quote');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('testimonials');
        Schema::dropIfExists('amenities');
        Schema::dropIfExists('apartments');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('media');
        Schema::dropIfExists('translations');
        Schema::dropIfExists('settings');
    }
};
