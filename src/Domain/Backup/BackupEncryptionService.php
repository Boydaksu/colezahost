<?php

declare(strict_types=1);

namespace Coleza\Domain\Backup;

use RuntimeException;
use Throwable;

/**
 * Handles AES-256-GCM authenticated encryption and decryption for backup archives.
 * Includes cryptographic nonce, ciphertext, and 16-byte authentication tag.
 */
final class BackupEncryptionService
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;
    private const NONCE_LENGTH = 12;

    /**
     * @param string $passphrase Master encryption secret or passphrase
     */
    public function __construct(
        private string $passphrase
    ) {
        if (trim($passphrase) === '') {
            throw new RuntimeException('Backup encryption passphrase cannot be empty.');
        }
    }

    /**
     * Encrypts plaintext bytes using AES-256-GCM.
     * Output format: [12 bytes IV/Nonce] . [16 bytes Auth Tag] . [Ciphertext]
     */
    public function encrypt(string $plainText): string
    {
        $key = hash('sha256', $this->passphrase, true);
        $iv = random_bytes(self::NONCE_LENGTH);
        $tag = '';

        $cipherText = openssl_encrypt(
            $plainText,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($cipherText === false) {
            throw new RuntimeException('OpenSSL failed to encrypt backup payload: ' . openssl_error_string());
        }

        return $iv . $tag . $cipherText;
    }

    /**
     * Decrypts ciphertext bytes using AES-256-GCM.
     */
    public function decrypt(string $payload): string
    {
        if (strlen($payload) < (self::NONCE_LENGTH + self::TAG_LENGTH)) {
            throw new RuntimeException('Invalid encrypted payload: insufficient length for IV and tag.');
        }

        $key = hash('sha256', $this->passphrase, true);
        $iv = substr($payload, 0, self::NONCE_LENGTH);
        $tag = substr($payload, self::NONCE_LENGTH, self::TAG_LENGTH);
        $cipherText = substr($payload, self::NONCE_LENGTH + self::TAG_LENGTH);

        $plainText = openssl_decrypt(
            $cipherText,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plainText === false) {
            throw new RuntimeException('Backup decryption failed: invalid passphrase or corrupted/tampered payload.');
        }

        return $plainText;
    }

    public function encryptFile(string $sourcePath, string $destinationPath): void
    {
        $content = (string) file_get_contents($sourcePath);
        $encrypted = $this->encrypt($content);
        file_put_contents($destinationPath, $encrypted);
    }

    public function decryptFile(string $sourcePath, string $destinationPath): void
    {
        $content = (string) file_get_contents($sourcePath);
        $decrypted = $this->decrypt($content);
        file_put_contents($destinationPath, $decrypted);
    }
}
