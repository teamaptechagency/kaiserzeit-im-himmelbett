<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Testimonials become reviews.
 *
 * The handoff had three quotes with a name on them, hard-coded — decoration,
 * not data. A guest who has actually stayed should be able to leave one, the
 * hosts should be able to reply, and nothing should reach the site until
 * somebody has read it. That needs a rating, an apartment, a stay date, a
 * moderation state and a reply, none of which a testimonial had.
 *
 * The table is renamed rather than replaced so the three seeded quotes come
 * along; they land as approved reviews with no apartment attached.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('testimonials', 'reviews');

        Schema::table('reviews', function (Blueprint $table) {
            $table->foreignId('apartment_id')->nullable()->after('id')->constrained()->nullOnDelete();
            /* Set when a guest with an account writes one; null for a quote
               the hosts entered themselves. */
            $table->foreignId('user_id')->nullable()->after('apartment_id')->constrained()->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->after('user_id')->constrained()->nullOnDelete();

            $table->string('email', 190)->nullable()->after('author');
            $table->string('title', 190)->nullable()->after('email');
            $table->date('stayed_at')->nullable();
            $table->string('locale', 5)->default('de');
            $table->string('source', 20)->default('site');     // site | google | airbnb | booking
            $table->string('status', 20)->default('approved'); // pending | approved | rejected
            $table->text('reply')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->string('ip_hash', 64)->nullable();

            $table->index('status');
            $table->index(['apartment_id', 'status']);
        });

        /* is_visible said "show this on the home page". That is now two
           separate questions — has it been approved, and is it featured — so
           the old flag becomes the featured one and status carries approval. */
        Schema::table('reviews', function (Blueprint $table) {
            $table->renameColumn('is_visible', 'is_featured');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->renameColumn('is_featured', 'is_visible');
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->dropForeign(['apartment_id']);
            $table->dropForeign(['user_id']);
            $table->dropForeign(['booking_id']);
            $table->dropColumn([
                'apartment_id', 'user_id', 'booking_id', 'email', 'title',
                'stayed_at', 'locale', 'source', 'status', 'reply', 'replied_at', 'ip_hash',
            ]);
        });

        Schema::rename('reviews', 'testimonials');
    }
};
