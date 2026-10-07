<?php

declare(strict_types=1);

namespace Coleza\Domain\Identity\TwoFactor;

use Coleza\Domain\Identity\Security\PasswordHasher;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use RuntimeException;

final class TwoFactorService
{
    private string $twoFactorTable = 'user_two_factor';
    private string $recoveryCodesTable = 'user_recovery_codes';
    private string $trustedDevicesTable = 'user_trusted_devices';

    public function __construct(
        private Connection $db,
        private TotpEngine $totpEngine,
        private PasswordHasher $hasher
    ) {
    }

    public function ensureTables(): void
    {
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        // 2FA state table
        $sql2FA = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                user_id INT NOT NULL UNIQUE,
                secret VARCHAR(128) NOT NULL,
                is_enabled INT NOT NULL DEFAULT 0,
                enabled_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->twoFactorTable,
            $autoInc
        );
        $this->db->statement($sql2FA);

        // Recovery codes table (stored hashed)
        $sqlRecovery = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                user_id INT NOT NULL,
                code_hash VARCHAR(128) NOT NULL,
                used_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->recoveryCodesTable,
            $autoInc
        );
        $this->db->statement($sqlRecovery);

        // Trusted devices table
        $sqlTrusted = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                user_id INT NOT NULL,
                device_token_hash VARCHAR(128) NOT NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                expires_at INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->trustedDevicesTable,
            $autoInc
        );
        $this->db->statement($sqlTrusted);
    }

    /**
     * Start 2FA setup for user: generates secret without activating yet.
     *
     * @return array{secret: string, qr_uri: string}
     */
    public function beginSetup(int $userId, string $accountEmail, string $issuer = 'Coleza Host'): array
    {
        $this->ensureTables();
        $secret = $this->totpEngine->generateSecret();

        $existing = $this->db->selectOne(
            sprintf('SELECT id FROM %s WHERE user_id = :uid', $this->twoFactorTable),
            ['uid' => $userId]
        );

        if ($existing === null) {
            $this->db->insert($this->twoFactorTable, [
                'user_id' => $userId,
                'secret' => $secret,
                'is_enabled' => 0,
                'enabled_at' => null,
            ]);
        } else {
            $this->db->update(
                $this->twoFactorTable,
                [
                    'secret' => $secret,
                    'is_enabled' => 0,
                    'enabled_at' => null,
                ],
                'user_id = :uid',
                ['uid' => $userId]
            );
        }

        $uri = $this->totpEngine->getProvisioningUri($issuer, $accountEmail, $secret);

        return [
            'secret' => $secret,
            'qr_uri' => $uri,
        ];
    }

    /**
     * Confirm 2FA setup with a valid TOTP code, enable 2FA, and generate recovery codes.
     *
     * @return array<int, string> List of plain recovery codes for user backup
     */
    public function confirmSetup(int $userId, string $code): array
    {
        $this->ensureTables();
        $record = $this->db->selectOne(
            sprintf('SELECT secret FROM %s WHERE user_id = :uid', $this->twoFactorTable),
            ['uid' => $userId]
        );

        if ($record === null) {
            throw new ValidationException(['two_factor' => ['2FA setup has not been initiated.']]);
        }

        $secret = (string) $record['secret'];
        if (!$this->totpEngine->verifyCode($secret, $code)) {
            throw new ValidationException(['code' => ['Invalid two-factor authentication code.']]);
        }

        // Enable 2FA and generate 8 fresh recovery codes
        $recoveryCodes = [];
        $hashedCodes = [];

        for ($i = 0; $i < 8; $i++) {
            $plain = strtoupper(bin2hex(random_bytes(4)) . '-' . bin2hex(random_bytes(4)));
            $recoveryCodes[] = $plain;
            $hashedCodes[] = hash('sha256', $plain);
        }

        $this->db->transaction(function (Connection $db) use ($userId, $hashedCodes): void {
            $db->update(
                $this->twoFactorTable,
                [
                    'is_enabled' => 1,
                    'enabled_at' => date('Y-m-d H:i:s'),
                ],
                'user_id = :uid',
                ['uid' => $userId]
            );

            // Clear old recovery codes and insert new ones
            $db->delete($this->recoveryCodesTable, 'user_id = :uid', ['uid' => $userId]);
            foreach ($hashedCodes as $hash) {
                $db->insert($this->recoveryCodesTable, [
                    'user_id' => $userId,
                    'code_hash' => $hash,
                    'used_at' => null,
                ]);
            }
        });

        return $recoveryCodes;
    }

    /**
     * Check if 2FA is active for given user.
     */
    public function isEnabled(int $userId): bool
    {
        $this->ensureTables();
        $record = $this->db->selectOne(
            sprintf('SELECT is_enabled FROM %s WHERE user_id = :uid', $this->twoFactorTable),
            ['uid' => $userId]
        );

        return $record !== null && (int) $record['is_enabled'] === 1;
    }

    /**
     * Verify TOTP code during login challenge.
     */
    public function verifyLoginCode(int $userId, string $code): bool
    {
        $this->ensureTables();
        $record = $this->db->selectOne(
            sprintf('SELECT secret, is_enabled FROM %s WHERE user_id = :uid', $this->twoFactorTable),
            ['uid' => $userId]
        );

        if ($record === null || (int) $record['is_enabled'] !== 1) {
            return true; // Not enabled, no challenge needed
        }

        return $this->totpEngine->verifyCode((string) $record['secret'], $code);
    }

    /**
     * Redeem a recovery code. Each code can only be used once.
     */
    public function redeemRecoveryCode(int $userId, string $plainCode): bool
    {
        $this->ensureTables();
        $hash = hash('sha256', strtoupper(trim($plainCode)));

        $record = $this->db->selectOne(
            sprintf('SELECT id FROM %s WHERE user_id = :uid AND code_hash = :hash AND used_at IS NULL', $this->recoveryCodesTable),
            ['uid' => $userId, 'hash' => $hash]
        );

        if ($record === null) {
            return false;
        }

        $this->db->update(
            $this->recoveryCodesTable,
            ['used_at' => date('Y-m-d H:i:s')],
            'id = :id',
            ['id' => $record['id']]
        );

        return true;
    }

    /**
     * Trust current device/browser to skip 2FA challenge for TTL seconds (e.g. 30 days).
     *
     * @return string Device token for client cookie
     */
    public function trustDevice(int $userId, ?string $ip = null, ?string $userAgent = null, int $ttlSeconds = 2592000): string
    {
        $this->ensureTables();
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        $this->db->insert($this->trustedDevicesTable, [
            'user_id' => $userId,
            'device_token_hash' => $tokenHash,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'expires_at' => time() + $ttlSeconds,
        ]);

        return $token;
    }

    /**
     * Verify if device token is trusted and active.
     */
    public function isDeviceTrusted(int $userId, string $plainToken): bool
    {
        $this->ensureTables();
        $tokenHash = hash('sha256', $plainToken);

        $record = $this->db->selectOne(
            sprintf('SELECT id, expires_at FROM %s WHERE user_id = :uid AND device_token_hash = :hash', $this->trustedDevicesTable),
            ['uid' => $userId, 'hash' => $tokenHash]
        );

        if ($record === null) {
            return false;
        }

        if ((int) $record['expires_at'] < time()) {
            $this->db->delete($this->trustedDevicesTable, 'id = :id', ['id' => $record['id']]);
            return false;
        }

        return true;
    }

    /**
     * Enforce Admin 2FA Policy according to Security Constitution.
     * Throws exception if user is an admin without 2FA enabled.
     */
    public function enforceAdmin2FaPolicy(int $userId, bool $isAdmin): void
    {
        if ($isAdmin && !$this->isEnabled($userId)) {
            throw new ValidationException(
                ['two_factor' => ['Mandatory 2FA policy enforced for administrator accounts. Setup 2FA before proceeding.']],
                'Administrative accounts require two-factor authentication.'
            );
        }
    }
}
