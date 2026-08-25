<?php

namespace App\Support;

/**
 * Time-based one-time passwords, RFC 6238.
 *
 * Written out rather than pulled in as a dependency: the algorithm is a
 * base32 decode, an HMAC and a truncation, it has not changed since 2011, and
 * a second-factor implementation is worth being able to read in full on the
 * page where it is used.
 *
 * Compatible with Google Authenticator, 1Password, Aegis and anything else
 * that reads an otpauth:// URI — SHA-1, six digits, thirty-second steps. Those
 * three are not chosen, they are what every authenticator app assumes when the
 * URI does not say otherwise, and saying otherwise breaks half of them.
 */
class TwoFactor
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const DIGITS   = 6;
    private const PERIOD   = 30;

    /** A fresh 160-bit secret, base32 encoded. */
    public static function secret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /**
     * Is this the code the app is showing?
     *
     * A window of one step either side is allowed, because the phone's clock
     * and the server's are never exactly aligned and a code typed at the last
     * second arrives after it has rolled over. One step is 30 seconds of
     * tolerance, which is the usual trade between a code that fails for no
     * visible reason and a window wide enough to be worth attacking.
     */
    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== self::DIGITS) {
            return false;
        }

        $counter = (int) floor(time() / self::PERIOD);

        for ($offset = -$window; $offset <= $window; $offset++) {
            /* hash_equals, not ===: a comparison that returns early tells an
               attacker how much of the code was right. */
            if (hash_equals(self::at($secret, $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    /** The code for one time step. */
    public static function at(string $secret, int $counter): string
    {
        $key = self::base32Decode($secret);

        if ($key === '') {
            return '';
        }

        $hash = hash_hmac('sha1', pack('N*', 0, $counter), $key, true);

        /* Dynamic truncation: the low nibble of the last byte says where in
           the digest to read the four bytes that become the code. */
        $offset = ord($hash[19]) & 0x0f;
        $value  = ((ord($hash[$offset]) & 0x7f) << 24)
                | ((ord($hash[$offset + 1]) & 0xff) << 16)
                | ((ord($hash[$offset + 2]) & 0xff) << 8)
                | (ord($hash[$offset + 3]) & 0xff);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** What the QR code encodes, and what can be pasted in by hand. */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'
            . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?' . http_build_query([
                'secret' => $secret,
                'issuer' => $issuer,
                'algorithm' => 'SHA1',
                'digits' => self::DIGITS,
                'period' => self::PERIOD,
            ]);
    }

    /** The secret in groups of four, which is how it gets typed in. */
    public static function readable(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    /**
     * Eight one-use codes for the day the phone is lost.
     *
     * Without them, losing the phone means losing the site — and the usual
     * way out of that is a support process this site does not have.
     */
    public static function recoveryCodes(int $count = 8): array
    {
        return collect(range(1, $count))
            ->map(fn () => strtoupper(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2))))
            ->all();
    }

    /* --------------------------------------------------------------- base32 */

    private static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    private static function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret) ?? '');

        if ($secret === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($secret) as $char) {
            $index = strpos(self::ALPHABET, $char);
            if ($index === false) {
                return '';
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr(bindec($chunk));
            }
        }

        return $out;
    }
}
