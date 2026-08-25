<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pages, the blocks they are made of, and everything a search engine reads.
 *
 * SEO fields live on the page rather than in a table of their own. They are
 * one-to-one with a page, they are edited on the same screen, and splitting
 * them would buy a join and nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();        // home, apartments, booking…
            $table->string('slug', 160)->unique();      // '' for the home page
            $table->string('template', 60)->default('page');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_published')->default(true);

            $table->json('title');                      // translatable

            /* --- what search engines and social cards read ---------------- */
            $table->json('meta_title')->nullable();
            $table->json('meta_description')->nullable();
            $table->json('og_title')->nullable();
            $table->json('og_description')->nullable();
            $table->foreignId('og_image_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('canonical', 255)->nullable();
            $table->boolean('noindex')->default(false);
            /* 0.0–1.0 and a change frequency, both straight into sitemap.xml */
            $table->decimal('sitemap_priority', 2, 1)->default(0.5);
            $table->string('sitemap_frequency', 12)->default('weekly');

            $table->timestamps();
        });

        /* A block on a page: the hero, the intro, the map, the host note.
           `content` is the block's own fields keyed by locale, because what a
           hero holds and what a map holds have nothing in common and a column
           per field would be a table nobody could change without a migration. */
        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->string('key', 60);                  // hero, intro, groups…
            $table->string('type', 40)->default('rich'); // which partial renders it
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->json('content');                    // {"de": {...}, "en": {...}}
            $table->timestamps();
            $table->unique(['page_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sections');
        Schema::dropIfExists('pages');
    }
};
