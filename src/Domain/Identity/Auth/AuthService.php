<?php

declare(strict_types=1);

namespace Coleza\Domain\Identity\Auth;

use Coleza\Domain\Identity\Security\PasswordHasher;
use Coleza\Domain\Identity\Session\DatabaseSessionHandler;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use Coleza\Domain\Identity\IdentityPersistence;

final class AuthService
{
    private string $usersTable = 'users';
    private string $passwordResetsTable = 'password_resets';
    private string $emailVerificationsTable = 'email_verifications';

    public function __construct(
        private Connection $db,
        private PasswordHasher $hasher,
        private DatabaseSessionHandler $sessionHandler
    ) {
    }

    public static function schemaRequirements(): array
    {
        return ['users' => ['id', 'email', 'password_hash', 'name', 'is_active', 'email_verified_at', 'created_at'],
            'password_resets' => ['id', 'email', 'token_hash', 'expires_at', 'created_at'],
            'email_verifications' => ['id', 'user_id', 'token_hash', 'expires_at', 'created_at']];
    }

    public function ensureTables(bool $installation = true): void
    {
        if (IdentityPersistence::installed($this->db, self::schemaRequirements(), $installation, ['users' => 'email'])) { return; }
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        // Users table
        $sqlUsers = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                email VARCHAR(191) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                name VARCHAR(100) NOT NULL,
                is_active INT NOT NULL DEFAULT 1,
                email_verified_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->usersTable,
            $autoInc
        );
        $this->db->statement($sqlUsers . ($driver === 'mysql' ? ' ENGINE=InnoDB' : ''));

