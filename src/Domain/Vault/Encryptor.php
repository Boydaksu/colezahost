<?php

declare(strict_types=1);

namespace Coleza\Domain\Vault;

use InvalidArgumentException;
use RuntimeException;

final class Encryptor
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;
    private const IV_LENGTH = 12;

    private string $key;

    public function __construct(string $key)
    {
        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }

        if (strlen($key) !== 32) {
            throw new InvalidArgumentException('Encryption key must be exactly 32 bytes (256-bit).');
        }

        $this->key = $key;
    }

    /**
     * Encrypt plaintext string using AES-256-GCM.
     * Returns base64 payload containing IV + Tag + Ciphertext.
     */
    public function encrypt(string $plainText): string
    {
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $cipherText = openssl_encrypt(
            $plainText,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($cipherText === false) {
            throw new RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        return base64_encode($iv . $tag . $cipherText);
    }

    /**
     * Decrypt base64 AES-256-GCM payload.
     */
    public function decrypt(string $payload): string
    {
        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            throw new InvalidArgumentException('Invalid payload base64 encoding.');
        }

        if (strlen($decoded) < (self::IV_LENGTH + self::TAG_LENGTH)) {
            throw new InvalidArgumentException('Cipher payload truncated.');
        }

        $iv = substr($decoded, 0, self::IV_LENGTH);
        $tag = substr($decoded, self::IV_LENGTH, self::TAG_LENGTH);
        $cipherText = substr($decoded, self::IV_LENGTH + self::TAG_LENGTH);

        $plainText = openssl_decrypt(
            $cipherText,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plainText === false) {
            throw new RuntimeException('Decryption failed: authentication tag verification failed.');
        }

        return $plainText;
    }
}
