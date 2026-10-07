<?php

declare(strict_types=1);

namespace Coleza\Domain\Identity\Auth;

use Coleza\Domain\Identity\Security\PasswordHasher;
use Coleza\Domain\Identity\Session\DatabaseSessionHandler;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use RuntimeException;

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

    public function ensureTables(): void
    {
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
        $this->db->statement($sqlUsers);

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
        $this->db->statement($sqlResets);

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
        $this->db->statement($sqlVerifications);
    }

    /**
     * Register a new user.
     *
     * @return int Created User ID
     */
    public function register(string $email, string $password, string $name): int
    {
        $this->ensureTables();
        $email = strtolower(trim($email));

        $existing = $this->db->selectOne(
            sprintf('SELECT id FROM %s WHERE email = :email', $this->usersTable),
            ['email' => $email]
        );

        if ($existing !== null) {
            throw new ValidationException(['email' => ['Email is already registered.']], 'Email is already registered.');
        }

        $hash = $this->hasher->hash($password);

        $id = $this->db->insert($this->usersTable, [
            'email' => $email,
            'password_hash' => $hash,
            'name' => trim($name),
            'is_active' => 1,
            'email_verified_at' => null,
        ]);

        return (int) $id;
    }

    /**
     * Authenticate user with email and password.
     *
     * @return array<string, mixed> User record
     */
    public function authenticate(string $email, string $password): array
    {
        $this->ensureTables();
        $email = strtolower(trim($email));

        $user = $this->db->selectOne(
            sprintf('SELECT * FROM %s WHERE email = :email', $this->usersTable),
            ['email' => $email]
        );

        if ($user === null) {
            throw new ValidationException(['credentials' => ['Invalid credentials.']], 'Invalid credentials.');
        }

        if ((int) $user['is_active'] !== 1) {
            throw new ValidationException(['account' => ['Account is inactive.']], 'Account is inactive.');
        }

        if (!$this->hasher->verify($password, (string) $user['password_hash'])) {
            throw new ValidationException(['credentials' => ['Invalid credentials.']], 'Invalid credentials.');
        }

        // Check and apply rehash if necessary
        if ($this->hasher->needsRehash((string) $user['password_hash'])) {
            $newHash = $this->hasher->hash($password);
            $this->db->update(
                $this->usersTable,
                ['password_hash' => $newHash],
                'id = :id',
                ['id' => $user['id']]
            );
        }

        return $user;
    }

    /**
     * Generate password reset token.
     *
     * @return string Cleartext reset token to send via email
     */
    public function createPasswordResetToken(string $email, int $ttlSeconds = 3600): string
    {
        $this->ensureTables();
        $email = strtolower(trim($email));

        // Purge old tokens for this email
        $this->db->delete($this->passwordResetsTable, 'email = :email', ['email' => $email]);

        $plainToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plainToken);

        $this->db->insert($this->passwordResetsTable, [
            'email' => $email,
            'token_hash' => $tokenHash,
            'expires_at' => time() + $ttlSeconds,
        ]);

        return $plainToken;
    }

    /**
     * Reset password using token.
     */
    public function resetPassword(string $email, string $plainToken, string $newPassword): void
    {
        $this->ensureTables();
        $email = strtolower(trim($email));
        $tokenHash = hash('sha256', $plainToken);

        $record = $this->db->selectOne(
            sprintf('SELECT * FROM %s WHERE email = :email AND token_hash = :hash', $this->passwordResetsTable),
            ['email' => $email, 'hash' => $tokenHash]
        );

        if ($record === null || (int) $record['expires_at'] < time()) {
            throw new ValidationException(['token' => ['Invalid or expired password reset token.']], 'Invalid or expired password reset token.');
        }

        $user = $this->db->selectOne(
            sprintf('SELECT id FROM %s WHERE email = :email', $this->usersTable),
            ['email' => $email]
        );

        if ($user === null) {
            throw new ValidationException(['user' => ['User not found.']], 'User not found.');
        }

        $newHash = $this->hasher->hash($newPassword);

        $this->db->transaction(function (Connection $db) use ($user, $newHash, $email): void {
            $db->update(
                $this->usersTable,
                ['password_hash' => $newHash],
                'id = :id',
                ['id' => $user['id']]
            );

            // Invalidate all tokens and sessions for user
            $db->delete($this->passwordResetsTable, 'email = :email', ['email' => $email]);
            $this->sessionHandler->destroyForUser((int) $user['id']);
        });
    }

    /**
     * Generate email verification token.
     */
    public function createEmailVerificationToken(int $userId, int $ttlSeconds = 86400): string
    {
        $this->ensureTables();
        $this->db->delete($this->emailVerificationsTable, 'user_id = :uid', ['uid' => $userId]);

        $plainToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plainToken);

        $this->db->insert($this->emailVerificationsTable, [
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => time() + $ttlSeconds,
        ]);

        return $plainToken;
    }

    /**
     * Verify email address using token.
     */
    public function verifyEmail(int $userId, string $plainToken): bool
    {
        $this->ensureTables();
        $tokenHash = hash('sha256', $plainToken);

        $record = $this->db->selectOne(
            sprintf('SELECT * FROM %s WHERE user_id = :uid AND token_hash = :hash', $this->emailVerificationsTable),
            ['uid' => $userId, 'hash' => $tokenHash]
        );

        if ($record === null || (int) $record['expires_at'] < time()) {
            return false;
        }

        $this->db->transaction(function (Connection $db) use ($userId): void {
            $db->update(
                $this->usersTable,
                ['email_verified_at' => date('Y-m-d H:i:s')],
                'id = :id',
                ['id' => $userId]
            );

            $db->delete($this->emailVerificationsTable, 'user_id = :uid', ['uid' => $userId]);
        });

        return true;
    }
}