        // Password resets table
        $sqlResets = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                email VARCHAR(191) NOT NULL,
                token_hash VARCHAR(128) NOT NULL,
                expires_at INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->passwordResetsTable,
            $autoInc
        );
        $this->db->statement($sqlResets . ($driver === 'mysql' ? ' ENGINE=InnoDB' : ''));

        // Email verifications table
        $sqlVerifications = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                user_id INT NOT NULL,
                token_hash VARCHAR(128) NOT NULL,
                expires_at INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->emailVerificationsTable,
            $autoInc
        );
        $this->db->statement($sqlVerifications . ($driver === 'mysql' ? ' ENGINE=InnoDB' : ''));
    }

    public function register(string $email, string $password, string $name): int
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191 || trim($name) === '') { throw new ValidationException(['email' => ['Valid email and name required.']]); }
        $this->ensureTables(installation: false);
        if ($this->db->selectOne('SELECT id FROM users WHERE email = ?', [$email]) !== null) { throw new ValidationException(['email' => ['Email is already registered.']]); }
        try {
            return (int) $this->db->insert('users', ['email' => $email, 'password_hash' => $this->hasher->hash($password), 'name' => trim($name), 'is_active' => 1, 'email_verified_at' => null]);
        } catch (\PDOException $error) {
            if ((string) $error->getCode() !== '23000') { throw $error; }
            IdentityPersistence::assertCaller($this->db);
            if ($this->db->selectOne('SELECT id FROM users WHERE email = ?' . IdentityPersistence::lock($this->db), [$email]) !== null) { throw new ValidationException(['email' => ['Email is already registered.']]); }
            throw $error;
        }
    }

    public function authenticate(string $email, string $password): array
    {
        $this->ensureTables(installation: false); $email = strtolower(trim($email));
        return IdentityPersistence::run($this->db, function () use ($email, $password): array {
            $user = $this->db->selectOne('SELECT * FROM users WHERE email = ?' . IdentityPersistence::lock($this->db), [$email]);
            if ($user === null || !$this->hasher->verify($password, (string) $user['password_hash'])) { throw new ValidationException(['credentials' => ['Invalid credentials.']], 'Invalid credentials.'); }
            if ((int) $user['is_active'] !== 1 || (array_key_exists('status', $user) && $user['status'] !== 'active')) { throw new ValidationException(['account' => ['Account is inactive.']], 'Account is inactive.'); }
            if ($this->hasher->needsRehash((string) $user['password_hash'])) {
                $user['password_hash'] = $this->hasher->hash($password);
                $this->db->update('users', ['password_hash' => $user['password_hash']], 'id = :id', ['id' => $user['id']]);
            }
            return $user;
        });
    }

    public function createPasswordResetToken(string $email, int $ttlSeconds = 3600): string
    {
        IdentityPersistence::expiresAt($ttlSeconds); $this->ensureTables(installation: false); $email = strtolower(trim($email));
        $plain = bin2hex(random_bytes(32));
        IdentityPersistence::run($this->db, function () use ($email, $ttlSeconds, $plain): void {
            $user = $this->db->selectOne('SELECT id FROM users WHERE email = ?' . IdentityPersistence::lock($this->db), [$email]);
            // Keep the opaque response shape for unknown accounts without creating usable orphan tokens.
            if ($user === null) { return; }
            $this->db->delete('password_resets', 'email = :email', ['email' => $email]);
            $this->db->insert('password_resets', ['email' => $email, 'token_hash' => hash('sha256', $plain), 'expires_at' => IdentityPersistence::expiresAt($ttlSeconds)]);
        });
        return $plain;
    }

    public function resetPassword(string $email, string $plainToken, string $newPassword): void
    {
        $this->ensureTables(installation: false); $this->sessionHandler->prepareLifecycle(); $email = strtolower(trim($email));
        $twoFactorInstalled = IdentityPersistence::installed($this->db, \Coleza\Domain\Identity\TwoFactor\TwoFactorService::schemaRequirements(), installation: false, unique: ['user_two_factor' => 'user_id']);
        $tokenHash = hash('sha256', $plainToken);
        IdentityPersistence::run($this->db, function () use ($email, $tokenHash, $newPassword, $twoFactorInstalled): void {
            $user = $this->db->selectOne('SELECT id FROM users WHERE email = ?' . IdentityPersistence::lock($this->db), [$email]);
            if ($user === null) { throw new ValidationException(['token' => ['Invalid or expired password reset token.']]); }
            $records = $this->db->select('SELECT * FROM password_resets WHERE email = ? AND token_hash = ?' . IdentityPersistence::lock($this->db), [$email, $tokenHash]);
            if (count($records) !== 1 || (int) $records[0]['expires_at'] <= time()) { throw new ValidationException(['token' => ['Invalid or expired password reset token.']]); }
            $newHash = $this->hasher->hash($newPassword);
            $this->db->update('users', ['password_hash' => $newHash], 'id = :id', ['id' => $user['id']]);
            $this->db->delete('password_resets', 'email = :email', ['email' => $email]);
            if ($twoFactorInstalled) {
                $this->db->selectOne('SELECT id FROM user_two_factor WHERE user_id = ?' . IdentityPersistence::lock($this->db), [$user['id']]);
                $this->db->delete('user_trusted_devices', 'user_id = :uid', ['uid' => $user['id']]);
            }
            $this->sessionHandler->destroyForUser((int) $user['id']);
        });
    }

    public function createEmailVerificationToken(int $userId, int $ttlSeconds = 86400): string
    {
        IdentityPersistence::expiresAt($ttlSeconds); $this->ensureTables(installation: false); $plain = bin2hex(random_bytes(32));
        IdentityPersistence::run($this->db, function () use ($userId, $ttlSeconds, $plain): void {
            if ($this->db->selectOne('SELECT id FROM users WHERE id = ?' . IdentityPersistence::lock($this->db), [$userId]) === null) { throw new ValidationException(['user' => ['User not found.']]); }
            $this->db->delete('email_verifications', 'user_id = :uid', ['uid' => $userId]);
            $this->db->insert('email_verifications', ['user_id' => $userId, 'token_hash' => hash('sha256', $plain), 'expires_at' => IdentityPersistence::expiresAt($ttlSeconds)]);
        });
        return $plain;
    }

    public function verifyEmail(int $userId, string $plainToken): bool
    {
        $this->ensureTables(installation: false); $hash = hash('sha256', $plainToken);
        return IdentityPersistence::run($this->db, function () use ($userId, $hash): bool {
            if ($this->db->selectOne('SELECT id FROM users WHERE id = ?' . IdentityPersistence::lock($this->db), [$userId]) === null) { return false; }
            $records = $this->db->select('SELECT * FROM email_verifications WHERE user_id = ? AND token_hash = ?' . IdentityPersistence::lock($this->db), [$userId, $hash]);
            if (count($records) !== 1 || (int) $records[0]['expires_at'] <= time()) { return false; }
            $this->db->update('users', ['email_verified_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $userId]);
            $this->db->delete('email_verifications', 'user_id = :uid', ['uid' => $userId]);
            return true;
        });
    }
}
