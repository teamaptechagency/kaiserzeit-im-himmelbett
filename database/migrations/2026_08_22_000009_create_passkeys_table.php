<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Passkeys — fingerprint, Windows Hello, Face ID.
 *
 * The private key never leaves the device; all this table holds is the public
 * half, which is useless to anyone who steals it. That is the whole appeal:
 * a database leak hands over nothing that can sign in.
 *
 * `device_token` is what makes the sign-in button appear on the laptop it was
 * set up on and nowhere else. It matches a long-lived cookie in that browser.
 * It is a convenience, not a control — an unknown device is refused by the
 * signature check, not by the missing cookie — but it keeps the login screen
 * from offering a method that would only fail.
 *
 * A note on the word "device": this is per browser profile, not per machine.
 * Chrome and Firefox on the same laptop hold separate cookies and separate
 * passkeys, and to this table they are two devices. That is how the platform
 * works, not a shortcut taken here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passkeys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /* Base64url of the raw credential id the authenticator generated.
               Unique across the table: one credential belongs to one account,
               and a collision would mean two accounts answering to one key. */
            $table->string('credential_id', 255)->unique();

            $table->text('public_key');                 // PEM
            $table->string('algorithm', 10);            // ES256 | RS256

            /* Moves forward on every use where the authenticator keeps one.
               A count that goes backwards means the credential was cloned. */
            $table->unsignedBigInteger('sign_count')->default(0);

            $table->string('aaguid', 40)->nullable();   // which make of authenticator
            $table->string('label', 80)->default('');   // "Nazmul's laptop"

            /* Which browser this was registered from; matches the kz_device
               cookie. Null once that cookie is lost, which costs the button
               and nothing else. */
            $table->string('device_token', 64)->nullable();
            $table->string('device_label', 120)->default('');

            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('device_token');
            $table->index(['user_id', 'last_used_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            /* The opaque handle the authenticator stores beside the key.
               Deliberately not the email or the row id: the browser hands
               this back before anyone has signed in, so it must give nothing
               away. */
            $table->string('webauthn_user_handle', 64)->nullable()->after('two_factor_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('webauthn_user_handle');
        });

        Schema::dropIfExists('passkeys');
    }
};
