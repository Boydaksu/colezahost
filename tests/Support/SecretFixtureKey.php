<?php

declare(strict_types=1);
namespace Coleza\Tests\Support;
final class SecretFixtureKey
{
    public static function encryptor(): \Coleza\Domain\Vault\Encryptor
    {
        return new \Coleza\Domain\Vault\Encryptor(hash('sha256', 'colezahost synthetic test-only secret storage key', true));
    }
}
