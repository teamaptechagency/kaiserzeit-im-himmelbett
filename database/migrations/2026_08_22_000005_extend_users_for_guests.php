<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The users table now holds two kinds of person.
 *
 * The design's Booking page has sign-in and sign-up, and its Profile page has
 * a booking history and invoices — so guests get accounts, not only the
 * people who run the site. `role` is what keeps a guest out of the admin
 * panel and out of the editor.
 *
 * Existing rows are administrators: the only account before this migration
 * was the one the installer made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('guest')->after('email');
            $table->string('whatsapp', 40)->nullable()->after('role');
            $table->string('locale', 5)->default('de')->after('whatsapp');
            $table->index('role');
        });

        /* Whoever was here before this ran was created by the installer. */
        \Illuminate\Support\Facades\DB::table('users')->update(['role' => 'admin']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn(['role', 'whatsapp', 'locale']);
        });
    }
};
