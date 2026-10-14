<?php

declare(strict_types=1);
namespace Coleza\Domain\Vault;

/** Versioned authenticated storage; legacy plaintext never becomes a runtime read fallback. */
final class SecretCipher
{
    public const PREFIX = 'enc:v1:';
    public function __construct(private ?Encryptor $encryptor = null) {}
    private function encryptor(): Encryptor
    {
        return $this->encryptor ??= ApplicationKey::encryptor(\Coleza\Domain\Vault\ApplicationKey::configured());
    }
    public function seal(#[\SensitiveParameter] string $plaintext, string $scope): string
    {
        return self::PREFIX . $this->encryptor()->encrypt($plaintext, $scope);
    }
    public function open(#[\SensitiveParameter] string $stored, string $scope): string
    {
        if (!str_starts_with($stored, self::PREFIX)) {
            throw new \LogicException('Legacy or unknown secret encoding; explicit secret storage upgrade required.');
        }
        return $this->encryptor()->decrypt(substr($stored, strlen(self::PREFIX)), $scope);
    }
}
