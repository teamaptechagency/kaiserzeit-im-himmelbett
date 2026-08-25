<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A username to sign in with, and a mobile number.
 *
 * Signing in now takes either the username or the email address in one field.
 * Two fields, or a chooser, would only give somebody a way to pick wrongly —
 * the server can tell which of the two it has been handed.
 *
 * Usernames are stored folded to lower case. "Nazmul" and "nazmul" must not be
 * two accounts, and a unique index over a case-sensitive column would let them
 * be — so the folding happens on the way in rather than at every comparison.
 *
 * Existing accounts are given one derived from their email, because a column
 * that is unique and required cannot be added to a table that already has rows
 * in it without answering what those rows should hold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 30)->nullable()->after('name');
            $table->string('phone', 40)->nullable()->after('whatsapp');
        });

        $this->backfill();

        Schema::table('users', function (Blueprint $table) {
            $table->unique('username');
        });
    }

    /**
     * Gives every existing account a username from its email.
     *
     * nazmulha30@gmail.com becomes nazmulha30. Where two addresses would
     * collide on the same local part, a number is appended — the alternative
     * is a failed migration on somebody else's data.
     */
    private function backfill(): void
    {
        $taken = [];

        foreach (DB::table('users')->select('id', 'email', 'name')->orderBy('id')->get() as $user) {
            $base = Str::of((string) $user->email)
                ->before('@')
                ->lower()
                ->replaceMatches('/[^a-z0-9._-]/', '')
                ->limit(24, '')
                ->toString();

            if (strlen($base) < 3) {
                $base = Str::of((string) $user->name)->slug('')->lower()->limit(24, '')->toString();
            }

            if (strlen($base) < 3) {
                $base = 'user' . $user->id;
            }

            $username = $base;
            $suffix   = 1;

            while (isset($taken[$username])) {
                $username = $base . ++$suffix;
            }

            $taken[$username] = true;

            DB::table('users')->where('id', $user->id)->update(['username' => $username]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'phone']);
        });
    }
};
