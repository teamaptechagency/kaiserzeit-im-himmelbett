<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What guests send in: booking requests and contact messages.
 *
 * The design's booking flow ends at "request sent", not at a charge — payment
 * is cash on arrival or arranged afterwards — so a booking here is a request
 * with a status, not a transaction. Money is still stored in cents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            /* What the guest is told to quote, and what the confirmation
               screen shows. Short, unambiguous, not the primary key. */
            $table->string('reference', 20)->unique();

            $table->foreignId('apartment_id')->nullable()->constrained()->nullOnDelete();
            /* Guests may book without an account; the Profile page's history
               is for those who made one. */
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->date('check_in');
            $table->date('check_out');
            $table->unsignedTinyInteger('guests')->default(2);

            $table->string('name', 160);
            $table->string('email', 190)->nullable();
            $table->string('whatsapp', 40)->nullable();
            $table->text('note')->nullable();

            $table->string('payment_method', 20)->default('cash');   // cash | digital
            $table->string('status', 20)->default('requested');      // requested|confirmed|cancelled|completed
            $table->string('locale', 5)->default('de');

            $table->unsignedInteger('nightly_rate')->default(0);
            $table->unsignedInteger('cleaning_fee')->default(0);
            $table->unsignedInteger('city_tax')->default(0);
            $table->unsignedInteger('vat')->default(0);
            $table->unsignedInteger('total')->default(0);

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            /* The query the availability check makes on every search. */
            $table->index(['apartment_id', 'check_in', 'check_out']);
            $table->index('status');
        });

        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('email', 190);
            $table->string('subject', 190)->default('');
            $table->text('message');
            $table->string('locale', 5)->default('de');
            $table->string('ip_hash', 64)->nullable();
            $table->boolean('is_handled')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index('is_handled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
        Schema::dropIfExists('bookings');
    }
};
