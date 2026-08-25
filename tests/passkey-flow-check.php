<?php

/*
 * Drives the whole passkey flow over HTTP, against a running server.
 *
 * A fingerprint reader cannot be scripted, so the authenticator is simulated:
 * a real P-256 key, and payloads assembled in the exact shape a device would
 * send. Everything else is genuine — real requests, real sessions, real
 * cookies, real verification.
 *
 * What this is really checking is the requirement that the sign-in button
 * appears on the browser that registered a passkey and on no other. That is
 * two browsers and two cookie jars, which is why it lives here rather than in
 * the unit check beside it.
 *
 *   php tests/passkey-flow-check.php http://localhost:8123
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Support\WebAuthn;

$base = rtrim($argv[1] ?? 'http://localhost:8123', '/');
$rpId = parse_url($base, PHP_URL_HOST);

$pass = 0;
$fail = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  %-4s %-52s %s\n", $ok ? 'ok' : 'FAIL', $what, $detail);
}

/* --------------------------------------------------------------- http --- */

function request(string $jar, string $method, string $url, mixed $body = null, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
    }

    $out    = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => $status, 'body' => (string) $out];
}

function csrf(string $html): string
{
    preg_match('/name="csrf-token" content="([^"]+)"/', $html, $meta);
    if ($meta) {
        return $meta[1];
    }
    preg_match('/name="_token" value="([^"]+)"/', $html, $field);

    return $field[1] ?? '';
}

/* ------------------------------------------------------- CBOR encoding --- */

function cbor_uint(int $major, int $value): string
{
    if ($value < 24)      return chr(($major << 5) | $value);
    if ($value < 0x100)   return chr(($major << 5) | 24) . chr($value);
    if ($value < 0x10000) return chr(($major << 5) | 25) . pack('n', $value);
    return chr(($major << 5) | 26) . pack('N', $value);
}

function cbor_bytes(string $b): string { return cbor_uint(2, strlen($b)) . $b; }
function cbor_text(string $t): string  { return cbor_uint(3, strlen($t)) . $t; }
function cbor_int(int $v): string      { return $v >= 0 ? cbor_uint(0, $v) : cbor_uint(1, -1 - $v); }

function cbor_map(array $pairs): string
{
    $out = cbor_uint(5, count($pairs));
    foreach ($pairs as [$k, $v]) $out .= $k . $v;
    return $out;
}

/* ------------------------------------------------- the simulated device -- */

$key     = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
$details = openssl_pkey_get_details($key);
$x       = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
$y       = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);

$cose = cbor_map([
    [cbor_int(1),  cbor_int(2)],
    [cbor_int(3),  cbor_int(-7)],
    [cbor_int(-1), cbor_int(1)],
    [cbor_int(-2), cbor_bytes($x)],
    [cbor_int(-3), cbor_bytes($y)],
]);

$credentialId = random_bytes(32);
$counter      = 0;

echo "server {$base}   rpId {$rpId}\n\n";

/* ------------------------------------------- 1. sign in with a password -- */

$laptop = tempnam(sys_get_temp_dir(), 'kzlaptop');
$phone  = tempnam(sys_get_temp_dir(), 'kzphone');

echo "the laptop registers a passkey\n";

$login = request($laptop, 'GET', $base . '/login');
$token = csrf($login['body']);

check('the login page has no passkey button yet',
    ! str_contains($login['body'], 'kz-passkey-signin'));

$signIn = request($laptop, 'POST', $base . '/login', http_build_query([
    '_token'   => $token,
    'email'    => 'nazmulha30@gmail.com',
    'password' => 'kaiserzeit-preview-2026',
]), ['content-type: application/x-www-form-urlencoded']);

check('password sign-in works', $signIn['status'] === 302, 'status ' . $signIn['status']);

/* -------------------------------------------------- 2. register the key -- */

$security = request($laptop, 'GET', $base . '/admin/security');
$token    = csrf($security['body']);

$options = request($laptop, 'POST', $base . '/passkey/register/options', '{}', [
    'content-type: application/json',
    'x-csrf-token: ' . $token,
]);

check('the server issues creation options', $options['status'] === 200, 'status ' . $options['status']);

$create    = json_decode($options['body'], true);
$challenge = $create['challenge'] ?? '';

$authData = hash('sha256', $rpId, true)
    . chr(0x01 | 0x04 | 0x40)
    . pack('N', $counter)
    . str_repeat("\x00", 16)
    . pack('n', strlen($credentialId))
    . $credentialId
    . $cose;

