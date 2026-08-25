<?php

/*
 * Exercises the passkey verification path with real cryptography.
 *
 * A fingerprint reader cannot be scripted, so this stands in for one: it
 * generates a P-256 key, assembles exactly what an authenticator would send
 * for a registration and then for a sign-in, and hands both to the server's
 * own verification code.
 *
 * That covers the parts worth being sure about — the CBOR reader, the COSE to
 * PEM conversion, the authenticator-data layout and the signature check. The
 * browser half is standard API usage on top.
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\WebAuthn;

$pass = 0;
$fail = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  %-4s %-52s %s\n", $ok ? 'ok' : 'FAIL', $what, $detail);
}

/* ------------------------------------------------------- a CBOR encoder --
   Only what the test needs to build an attestation object. The server has
   the decoder; this is its mirror, written here so the decoder is checked
   against something independent of itself. */

function cbor_uint(int $major, int $value): string
{
    if ($value < 24)         return chr(($major << 5) | $value);
    if ($value < 0x100)      return chr(($major << 5) | 24) . chr($value);
    if ($value < 0x10000)    return chr(($major << 5) | 25) . pack('n', $value);
    return chr(($major << 5) | 26) . pack('N', $value);
}

function cbor_bytes(string $bytes): string  { return cbor_uint(2, strlen($bytes)) . $bytes; }
function cbor_text(string $text): string    { return cbor_uint(3, strlen($text)) . $text; }
function cbor_int(int $value): string       { return $value >= 0 ? cbor_uint(0, $value) : cbor_uint(1, -1 - $value); }

/** @param array<array{0:string,1:string}> $pairs already-encoded key/value */
function cbor_map(array $pairs): string
{
    $out = cbor_uint(5, count($pairs));
    foreach ($pairs as [$key, $value]) $out .= $key . $value;
    return $out;
}

/* --------------------------------------------------------- the "device" -- */

$rpId      = WebAuthn::rpId();
$origin    = 'http://' . $rpId . ':8123';
$challenge = WebAuthn::b64uEncode(random_bytes(32));

echo "rpId: {$rpId}   origin: {$origin}\n\n";

/* A real P-256 key pair, as a platform authenticator would generate. */
$key = openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'curve_name'       => 'prime256v1',
]);
$details = openssl_pkey_get_details($key);
$x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
$y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);

/* The COSE form of that public key: kty=EC2, alg=ES256, crv=P-256, x, y */
$cose = cbor_map([
    [cbor_int(1),  cbor_int(2)],
    [cbor_int(3),  cbor_int(-7)],
    [cbor_int(-1), cbor_int(1)],
    [cbor_int(-2), cbor_bytes($x)],
    [cbor_int(-3), cbor_bytes($y)],
]);

$credentialId = random_bytes(32);

/* authData for a registration: UP | UV | AT set, and the attested data. */
$authDataCreate = hash('sha256', $rpId, true)
    . chr(0x01 | 0x04 | 0x40)
    . pack('N', 0)
    . str_repeat("\x00", 16)
    . pack('n', strlen($credentialId))
    . $credentialId
    . $cose;

$attestation = cbor_map([
    [cbor_text('fmt'),      cbor_text('none')],
    [cbor_text('attStmt'),  cbor_map([])],
    [cbor_text('authData'), cbor_bytes($authDataCreate)],
]);

$clientDataCreate = json_encode([
    'type'      => 'webauthn.create',
    'challenge' => $challenge,
    'origin'    => $origin,
]);

/* ------------------------------------------------------- registration --- */

echo "registration\n";

$stored = null;
try {
    $stored = WebAuthn::verifyRegistration(
        WebAuthn::b64uEncode($clientDataCreate),
        WebAuthn::b64uEncode($attestation),
        $challenge,
    );
    check('a genuine registration is accepted', true);
    check('credential id round-trips', $stored['credential_id'] === WebAuthn::b64uEncode($credentialId));
    check('algorithm recognised as ES256', $stored['algorithm'] === 'ES256');
    check('COSE key became a usable PEM', openssl_pkey_get_public($stored['public_key']) !== false);
} catch (Throwable $e) {
    check('a genuine registration is accepted', false, $e->getMessage());
}

/* The checks that should refuse. */
foreach ([
    'a different challenge is refused' => [$clientDataCreate, $attestation, WebAuthn::b64uEncode(random_bytes(32))],
    'a foreign origin is refused' => [
        json_encode(['type' => 'webauthn.create', 'challenge' => $challenge, 'origin' => 'https://evil.example']),
        $attestation, $challenge,
    ],
    'the wrong ceremony type is refused' => [
        json_encode(['type' => 'webauthn.get', 'challenge' => $challenge, 'origin' => $origin]),
        $attestation, $challenge,
    ],
] as $what => [$clientData, $att, $chal]) {
    try {
        WebAuthn::verifyRegistration(WebAuthn::b64uEncode($clientData), WebAuthn::b64uEncode($att), $chal);
        check($what, false, 'it was ACCEPTED');
    } catch (Throwable $e) {
        check($what, true);
    }
}

