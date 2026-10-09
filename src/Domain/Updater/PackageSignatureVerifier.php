<?php

declare(strict_types=1);

namespace Coleza\Domain\Updater;

use RuntimeException;
use Throwable;

/**
 * Service for signing and verifying cryptographic signatures on update packages.
 * Supports:
 * - Modern Ed25519 (Libsodium - standard in modern PHP 8.2+)
 * - RSA-SHA256 (OpenSSL)
 */
final class PackageSignatureVerifier
{
    /**
     * @param string $publicKey Public key (Hex/Base64/PEM format)
     * @param string $scheme Signature scheme ('ed25519' or 'rsa-sha256')
     */
    public function __construct(
        private string $publicKey,
        private string $scheme = 'ed25519'
    ) {
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }

    /**
     * Verifies that the given payload matches the detached cryptographic signature.
     *
     * @param string $payload Serialized data or file contents to verify
     * @param string $signatureBase64 Base64-encoded binary signature
     * @return bool
     */
    public function verify(string $payload, string $signatureBase64): bool
    {
        $signature = base64_decode($signatureBase64, true);
        if ($signature === false || $signature === '') {
            return false;
        }

        if ($this->scheme === 'ed25519') {
            try {
                $pubBinary = hex2bin($this->publicKey);
                if ($pubBinary === false || strlen($pubBinary) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                    $pubBinary = base64_decode($this->publicKey, true);
                }

                if ($pubBinary === false || strlen($pubBinary) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                    return false;
                }

                return sodium_crypto_sign_verify_detached($signature, $payload, $pubBinary);
            } catch (Throwable) {
                return false;
            }
        }

        // OpenSSL RSA fallback
        try {
            $pubKeyResource = openssl_pkey_get_public($this->publicKey);
            if ($pubKeyResource === false) {
                return false;
            }

            $result = openssl_verify($payload, $signature, $pubKeyResource, OPENSSL_ALGO_SHA256);
            return $result === 1;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Helper to create a signature with a private key (for packaging/testing).
     *
     * @param string $payload
     * @param string $privateKey Private key (Hex/Base64 for Ed25519 or PEM for RSA)
     * @param string $scheme
     * @return string Base64 encoded signature
     */
    public static function signPayload(string $payload, string $privateKey, string $scheme = 'ed25519'): string
    {
        if ($scheme === 'ed25519') {
            $privBinary = hex2bin($privateKey);
            if ($privBinary === false || strlen($privBinary) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
                $privBinary = base64_decode($privateKey, true);
            }

            if ($privBinary === false || strlen($privBinary) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
                throw new RuntimeException('Invalid Ed25519 secret key length.');
            }

            $sig = sodium_crypto_sign_detached($payload, $privBinary);
            return base64_encode($sig);
        }

        $privKeyResource = openssl_pkey_get_private($privateKey);
        if ($privKeyResource === false) {
            throw new RuntimeException('Invalid private key provided to sign payload.');
        }

        $signature = '';
        $success = openssl_sign($payload, $signature, $privKeyResource, OPENSSL_ALGO_SHA256);
        if (!$success) {
            throw new RuntimeException('OpenSSL failed to generate signature: ' . openssl_error_string());
        }

        return base64_encode($signature);
    }

    /**
     * Helper to generate a new keypair (for testing or key rotation).
     *
     * @param string $scheme 'ed25519' or 'rsa-sha256'
     * @return array{public: string, private: string, scheme: string}
     */
    public static function generateKeyPair(string $scheme = 'ed25519'): array
    {
        if ($scheme === 'ed25519') {
            $kp = sodium_crypto_sign_keypair();
            $secretKey = sodium_crypto_sign_secretkey($kp);
            $publicKey = sodium_crypto_sign_publickey($kp);

            return [
                'public' => sodium_bin2hex($publicKey),
                'private' => sodium_bin2hex($secretKey),
                'scheme' => 'ed25519',
            ];
        }

        // OpenSSL fallback
        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($res === false) {
            throw new RuntimeException('Failed to generate OpenSSL keypair: ' . openssl_error_string());
        }

        $privKey = '';
        openssl_pkey_export($res, $privKey);
        $details = openssl_pkey_get_details($res);
        $pubKey = (string) ($details['key'] ?? '');

        return [
            'public' => $pubKey,
            'private' => $privKey,
            'scheme' => 'rsa-sha256',
        ];
    }
}
