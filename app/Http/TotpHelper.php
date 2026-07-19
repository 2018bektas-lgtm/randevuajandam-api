<?php

namespace App\Http;

/**
 * Minimal TOTP (RFC 6238) — no external package required.
 * Compatible with Google Authenticator / Authy secrets (Base32).
 */
class TotpHelper
{
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function otpauthUrl(string $company, string $email, string $secret): string
    {
        $label = rawurlencode($company.':'.$email);
        $issuer = rawurlencode($company);

        return "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";
    }

    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\s+/', '', (string) $code) ?? '';
        if ($code === '' || ! ctype_digit($code) || strlen($code) < 6) {
            return false;
        }
        $code = str_pad(substr($code, 0, 6), 6, '0', STR_PAD_LEFT);

        $timeSlice = (int) floor(time() / 30);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::at($secret, $timeSlice + $i), $code)) {
                return true;
            }
        }

        return false;
    }

    public static function at(string $secret, int $timeSlice): string
    {
        $secretKey = self::base32Decode($secret);
        if ($secretKey === '') {
            return '000000';
        }
        $time = pack('N*', 0, $timeSlice);
        $hash = hash_hmac('sha1', $time, $secretKey, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $truncated = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF)
        ) % 1000000;

        return str_pad((string) $truncated, 6, '0', STR_PAD_LEFT);
    }

    /**
     * @return list<string>
     */
    public static function recoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(self::randomChunk(4).'-'.self::randomChunk(4));
        }

        return $codes;
    }

    protected static function randomChunk(int $length): string
    {
        $pool = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        $max = strlen($pool) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= $pool[random_int(0, $max)];
        }

        return $out;
    }

    protected static function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binary = '';
        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $fiveBit = str_split($binary, 5);
        $base32 = '';
        foreach ($fiveBit as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $base32 .= $alphabet[bindec($chunk)];
        }

        return $base32;
    }

    protected static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32) ?? '');
        if ($b32 === '') {
            return '';
        }
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binary = '';
        foreach (str_split($b32) as $char) {
            $val = strpos($alphabet, $char);
            if ($val === false) {
                continue;
            }
            $binary .= str_pad(decbin($val), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($binary, 8) as $byte) {
            if (strlen($byte) === 8) {
                $bytes .= chr(bindec($byte));
            }
        }

        return $bytes;
    }
}