/* A device that only proved presence, with no fingerprint or PIN. */
$noUv = hash('sha256', $rpId, true) . chr(0x01 | 0x40) . pack('N', 0)
    . str_repeat("\x00", 16) . pack('n', strlen($credentialId)) . $credentialId . $cose;
try {
    WebAuthn::verifyRegistration(
        WebAuthn::b64uEncode($clientDataCreate),
        WebAuthn::b64uEncode(cbor_map([
            [cbor_text('fmt'), cbor_text('none')],
            [cbor_text('attStmt'), cbor_map([])],
            [cbor_text('authData'), cbor_bytes($noUv)],
        ])),
        $challenge,
    );
    check('a tap with no fingerprint or PIN is refused', false, 'it was ACCEPTED');
} catch (Throwable $e) {
    check('a tap with no fingerprint or PIN is refused', true);
}

/* ----------------------------------------------------------- signing in -- */

echo "\nsign-in\n";

$loginChallenge = WebAuthn::b64uEncode(random_bytes(32));
$clientDataGet  = json_encode([
    'type'      => 'webauthn.get',
    'challenge' => $loginChallenge,
    'origin'    => $origin,
]);

/* authData for an assertion: UP | UV, no attested data, counter moved on. */
$authDataGet = hash('sha256', $rpId, true) . chr(0x01 | 0x04) . pack('N', 5);

openssl_sign($authDataGet . hash('sha256', $clientDataGet, true), $signature, $key, OPENSSL_ALGO_SHA256);

$credential = ['public_key' => $stored['public_key'] ?? '', 'sign_count' => 0];

try {
    $count = WebAuthn::verifyAssertion(
        $credential,
        WebAuthn::b64uEncode($clientDataGet),
        WebAuthn::b64uEncode($authDataGet),
        WebAuthn::b64uEncode($signature),
        $loginChallenge,
    );
    check('a genuine signature is accepted', true);
    check('the counter comes back as the device sent it', $count === 5, "got {$count}");
} catch (Throwable $e) {
    check('a genuine signature is accepted', false, $e->getMessage());
}

/* Someone else's key. */
$other = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
openssl_sign($authDataGet . hash('sha256', $clientDataGet, true), $wrongSig, $other, OPENSSL_ALGO_SHA256);
try {
    WebAuthn::verifyAssertion($credential, WebAuthn::b64uEncode($clientDataGet),
        WebAuthn::b64uEncode($authDataGet), WebAuthn::b64uEncode($wrongSig), $loginChallenge);
    check('a signature from another key is refused', false, 'it was ACCEPTED');
} catch (Throwable $e) {
    check('a signature from another key is refused', true);
}

/* A replay: the same signature against a challenge we did not issue. */
try {
    WebAuthn::verifyAssertion($credential, WebAuthn::b64uEncode($clientDataGet),
        WebAuthn::b64uEncode($authDataGet), WebAuthn::b64uEncode($signature),
        WebAuthn::b64uEncode(random_bytes(32)));
    check('a replayed response is refused', false, 'it was ACCEPTED');
} catch (Throwable $e) {
    check('a replayed response is refused', true);
}

/* A cloned credential: the counter did not move forward. */
try {
    WebAuthn::verifyAssertion(
        ['public_key' => $stored['public_key'], 'sign_count' => 9],
        WebAuthn::b64uEncode($clientDataGet), WebAuthn::b64uEncode($authDataGet),
        WebAuthn::b64uEncode($signature), $loginChallenge,
    );
    check('a counter that went backwards is refused', false, 'it was ACCEPTED');
} catch (Throwable $e) {
    check('a counter that went backwards is refused', true);
}

/* A key made for another site. */
$foreign = hash('sha256', 'evil.example', true) . chr(0x01 | 0x04) . pack('N', 6);
openssl_sign($foreign . hash('sha256', $clientDataGet, true), $foreignSig, $key, OPENSSL_ALGO_SHA256);
try {
    WebAuthn::verifyAssertion($credential, WebAuthn::b64uEncode($clientDataGet),
        WebAuthn::b64uEncode($foreign), WebAuthn::b64uEncode($foreignSig), $loginChallenge);
    check('a passkey bound to another domain is refused', false, 'it was ACCEPTED');
} catch (Throwable $e) {
    check('a passkey bound to another domain is refused', true);
}

echo "\npassed {$pass}, failed {$fail}\n";
exit($fail === 0 ? 0 : 1);
