<?php

namespace App\Models\Helper;

/**
 * Time-based one-time passwords, RFC 6238 (which builds on RFC 4226 HOTP).
 *
 * Written out rather than pulled in as a package: the algorithm is small and
 * stable, and this avoids adding a dependency for ~80 lines of HMAC.
 * Verified against the RFC 6238 appendix B test vectors.
 */
class TotpHelper
{
    /** Seconds per code. The value every authenticator app assumes. */
    public const PERIOD = 30;

    /** Digits in a code. */
    public const DIGITS = 6;

    /**
     * How many periods either side of "now" we accept, to allow for clock
     * drift between the phone and the server. 1 => a 90-second window.
     */
    public const WINDOW = 1;

    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * A fresh base32 secret. 20 bytes = 160 bits, the RFC 4226 recommendation.
     */
    public static function generateSecret($bytes = 20)
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /**
     * The URI an authenticator app expects behind a QR code.
     */
    public static function provisioningUri($secret, $account, $issuer)
    {
        return 'otpauth://totp/'
            . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?' . http_build_query([
                'secret' => $secret,
                'issuer' => $issuer,
                'algorithm' => 'SHA1',
                'digits' => self::DIGITS,
                'period' => self::PERIOD
            ]);
    }

    /**
     * The code for a given moment. $timestamp defaults to now.
     */
    public static function codeAt($secret, $timestamp = null)
    {
        $counter = intdiv($timestamp ?? time(), self::PERIOD);

        return self::hotp($secret, $counter);
    }

    /**
     * Does $code match, allowing for a little clock drift either way?
     */
    public static function verify($secret, $code, $timestamp = null)
    {
        $code = preg_replace('/\D/', '', (string)$code);

        if (strlen($code) !== self::DIGITS) {
            return false;
        }

        $counter = intdiv($timestamp ?? time(), self::PERIOD);

        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            // hash_equals, not ==, so a wrong code always costs the same time.
            if (hash_equals(self::hotp($secret, $counter + $i), $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * RFC 4226 HOTP: HMAC-SHA1 of the counter, dynamically truncated.
     */
    private static function hotp($secret, $counter)
    {
        $key = self::base32Decode($secret);

        // 8-byte big-endian counter.
        $binCounter = pack('N*', 0, $counter);

        $hash = hash_hmac('sha1', $binCounter, $key, true);

        $offset = ord($hash[19]) & 0xf;

        $value = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        return str_pad($value % (10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function base32Encode($binary)
    {
        if ($binary === '') {
            return '';
        }

        $bits = '';

        foreach (str_split($binary) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    public static function base32Decode($secret)
    {
        $secret = strtoupper(str_replace([' ', '=', '-'], '', (string)$secret));

        if ($secret === '') {
            return '';
        }

        $bits = '';

        foreach (str_split($secret) as $char) {

            $index = strpos(self::BASE32_ALPHABET, $char);

            if ($index === false) {
                continue;
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $binary = '';

        // Trailing bits that don't fill a byte are padding; drop them.
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $binary .= chr(bindec($byte));
            }
        }

        return $binary;
    }

    /**
     * One-use codes for when the phone is lost. Stored hashed, shown once.
     */
    public static function generateRecoveryCodes($count = 8)
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)));
        }

        return $codes;
    }
}
