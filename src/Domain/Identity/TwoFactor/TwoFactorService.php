<?php

declare(strict_types=1);

namespace Coleza\Domain\Identity\TwoFactor;

use Coleza\Domain\Identity\Security\PasswordHasher;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use Coleza\Domain\Identity\IdentityPersistence;

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

    public static function schemaRequirements(): array
    {
        return ['user_two_factor' => ['id', 'user_id', 'secret', 'is_enabled', 'enabled_at', 'created_at'],
            'user_recovery_codes' => ['id', 'user_id', 'code_hash', 'used_at', 'created_at'],
            'user_trusted_devices' => ['id', 'user_id', 'device_token_hash', 'ip_address', 'user_agent', 'expires_at', 'created_at']];
    }
    public function ensureTables(bool $installation = true): void
    {
        if (IdentityPersistence::installed($this->db, self::schemaRequirements(), $installation, ['user_two_factor' => 'user_id'])) { return; }
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
        $this->db->statement($sql2FA . ($driver === 'mysql' ? ' ENGINE=InnoDB' : ''));

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
        $this->db->statement($sqlRecovery . ($driver === 'mysql' ? ' ENGINE=InnoDB' : ''));

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
        $this->db->statement($sqlTrusted . ($driver === 'mysql' ? ' ENGINE=InnoDB' : ''));
    }

    public function beginSetup(int $userId, string $accountEmail, string $issuer = 'Coleza Host'): array
    {
        if ($userId < 1) { throw new \InvalidArgumentException('Invalid user ID.'); }
        $this->ensureTables(installation: false); TwoFactorLoginSchema::prepare($this->db); $secret = $this->totpEngine->generateSecret();
        return IdentityPersistence::run($this->db, function () use ($userId, $accountEmail, $issuer, $secret): array {
            $sql = 'INSERT INTO user_two_factor (user_id, secret, is_enabled, enabled_at) VALUES (?, ?, 0, NULL)' . ($this->db->getDriverName() === 'mysql' ? ' ON DUPLICATE KEY UPDATE user_id = user_id' : ' ON CONFLICT(user_id) DO NOTHING');
            $this->db->statement($sql, [$userId, $secret]);
            $record = $this->stateForUpdate($userId);
            if ((int) $record['is_enabled'] === 1) { throw new ValidationException(['two_factor' => ['Active 2FA cannot be replaced by setup.']]); }
            $this->db->update('user_two_factor', ['secret' => $secret, 'is_enabled' => 0, 'enabled_at' => null], 'user_id = :uid', ['uid' => $userId]);
            $this->db->delete('user_recovery_codes', 'user_id = :uid', ['uid' => $userId]);
            $this->db->delete('user_trusted_devices', 'user_id = :uid', ['uid' => $userId]);
            $this->db->delete('two_factor_login_steps', 'user_id = :uid', ['uid' => $userId]);
            return ['secret' => $secret, 'qr_uri' => $this->totpEngine->getProvisioningUri($issuer, $accountEmail, $secret)];
        });
    }

    public function confirmSetup(int $userId, string $code): array
    {
        $this->ensureTables(installation: false);
        return IdentityPersistence::run($this->db, function () use ($userId, $code): array {
            $record = $this->stateForUpdate($userId);
            if ($record === null || (int) $record['is_enabled'] !== 0) { throw new ValidationException(['two_factor' => ['Pending 2FA setup required.']]); }
            if (!$this->totpEngine->verifyCode((string) $record['secret'], $code)) { throw new ValidationException(['code' => ['Invalid two-factor authentication code.']]); }
            $plainCodes = [];
            $this->db->update('user_two_factor', ['is_enabled' => 1, 'enabled_at' => date('Y-m-d H:i:s')], 'user_id = :uid', ['uid' => $userId]);
            $this->db->delete('user_recovery_codes', 'user_id = :uid', ['uid' => $userId]);
            for ($i = 0; $i < 8; $i++) {
                $plain = strtoupper(bin2hex(random_bytes(4)) . '-' . bin2hex(random_bytes(4))); $plainCodes[] = $plain;
                $this->db->insert('user_recovery_codes', ['user_id' => $userId, 'code_hash' => hash('sha256', $plain), 'used_at' => null]);
            }
            return $plainCodes;
        });
    }
    public function isEnabled(int $userId): bool
    {
        $this->ensureTables(installation: false);
        return IdentityPersistence::run($this->db, function () use ($userId): bool { $record = $this->stateForUpdate($userId); return $record !== null && (int) $record['is_enabled'] === 1; });
    }
    public function verifyLoginCode(int $userId, string $code): bool
    {
        $this->ensureTables(installation: false); TwoFactorLoginSchema::prepare($this->db);
        return IdentityPersistence::run($this->db, function () use ($userId, $code): bool {
            $record = $this->stateForUpdate($userId);
            if ($record === null || (int) $record['is_enabled'] !== 1) { return true; }
            $step = $this->totpEngine->matchingStep((string) $record['secret'], $code);
            if ($step === null) { return false; }
            $used = $this->db->selectOne('SELECT last_used_step FROM two_factor_login_steps WHERE user_id = ?' . IdentityPersistence::lock($this->db), [$userId]);
            if ($used !== null && $step <= (int) $used['last_used_step']) { return false; }
            $sql = 'INSERT INTO two_factor_login_steps (user_id, last_used_step) VALUES (?, ?)' . ($this->db->getDriverName() === 'mysql' ? ' ON DUPLICATE KEY UPDATE last_used_step = VALUES(last_used_step)' : ' ON CONFLICT(user_id) DO UPDATE SET last_used_step = excluded.last_used_step');
            $this->db->statement($sql, [$userId, $step]); return true;
        });
    }
    public function redeemRecoveryCode(int $userId, string $plainCode): bool
    {
        $this->ensureTables(installation: false); $hash = hash('sha256', strtoupper(trim($plainCode)));
        return IdentityPersistence::run($this->db, function () use ($userId, $hash): bool {
            $state = $this->stateForUpdate($userId); if ($state === null || (int) $state['is_enabled'] !== 1) { return false; }
            $records = $this->db->select('SELECT id, used_at FROM user_recovery_codes WHERE user_id = ? AND code_hash = ?' . IdentityPersistence::lock($this->db), [$userId, $hash]);
            if (count($records) !== 1 || $records[0]['used_at'] !== null) { return false; }
            return $this->db->update('user_recovery_codes', ['used_at' => date('Y-m-d H:i:s')], 'id = :id AND used_at IS NULL', ['id' => $records[0]['id']]) === 1;
        });
    }
    public function trustDevice(int $userId, ?string $ip = null, ?string $userAgent = null, int $ttlSeconds = 2592000): string
    {
        IdentityPersistence::expiresAt($ttlSeconds); $this->ensureTables(installation: false); $plain = bin2hex(random_bytes(32));
        IdentityPersistence::run($this->db, function () use ($userId, $ip, $userAgent, $ttlSeconds, $plain): void {
            $state = $this->stateForUpdate($userId);
            if ($state === null || (int) $state['is_enabled'] !== 1) { throw new ValidationException(['two_factor' => ['Active 2FA required before trusting a device.']]); }
            $this->db->insert('user_trusted_devices', ['user_id' => $userId, 'device_token_hash' => hash('sha256', $plain), 'ip_address' => $ip, 'user_agent' => $userAgent, 'expires_at' => IdentityPersistence::expiresAt($ttlSeconds)]);
        });
        return $plain;
    }
    public function isDeviceTrusted(int $userId, string $plainToken): bool
    {
        $this->ensureTables(installation: false); $hash = hash('sha256', $plainToken);
        return IdentityPersistence::run($this->db, function () use ($userId, $hash): bool {
            $state = $this->stateForUpdate($userId); if ($state === null || (int) $state['is_enabled'] !== 1) { return false; }
            $records = $this->db->select('SELECT id, expires_at FROM user_trusted_devices WHERE user_id = ? AND device_token_hash = ?' . IdentityPersistence::lock($this->db), [$userId, $hash]);
            if (count($records) !== 1) { return false; }
            if ((int) $records[0]['expires_at'] <= time()) { $this->db->delete('user_trusted_devices', 'id = :id', ['id' => $records[0]['id']]); return false; }
            return true;
        });
    }
    private function stateForUpdate(int $userId): ?array
    {
        $record = $this->db->selectOne('SELECT * FROM user_two_factor WHERE user_id = ?' . IdentityPersistence::lock($this->db), [$userId]);
        if ($record !== null && (!in_array((string) $record['is_enabled'], ['0', '1'], true) || (string) $record['secret'] === '')) { throw new \LogicException('Invalid persisted 2FA state; explicit reconciliation required.'); }
        return $record;
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
