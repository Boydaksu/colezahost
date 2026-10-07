<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Domain\Identity;

use Coleza\Domain\Identity\Auth\AuthService;
use Coleza\Domain\Identity\Security\PasswordHasher;
use Coleza\Domain\Identity\Session\DatabaseSessionHandler;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class AuthServiceTest extends TestCase
{
    private Connection $connection;
    private PasswordHasher $hasher;
    private DatabaseSessionHandler $sessionHandler;
    private AuthService $auth;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->connection = new Connection($pdo, 'sqlite');
        // Use Bcrypt with low cost in tests for high speed
        $this->hasher = new PasswordHasher(PASSWORD_BCRYPT, ['cost' => 4]);
        $this->sessionHandler = new DatabaseSessionHandler($this->connection);
        $this->auth = new AuthService($this->connection, $this->hasher, $this->sessionHandler);
    }

    public function testRegisterAndAuthenticateUser(): void
    {
        $userId = $this->auth->register('admin@coleza.com', 'SuperSecret123!', 'Coleza Admin');
        $this->assertGreaterThan(0, $userId);

        $user = $this->auth->authenticate('admin@coleza.com', 'SuperSecret123!');
        $this->assertSame($userId, (int) $user['id']);
        $this->assertSame('admin@coleza.com', $user['email']);
        $this->assertSame('Coleza Admin', $user['name']);
    }

    public function testDuplicateEmailThrowsValidationException(): void
    {
        $this->auth->register('test@coleza.com', 'Password123!', 'User One');

        $this->expectException(ValidationException::class);
        $this->auth->register('test@coleza.com', 'AnotherPassword!', 'User Two');
    }

    public function testAuthenticateInvalidPasswordThrowsValidationException(): void
    {
        $this->auth->register('test@coleza.com', 'Password123!', 'User One');

        $this->expectException(ValidationException::class);
        $this->auth->authenticate('test@coleza.com', 'WrongPassword!');
    }

    public function testPasswordResetFlow(): void
    {
        $userId = $this->auth->register('user@coleza.com', 'OldPass123!', 'Reset Tester');

        // Store active session for user
        $this->sessionHandler->write('sess_123', ['user_id' => $userId], userId: $userId);
        $this->assertNotEmpty($this->sessionHandler->read('sess_123'));

        // Request reset
        $token = $this->auth->createPasswordResetToken('user@coleza.com');
        $this->assertNotEmpty($token);

        // Reset password
        $this->auth->resetPassword('user@coleza.com', $token, 'NewSecurePass456!');

        // Check authentication works with new password and fails with old
        $user = $this->auth->authenticate('user@coleza.com', 'NewSecurePass456!');
        $this->assertSame($userId, (int) $user['id']);

        // Check user session was revoked
        $this->assertEmpty($this->sessionHandler->read('sess_123'));
    }

    public function testEmailVerificationFlow(): void
    {
        $userId = $this->auth->register('verify@coleza.com', 'Password123!', 'Verify Tester');

        $token = $this->auth->createEmailVerificationToken($userId);
        $this->assertNotEmpty($token);

        $verified = $this->auth->verifyEmail($userId, $token);
        $this->assertTrue($verified);

        // Second verification with consumed token fails
        $this->assertFalse($this->auth->verifyEmail($userId, $token));
    }

    public function testDatabaseSessionGarbageCollection(): void
    {
        $handler = new DatabaseSessionHandler($this->connection, lifetimeSeconds: 10);
        $handler->write('sess_active', ['logged' => true]);

        // Manipulate last_activity to simulate expired session
        $this->connection->statement('UPDATE sessions SET last_activity = :past WHERE id = "sess_active"', [
            'past' => time() - 100,
        ]);

        $this->assertEmpty($handler->read('sess_active'));
    }
}
