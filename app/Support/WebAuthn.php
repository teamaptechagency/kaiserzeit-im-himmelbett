<?php

namespace App\Support;

use RuntimeException;

/**
 * WebAuthn — passkeys, in plain PHP.
 *
 * Fingerprint, Windows Hello and Face ID all speak this one protocol, so
 * there is nothing device-specific below: the browser decides which sensor to
 * put in front of the person, and what arrives here is the same signed blob
 * either way.
 *
 * Written out rather than pulled in as a dependency, for the same reason the
 * TOTP code is: this is what stands between the admin panel and everyone
 * else, and it should be readable in full. Ported from the implementation
 * already running on wpnazmul.com, which has the same job.
 *
 * Only what WebAuthn actually uses is implemented — definite-length CBOR, two
 * signature algorithms. Anything else is refused rather than guessed at: a
 * parser that improvises on malformed input is a parser an attacker writes
 * the input for.
 */
class WebAuthn
{
    /* ------------------------------------------------------- base64url --- */

    public static function b64uEncode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $text): string
    {
        $padded = strtr($text, '-_', '+/');
        $remainder = strlen($padded) % 4;

        if ($remainder) {
            $padded .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($padded, true);

        if ($decoded === false) {
            throw new RuntimeException('Malformed authenticator response.');
        }

        return $decoded;
    }

    /* ------------------------------------------------- relying party id --- */

    /**
     * The domain the passkey is bound to.
     *
     * A key made for one host will not sign for another, which is what makes
     * passkeys unphishable — and what makes them stop working the moment this
     * value is wrong. It comes from APP_URL, with the port stripped: the spec
     * takes a domain, and localhost:8123 is not one.
     */
    public static function rpId(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'localhost';
    }

    /**
     * Origins a ceremony may come from.
     *
     * Exact match, scheme and port included. The current request's own origin
     * is accepted alongside the configured one so that a site reached at
     * 127.0.0.1 during development does not have to have APP_URL rewritten
     * before anyone can test a passkey.
     */
    public static function origins(): array
    {
        $origins = [rtrim((string) config('app.url'), '/')];

        if ($request = request()) {
            $origins[] = $request->getSchemeAndHttpHost();
        }

        return array_values(array_unique(array_filter($origins)));
    }

    /** Whether passkeys can work here at all. */
    public static function possible(): bool
    {
        return self::unavailableReason() === null;
    }

    /**
     * Why passkeys cannot be used, or null when they can.
     *
     * The two things that stop them are worth naming rather than letting the
     * browser fail with its own wording. An IP address in particular is a
     * trap: WebAuthn takes a domain, browsers reject an IP outright, and the
     * error they give says nothing about why.
     */
    public static function unavailableReason(): ?string
    {
        if (! extension_loaded('openssl') || ! function_exists('openssl_verify')) {
            return 'This server has no OpenSSL, so it cannot check the signatures passkeys produce.';
        }

        $host = self::rpId();

        /* localhost is the one exception browsers make, and they make it for
           exactly this reason — so the thing can be developed at all. */
        if ($host !== 'localhost' && filter_var($host, FILTER_VALIDATE_IP)) {
            return 'Passkeys need a domain name. This site is being served from the IP address '
                . $host . ', and browsers refuse to bind a passkey to one. Set APP_URL to a '
                . 'hostname — localhost will do while developing.';
        }

        return null;
    }

    /* -------------------------------------------------------- challenge --- */

    /**
     * A fresh challenge, remembered in the session.
     *
     * One per ceremony and taken on use, so a captured response cannot be
     * replayed: the second attempt finds nothing to compare against.
     */
    public static function newChallenge(string $purpose): string
    {
        $challenge = self::b64uEncode(random_bytes(32));

        session()->put('webauthn.' . $purpose, [
            'challenge' => $challenge,
            'at'        => now()->timestamp,
        ]);

        return $challenge;
    }

    public static function takeChallenge(string $purpose): string
    {
        $stored = session()->pull('webauthn.' . $purpose);

        if (! is_array($stored) || empty($stored['challenge'])) {
            throw new RuntimeException('This request has expired. Please try again.');
        }

        /* Five minutes is long enough to find a finger and short enough that
           a challenge left in a closed laptop is no use to anyone. */
        if (now()->timestamp - (int) ($stored['at'] ?? 0) > 300) {
            throw new RuntimeException('That took too long. Please try again.');
        }

        return (string) $stored['challenge'];
    }

    /* -------------------------------------------------------------- CBOR --- */

    /**
     * Only the major types WebAuthn uses, and only definite lengths.
     *
     * $offset moves as it reads, so the caller can tell where a value ended —
     * which is how the COSE key at the tail of the authenticator data is
     * found.
     */
    public static function cborDecode(string $data, int &$offset = 0): mixed
    {
        if ($offset >= strlen($data)) {
            throw new RuntimeException('Malformed authenticator response.');
        }

        $byte  = ord($data[$offset++]);
        $major = $byte >> 5;
        $info  = $byte & 0x1f;

        $readUint = static function (int $info) use ($data, &$offset): int {
            if ($info < 24) {
                return $info;
            }

            $lengths = [24 => 1, 25 => 2, 26 => 4, 27 => 8];

            if (! isset($lengths[$info])) {
                throw new RuntimeException('Unsupported CBOR length.');
            }

            $n = $lengths[$info];

            if ($offset + $n > strlen($data)) {
                throw new RuntimeException('Truncated authenticator response.');
            }

            $chunk   = substr($data, $offset, $n);
            $offset += $n;
            $value   = 0;

            for ($i = 0; $i < $n; $i++) {
                $value = ($value << 8) | ord($chunk[$i]);
            }

            return $value;
        };

        switch ($major) {
            case 0:                                   // unsigned integer
                return $readUint($info);

            case 1:                                   // negative integer
                return -1 - $readUint($info);

            case 2:                                   // byte string
            case 3:                                   // text string
                $len = $readUint($info);

                if ($offset + $len > strlen($data)) {
                    throw new RuntimeException('Truncated authenticator response.');
                }

                $out     = substr($data, $offset, $len);
                $offset += $len;

                return $out;

            case 4:                                   // array
                $len = $readUint($info);
                $out = [];

                for ($i = 0; $i < $len; $i++) {
                    $out[] = self::cborDecode($data, $offset);
                }

                return $out;

            case 5:                                   // map
                $len = $readUint($info);
                $out = [];

                for ($i = 0; $i < $len; $i++) {
                    $key = self::cborDecode($data, $offset);
                    $out[is_int($key) ? $key : (string) $key] = self::cborDecode($data, $offset);
                }

                return $out;

            case 7:                                   // false / true / null
                if ($info === 20) {
                    return false;
                }
                if ($info === 21) {
                    return true;
                }
                if ($info === 22) {
                    return null;
                }
                throw new RuntimeException('Unsupported CBOR value.');
        }

        throw new RuntimeException('Unsupported CBOR type.');
    }

    /* ----------------------------------- DER, enough to build a key ------- */

    private static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function derTag(int $tag, string $value): string
    {
        return chr($tag) . self::derLength(strlen($value)) . $value;
    }

    /** A DER INTEGER. The leading zero matters: a high bit reads as negative. */
    private static function derUint(string $binary): string
    {
        $binary = ltrim($binary, "\x00");

        if ($binary === '') {
            $binary = "\x00";
        }

        if (ord($binary[0]) & 0x80) {
            $binary = "\x00" . $binary;
        }

        return self::derTag(0x02, $binary);
    }

    private static function derToPem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    /**
     * COSE key to PEM.
     *
     * Two algorithms, which between them cover every platform authenticator
     * in use: ES256 (Touch ID, Face ID, Android, most security keys) and
     * RS256 (Windows Hello on older TPMs). Anything else is refused rather
     * than half-supported.
     *
     * @return array{pem: string, alg: string}
     */
    public static function coseToPem(array $cose): array
    {
        $kty = $cose[1] ?? null;
        $alg = $cose[3] ?? null;

        if ($kty === 2 && $alg === -7) {                      // EC P-256
            if (($cose[-1] ?? null) !== 1) {
                throw new RuntimeException('Unsupported key curve.');
            }

            $x = (string) ($cose[-2] ?? '');
            $y = (string) ($cose[-3] ?? '');

            if (strlen($x) !== 32 || strlen($y) !== 32) {
                throw new RuntimeException('Malformed public key.');
            }

            /* SPKI header for id-ecPublicKey + prime256v1, then the
               uncompressed point. */
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200')
                . "\x04" . $x . $y;

            return ['pem' => self::derToPem($der), 'alg' => 'ES256'];
        }

        if ($kty === 3 && $alg === -257) {                    // RSA
            $n        = (string) ($cose[-1] ?? '');
            $exponent = (string) ($cose[-2] ?? '');

            if ($n === '' || $exponent === '') {
                throw new RuntimeException('Malformed public key.');
            }

            $rsa  = self::derTag(0x30, self::derUint($n) . self::derUint($exponent));
            $algo = self::derTag(0x30, hex2bin('06092a864886f70d010101') . hex2bin('0500'));
            $der  = self::derTag(0x30, $algo . self::derTag(0x03, "\x00" . $rsa));

            return ['pem' => self::derToPem($der), 'alg' => 'RS256'];
        }

        throw new RuntimeException('This device uses a signature type the site does not support.');
    }

    /* ------------------------------------------- authenticator data ------- */

    /**
     * rpIdHash(32) | flags(1) | signCount(4) | [ aaguid(16) credIdLen(2)
     * credId attestedCredentialPublicKey ]
     *
     * The bracketed part is present only when the AT flag is set, which is to
     * say on registration.
     */
    public static function parseAuthData(string $authData): array
    {
        if (strlen($authData) < 37) {
            throw new RuntimeException('Malformed authenticator data.');
        }

        $flags = ord($authData[32]);

        $out = [
            'rp_id_hash'    => substr($authData, 0, 32),
            'user_present'  => (bool) ($flags & 0x01),
            'user_verified' => (bool) ($flags & 0x04),
            'has_attested'  => (bool) ($flags & 0x40),
            'sign_count'    => unpack('N', substr($authData, 33, 4))[1],
        ];

        if ($out['has_attested']) {
            if (strlen($authData) < 55) {
                throw new RuntimeException('Malformed attested credential data.');
            }

            $out['aaguid'] = bin2hex(substr($authData, 37, 16));
            $credLength    = unpack('n', substr($authData, 53, 2))[1];

            if (strlen($authData) < 55 + $credLength) {
                throw new RuntimeException('Malformed attested credential data.');
            }

            $out['credential_id'] = substr($authData, 55, $credLength);

            $offset = 55 + $credLength;
            $cose   = self::cborDecode($authData, $offset);

            if (! is_array($cose)) {
                throw new RuntimeException('Malformed public key.');
            }

            $out['cose'] = $cose;
        }

        return $out;
    }

    /**
     * The checks that are identical on registration and on sign-in: this is
     * the ceremony we started, for the challenge we issued, from an origin we
     * recognise.
     */
    private static function checkClientData(string $clientDataJson, string $expectedType, string $expectedChallenge): void
    {
        $data = json_decode($clientDataJson, true);

        if (! is_array($data)) {
            throw new RuntimeException('Malformed sign-in data.');
        }

        if (($data['type'] ?? '') !== $expectedType) {
            throw new RuntimeException('Unexpected sign-in type.');
        }

        /* hash_equals, not ===: this compares a secret we issued. */
        if (! hash_equals($expectedChallenge, (string) ($data['challenge'] ?? ''))) {
            throw new RuntimeException('This sign-in request is no longer valid. Please try again.');
        }

        if (! in_array((string) ($data['origin'] ?? ''), self::origins(), true)) {
            throw new RuntimeException('This sign-in came from an unexpected address.');
        }
    }

    /* --------------------------------------------------- registration ----- */

    /**
     * @return array{credential_id: string, public_key: string, algorithm: string, sign_count: int, aaguid: ?string}
     */
    public static function verifyRegistration(string $clientDataB64, string $attestationB64, string $expectedChallenge): array
    {
        $clientDataJson = self::b64uDecode($clientDataB64);
        self::checkClientData($clientDataJson, 'webauthn.create', $expectedChallenge);

        $offset      = 0;
        $attestation = self::cborDecode(self::b64uDecode($attestationB64), $offset);

        if (! is_array($attestation) || ! isset($attestation['authData'])) {
            throw new RuntimeException('Malformed registration response.');
        }

        $auth = self::parseAuthData((string) $attestation['authData']);

        if (! hash_equals(hash('sha256', self::rpId(), true), $auth['rp_id_hash'])) {
            throw new RuntimeException('This passkey belongs to a different website.');
        }

        if (! $auth['user_present']) {
            throw new RuntimeException('The device did not confirm you were there.');
        }

        /* userVerification is asked for as "required", so a device that only
           proved presence — a tap with no fingerprint, face or PIN — is not
           what was asked for. */
        if (! $auth['user_verified']) {
            throw new RuntimeException('Set up a fingerprint, face or PIN on this device first.');
        }

        if (! $auth['has_attested'] || ! isset($auth['cose'])) {
            throw new RuntimeException('The device sent no public key.');
        }

        $key = self::coseToPem($auth['cose']);

        return [
            'credential_id' => self::b64uEncode($auth['credential_id']),
            'public_key'    => $key['pem'],
            'algorithm'     => $key['alg'],
            'sign_count'    => $auth['sign_count'],
            'aaguid'        => $auth['aaguid'] ?? null,
        ];
    }

    /* -------------------------------------------------------- signing in -- */

    /**
     * Checks one assertion against one stored credential.
     *
     * Returns the device's new signature counter; throws on any failure.
     */
    public static function verifyAssertion(
        array $credential,
        string $clientDataB64,
        string $authDataB64,
        string $signatureB64,
        string $expectedChallenge,
    ): int {
        $clientDataJson = self::b64uDecode($clientDataB64);
        self::checkClientData($clientDataJson, 'webauthn.get', $expectedChallenge);

        $authDataRaw = self::b64uDecode($authDataB64);
        $auth        = self::parseAuthData($authDataRaw);

        if (! hash_equals(hash('sha256', self::rpId(), true), $auth['rp_id_hash'])) {
            throw new RuntimeException('This passkey belongs to a different website.');
        }

        if (! $auth['user_present'] || ! $auth['user_verified']) {
            throw new RuntimeException('The device did not verify who you are.');
        }

        /* The signed message is the raw authenticator data followed by the
           hash of the client data — concatenated, not hashed together. */
        $signed = $authDataRaw . hash('sha256', $clientDataJson, true);

        $key = openssl_pkey_get_public((string) $credential['public_key']);

        if ($key === false) {
            throw new RuntimeException('This passkey is stored incorrectly. Remove it and register again.');
        }

        if (openssl_verify($signed, self::b64uDecode($signatureB64), $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('That did not match. Please try again.');
        }

        /* A counter that has not moved forward means the credential was copied
           off the device it was made on. Many platform authenticators
           legitimately report 0 forever, so a pair of zeroes proves nothing
           either way. */
        $stored = (int) $credential['sign_count'];
        $fresh  = (int) $auth['sign_count'];

        if ($stored > 0 && $fresh > 0 && $fresh <= $stored) {
            throw new RuntimeException('This passkey looks like a copy. It has been refused.');
        }

        return $fresh;
    }
}
