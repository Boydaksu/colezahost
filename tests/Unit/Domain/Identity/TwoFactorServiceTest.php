<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Domain\Identity;

use Coleza\Domain\Identity\Security\PasswordHasher;
use Coleza\Domain\Identity\TwoFactor\TotpEngine;
use Coleza\Domain\Identity\TwoFactor\TwoFactorService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class TwoFactorServiceTest extends TestCase
{
    private Connection $connection;
    private TotpEngine $totpEngine;
    private PasswordHasher $hasher;
    private TwoFactorService $twoFactor;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->connection = new Connection($pdo, 'sqlite');
        $this->totpEngine = new TotpEngine();
        $this->hasher = new PasswordHasher(PASSWORD_BCRYPT, ['cost' => 4]);
        $this->twoFactor = new TwoFactorService($this->connection, $this->totpEngine, $this->hasher);
    }

    public function testTotpEngineCodeGenerationAndVerification(): void
    {
        $secret = $this->totpEngine->generateSecret();
        $this->assertNotEmpty($secret);

        $now = time();
        $code = $this->totpEngine->generateCode($secret, $now);
        $this->assertSame(6, strlen($code));

        $this->assertTrue($this->totpEngine->verifyCode($secret, $code, $now));
        $this->assertFalse($this->totpEngine->verifyCode($secret, '000000', $now));

        // Test clock drift (+/- 30 seconds within window)
        $this->assertTrue($this->totpEngine->verifyCode($secret, $code, $now + 25));
        $this->assertTrue($this->totpEngine->verifyCode($secret, $code, $now - 25));

        // Beyond window (2 periods away)
        $this->assertFalse($this->totpEngine->verifyCode($secret, $code, $now + 90));
    }

    public function testBeginAndConfirmTwoFactorSetup(): void
    {
        $userId = 1;
        $setup = $this->twoFactor->beginSetup($userId, 'admin@coleza.com', 'Coleza Host');

        $this->assertNotEmpty($setup['secret']);
        $this->assertStringContainsString('otpauth://totp/', $setup['qr_uri']);
        $this->assertFalse($this->twoFactor->isEnabled($userId));

        // Generate current code
        $validCode = $this->totpEngine->generateCode($setup['secret']);
        $recoveryCodes = $this->twoFactor->confirmSetup($userId, $validCode);

        $this->assertCount(8, $recoveryCodes);
        $this->assertTrue($this->twoFactor->isEnabled($userId));

        // Verify login code
        $this->assertTrue($this->twoFactor->verifyLoginCode($userId, $validCode));
        $this->assertFalse($this->twoFactor->verifyLoginCode($userId, '999999'));
    }

    public function testConfirmSetupWithInvalidCodeFails(): void
    {
        $userId = 2;
        $this->twoFactor->beginSetup($userId, 'user@coleza.com');

        $this->expectException(ValidationException::class);
        $this->twoFactor->confirmSetup($userId, '000000');
    }

    public function testRedeemRecoveryCode(): void
    {
        $userId = 3;
        $setup = $this->twoFactor->beginSetup($userId, 'recovery@coleza.com');
        $code = $this->totpEngine->generateCode($setup['secret']);
        $recoveryCodes = $this->twoFactor->confirmSetup($userId, $code);

        $testCode = $recoveryCodes[0];

        // First redemption succeeds
        $this->assertTrue($this->twoFactor->redeemRecoveryCode($userId, $testCode));

        // Reusing the same code fails (one-time use)
        $this->assertFalse($this->twoFactor->redeemRecoveryCode($userId, $testCode));
    }

    public function testTrustedDeviceLifecycle(): void
    {
        $userId = 4;
        $token = $this->twoFactor->trustDevice($userId, '192.168.1.1', 'Mozilla/5.0', ttlSeconds: 3600);

        $this->assertTrue($this->twoFactor->isDeviceTrusted($userId, $token));
        $this->assertFalse($this->twoFactor->isDeviceTrusted($userId, 'fake-token'));

        // Expire device
        $this->connection->statement('UPDATE user_trusted_devices SET expires_at = :past WHERE user_id = :uid', [
            'past' => time() - 100,
            'uid' => $userId,
        ]);

        $this->assertFalse($this->twoFactor->isDeviceTrusted($userId, $token));
    }

    public function testEnforceAdmin2FaPolicy(): void
    {
        $userId = 5;

        // Normal user without 2FA -> no exception
        $this->twoFactor->enforceAdmin2FaPolicy($userId, isAdmin: false);

        // Admin user without 2FA -> exception
        $this->expectException(ValidationException::class);
        $this->twoFactor->enforceAdmin2FaPolicy($userId, isAdmin: true);
    }
}