$attestation = cbor_map([
    [cbor_text('fmt'),      cbor_text('none')],
    [cbor_text('attStmt'),  cbor_map([])],
    [cbor_text('authData'), cbor_bytes($authData)],
]);

$clientData = json_encode([
    'type'      => 'webauthn.create',
    'challenge' => $challenge,
    'origin'    => $base,
]);

$registered = request($laptop, 'POST', $base . '/passkey/register', [
    'clientDataJSON'    => WebAuthn::b64uEncode($clientData),
    'attestationObject' => WebAuthn::b64uEncode($attestation),
    'label'             => 'Test laptop',
], ['content-type: application/json', 'x-csrf-token: ' . $token]);

check('the passkey is accepted and stored', $registered['status'] === 200,
    $registered['status'] . ' ' . substr($registered['body'], 0, 90));

check('the browser is marked as a registered device',
    str_contains(file_get_contents($laptop), 'kz_device'));

/* -------------------------------------------- 3. sign out, then look again */

echo "\nthe laptop signs in with the passkey\n";

$page  = request($laptop, 'GET', $base . '/admin/security');
$token = csrf($page['body']);
request($laptop, 'POST', $base . '/logout', http_build_query(['_token' => $token]),
    ['content-type: application/x-www-form-urlencoded']);

$login = request($laptop, 'GET', $base . '/login');

check('the button now appears on this browser',
    str_contains($login['body'], 'kz-passkey-signin'));

$token   = csrf($login['body']);
$options = request($laptop, 'POST', $base . '/login/passkey/options', '{}', [
    'content-type: application/json', 'x-csrf-token: ' . $token,
]);

check('the server offers this device its credentials', $options['status'] === 200,
    'status ' . $options['status']);

$get       = json_decode($options['body'], true);
$challenge = $get['challenge'] ?? '';

check('only this device credential is offered',
    count($get['allowCredentials'] ?? []) === 1
    && $get['allowCredentials'][0]['id'] === WebAuthn::b64uEncode($credentialId));

$counter++;
$assertAuthData = hash('sha256', $rpId, true) . chr(0x01 | 0x04) . pack('N', $counter);
$assertClient   = json_encode([
    'type'      => 'webauthn.get',
    'challenge' => $challenge,
    'origin'    => $base,
]);

openssl_sign($assertAuthData . hash('sha256', $assertClient, true), $signature, $key, OPENSSL_ALGO_SHA256);

$signedIn = request($laptop, 'POST', $base . '/login/passkey', [
    'credentialId'      => WebAuthn::b64uEncode($credentialId),
    'clientDataJSON'    => WebAuthn::b64uEncode($assertClient),
    'authenticatorData' => WebAuthn::b64uEncode($assertAuthData),
    'signature'         => WebAuthn::b64uEncode($signature),
], ['content-type: application/json', 'x-csrf-token: ' . $token]);

check('signing in with the passkey works', $signedIn['status'] === 200,
    $signedIn['status'] . ' ' . substr($signedIn['body'], 0, 90));

$panel = request($laptop, 'GET', $base . '/admin');
check('and it lands in the panel', $panel['status'] === 200, 'status ' . $panel['status']);

/* --------------------------------------------- 4. a browser that has none */

echo "\nan unregistered browser\n";

$other = request($phone, 'GET', $base . '/login');

check('the button is absent', ! str_contains($other['body'], 'kz-passkey-signin'));

$token   = csrf($other['body']);
$refused = request($phone, 'POST', $base . '/login/passkey/options', '{}', [
    'content-type: application/json', 'x-csrf-token: ' . $token,
]);

check('and the server offers it nothing', $refused['status'] === 404,
    'status ' . $refused['status']);

/* Even holding the credential id, without the device cookie it gets nowhere. */
$stolen = request($phone, 'POST', $base . '/login/passkey', [
    'credentialId'      => WebAuthn::b64uEncode($credentialId),
    'clientDataJSON'    => WebAuthn::b64uEncode($assertClient),
    'authenticatorData' => WebAuthn::b64uEncode($assertAuthData),
    'signature'         => WebAuthn::b64uEncode($signature),
], ['content-type: application/json', 'x-csrf-token: ' . $token]);

check('a captured response replayed elsewhere is refused',
    $stolen['status'] >= 400, 'status ' . $stolen['status']);

@unlink($laptop);
@unlink($phone);

echo "\npassed {$pass}, failed {$fail}\n";
exit($fail === 0 ? 0 : 1);
