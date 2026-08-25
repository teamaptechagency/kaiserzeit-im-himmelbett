<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who changed what, and when.
 *
 * Two people share this panel and both can rewrite the site's copy, its
 * prices and its bookings. Without a record, "the rate is wrong and nobody
 * touched it" is unanswerable — so every write through the admin panel leaves
 * a row here.
 *
 * `changes` holds only the fields that actually differed, before and after.
 * Storing the whole model twice per edit would turn a year of ordinary
 * editing into a table larger than the site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();

            /* Kept if the account is later deleted: the record of what
               happened should outlive the person who left. */
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_label', 160)->default('');

            $table->string('action', 40);               // created | updated | deleted | login | logout…
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label', 190)->default('');

            $table->text('description')->nullable();
            $table->json('changes')->nullable();

            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
