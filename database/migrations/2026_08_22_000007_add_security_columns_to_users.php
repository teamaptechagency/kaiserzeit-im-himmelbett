<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-factor authentication, and the things a security screen has to show.
 *
 * The secret and the recovery codes are encrypted at rest, so a leaked
 * database backup does not hand over the second factor along with the first.
 * That is the whole point of the second factor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            /* Set only once a code has been entered correctly. An enrolment
               that was started and abandoned must not lock anyone out. */
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');

            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip_hash', 64)->nullable();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('login_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('email', 190);
            $table->string('ip_hash', 64);
            $table->string('user_agent', 255)->nullable();
            $table->boolean('successful')->default(false);
            $table->string('reason', 40)->nullable();      // bad-password | no-user | inactive | bad-2fa
            $table->timestamp('created_at')->useCurrent();

            $table->index(['email', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_attempts');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at',
                'last_login_at', 'last_login_ip_hash', 'is_active',
            ]);
        });
    }
};
