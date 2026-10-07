<?php

declare(strict_types=1);

namespace Coleza\Domain\Identity\TwoFactor;

use InvalidArgumentException;

final class TotpEngine
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generate a cryptographically secure Base32 TOTP secret.
     */
    public function generateSecret(int $length = 32): string
    {
        $bytes = random_bytes($length);
        return $this->base32Encode($bytes);
    }

    /**
     * Generate a one-time code for a given secret at given timestamp (defaults to current time).
     */
    public function generateCode(string $secret, ?int $timestamp = null, int $period = 30, int $digits = 6): string
    {
        $time = $timestamp ?? time();
        $counter = (int) floor($time / $period);

        $secretBytes = $this->base32Decode($secret);
        $binaryCounter = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac('sha1', $binaryCounter, $secretBytes, true);

        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binaryCode = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );

        $otp = $binaryCode % (10 ** $digits);
        return str_pad((string) $otp, $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a code against the secret, with optional clock drift window (default 1 window = +/- 30 seconds).
     */
    public function verifyCode(string $secret, string $code, ?int $timestamp = null, int $window = 1, int $period = 30): bool
    {
        $time = $timestamp ?? time();
        $cleanCode = trim($code);

        for ($i = -$window; $i <= $window; $i++) {
            $checkTime = $time + ($i * $period);
            if (hash_equals($this->generateCode($secret, $checkTime, $period), $cleanCode)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate an otpauth:// URI for QR code generation in authenticator apps.
     */
    public function getProvisioningUri(string $issuer, string $accountName, string $secret): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            rawurlencode($issuer),
            rawurlencode($accountName),
            $secret,
            rawurlencode($issuer)
        );
    }

    private function base32Encode(string $data): string
    {
        if ($data === '') {
            return '';
        }

        $binary = '';
        $length = strlen($data);
        for ($i = 0; $i < $length; $i++) {
            $binary .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }

        $output = '';
        $chunks = str_split($binary, 5);
        foreach ($chunks as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $output .= self::BASE32_ALPHABET[bindec($chunk)];
        }

        return $output;
    }

    private function base32Decode(string $base32): string
    {
        $base32 = strtoupper(trim($base32));
        if ($base32 === '') {
            return '';
        }

        $binary = '';
        $length = strlen($base32);
        for ($i = 0; $i < $length; $i++) {
            $pos = strpos(self::BASE32_ALPHABET, $base32[$i]);
            if ($pos === false) {
                continue; // Ignore non-alphabet characters or padding
            }
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $output = '';
        $bytes = str_split($binary, 8);
        foreach ($bytes as $byte) {
            if (strlen($byte) === 8) {
                $output .= chr(bindec($byte));
            }
        }

        return $output;
    }
}
