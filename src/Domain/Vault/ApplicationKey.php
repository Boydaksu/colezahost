<?php

declare(strict_types=1);

namespace Coleza\Domain\Vault;

/** Explicit installation key provisioning; runtime never creates or replaces a key. */
final class ApplicationKey
{
    public static function generate(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    public static function encryptor(#[\SensitiveParameter] mixed $configuredKey): Encryptor
    {
        if (!is_string($configuredKey) || $configuredKey === '') {
            throw new \RuntimeException('Configure a persistent installation-specific APP_KEY before using encrypted storage.');
        }
        $bytes = str_starts_with($configuredKey, 'base64:')
            ? base64_decode(substr($configuredKey, 7), true) : $configuredKey;
        if ($bytes === false || strlen($bytes) !== 32 || $bytes === str_repeat($bytes[0], 32)) {
            throw new \RuntimeException('APP_KEY must contain 32 random bytes; malformed or repeated-byte keys are refused.');
        }
        return new Encryptor($bytes);
    }
}
