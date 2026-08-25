<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The visitor counter.
 *
 * One row per page view, plus a daily rollup the dashboard reads instead of
 * counting rows. A small site still accumulates a few hundred thousand rows a
 * year, and "how many visitors this month" should not become a table scan.
 *
 * No IP address is stored. `visitor_hash` is a salted digest of the address
 * and user agent, rotated daily by including the date in the salt, which is
 * enough to tell two visits apart on the same day and useless for identifying
 * anyone afterwards. That keeps the counter honest without turning the site
 * into something that needs a consent banner to run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_views', function (Blueprint $table) {
            $table->id();
            $table->string('path', 190);
            $table->string('locale', 5)->default('de');
            $table->string('visitor_hash', 64);
            $table->string('referrer_host', 120)->nullable();
            $table->string('device', 12)->default('desktop');   // desktop | mobile | tablet | bot
            $table->boolean('is_new_visitor')->default(true);
            $table->timestamp('viewed_at');

            $table->index(['viewed_at']);
            $table->index(['path', 'viewed_at']);
            $table->index(['visitor_hash', 'viewed_at']);
        });

        Schema::create('page_view_daily', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->string('path', 190);
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('visitors')->default(0);
            $table->timestamps();
            $table->unique(['day', 'path']);
            $table->index('day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_view_daily');
        Schema::dropIfExists('page_views');
    }
};
