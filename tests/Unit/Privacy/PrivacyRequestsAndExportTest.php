<?php

declare(strict_types=1);

namespace Tests\Unit\Privacy;

use Coleza\Domain\Privacy\Export\DefaultPrivacyDataCollector;
use Coleza\Domain\Privacy\Export\PrivacyDataCollectorInterface;
use Coleza\Domain\Privacy\Export\PrivacyExportPackage;
use Coleza\Domain\Privacy\Requests\PrivacyRequest;
use Coleza\Domain\Privacy\Requests\PrivacyRequestService;
use Coleza\Domain\Privacy\Requests\PrivacyRequestStatus;
use Coleza\Domain\Privacy\Requests\PrivacyRequestType;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class PrivacyRequestsAndExportTest extends TestCase
{
    private Connection $db;
    private PrivacyRequestService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');
        $this->service = new PrivacyRequestService($this->db);
        $this->service->ensureTables();

        $this->setUpMockDatabaseData();
    }

    private function setUpMockDatabaseData(): void
    {
        // Mock user table
        $this->db->statement(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(100),
                email VARCHAR(100),
                password_hash VARCHAR(255),
                two_factor_secret VARCHAR(100),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->db->insert('users', [
            'name' => 'Alice Privacy',
            'email' => 'alice@company.com',
            'password_hash' => '$2y$10$supersecretpasswordhashthatmustbescrubbed',
            'two_factor_secret' => 'SECRET2FAKEY',
        ]);

        // Mock consents table
        $this->db->statement(
            'CREATE TABLE privacy_consents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INT,
                purpose VARCHAR(100),
                status VARCHAR(20),
                granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->db->insert('privacy_consents', [
            'user_id' => 1,
            'purpose' => 'marketing_emails',
            'status' => 'granted',
        ]);
    }

    public function testCreatePrivacyRequestGeneratesStepUpChallenge(): void
    {
        $result = $this->service->createRequest(userId: 1, type: PrivacyRequestType::EXPORT);

        /** @var PrivacyRequest $request */
        $request = $result['request'];
        $otpCode = $result['otp_code'];

        $this->assertNotNull($request->getId());
        $this->assertSame(1, $request->getUserId());
        $this->assertSame(PrivacyRequestType::EXPORT, $request->getRequestType());
        $this->assertSame(PrivacyRequestStatus::PENDING_VERIFICATION, $request->getStatus());
        $this->assertTrue($request->isPendingVerification());
        $this->assertFalse($request->isVerified());

        // OTP is 6 digits
        $this->assertMatchesRegularExpression('/^\d{6}$/', $otpCode);

        // Plaintext OTP is NOT stored in DB; only SHA-256 hash is stored
        $this->assertSame(hash('sha256', $otpCode), $request->getOtpCodeHash());
        $this->assertNotSame($otpCode, $request->getOtpCodeHash());
    }

    public function testCannotGenerateExportWithoutStepUpVerification(): void
    {
        $result = $this->service->createRequest(userId: 1, type: PrivacyRequestType::EXPORT);
        $request = $result['request'];

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Step-up identity verification required before data export can be compiled.');

        $this->service->generateExport($request->getId());
    }

    public function testFailedStepUpOtpAttemptsEnforceRateLimitAndLockout(): void
    {
        $result = $this->service->createRequest(userId: 1, type: PrivacyRequestType::EXPORT);
        $request = $result['request'];
        $token = $request->getStepUpToken();

        // Attempt 1: wrong code
        try {
            $this->service->verifyStepUp($token, '000000');
            $this->fail('Expected ValidationException on wrong code');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Attempts remaining: 2', $e->getMessage());
        }

        // Attempt 2: wrong code
        try {
            $this->service->verifyStepUp($token, '111111');
            $this->fail('Expected ValidationException on wrong code');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Attempts remaining: 1', $e->getMessage());
        }

        // Attempt 3: wrong code -> locked/rejected
        try {
            $this->service->verifyStepUp($token, '222222');
            $this->fail('Expected ValidationException on lockout');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Maximum step-up verification attempts exceeded. Request locked.', $e->getMessage());
        }

        // Request is now REJECTED
        $updated = $this->service->getRequest($request->getId());
        $this->assertSame(PrivacyRequestStatus::REJECTED, $updated?->getStatus());

        // Subsequent attempt blocked
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('rejected due to excessive failed verification attempts');
        $this->service->verifyStepUp($token, $result['otp_code']);
    }

    public function testSuccessfulStepUpAllowsExportGenerationWithScrubbedSecrets(): void
    {
        $result = $this->service->createRequest(userId: 1, type: PrivacyRequestType::EXPORT);
        $request = $result['request'];
        $token = $request->getStepUpToken();
        $otpCode = $result['otp_code'];

        // Verify step-up
        $verified = $this->service->verifyStepUp($token, $otpCode);
        $this->assertTrue($verified->isVerified());
        $this->assertNotNull($verified->getVerifiedAt());

        // Compile export package
        $package = $this->service->generateExport($request->getId());

        $this->assertSame(1, $package->getUserId());
        $this->assertNotEmpty($package->getChecksum());
        $this->assertTrue($package->verifyIntegrity());

        $data = $package->getData();
        $this->assertArrayHasKey('profile', $data);
        $this->assertArrayHasKey('consents', $data);

        // Check that confidential secrets were safely scrubbed
        $profile = $data['profile'];
        $this->assertSame('Alice Privacy', $profile['name']);
        $this->assertSame('alice@company.com', $profile['email']);
        $this->assertArrayNotHasKey('password_hash', $profile);
        $this->assertArrayNotHasKey('two_factor_secret', $profile);

        // Verify request marked COMPLETED
        $completedReq = $this->service->getRequest($request->getId());
        $this->assertTrue($completedReq?->isCompleted());
        $this->assertNotNull($completedReq?->getCompletedAt());
        $this->assertSame($package->getChecksum(), $completedReq?->getExportChecksum());

        // Retrieve package via getExport
        $retrieved = $this->service->getExport($request->getId());
        $this->assertNotNull($retrieved);
        $this->assertSame($package->getChecksum(), $retrieved->getChecksum());
        $this->assertTrue($retrieved->verifyIntegrity());
    }

    public function testExportPackageTamperDetection(): void
    {
        $result = $this->service->createRequest(userId: 1, type: PrivacyRequestType::EXPORT);
        $this->service->verifyStepUp($result['request']->getStepUpToken(), $result['otp_code']);
        $package = $this->service->generateExport($result['request']->getId());

        $this->assertTrue($package->verifyIntegrity());

        // Tamper with data
        $tamperedData = $package->getData();
        $tamperedData['profile']['name'] = 'Attacker Impersonator';

        $tamperedPackage = new PrivacyExportPackage(
            userId: $package->getUserId(),
            generatedAt: $package->getGeneratedAt(),
            data: $tamperedData,
            checksum: $package->getChecksum() // old checksum
        );

        $this->assertFalse($tamperedPackage->verifyIntegrity());
    }

    public function testUserCanHaveMultiplePrivacyRequestTypes(): void
    {
        $export = $this->service->createRequest(userId: 10, type: PrivacyRequestType::EXPORT);
        $erasure = $this->service->createRequest(userId: 10, type: PrivacyRequestType::ERASURE);
        $restrict = $this->service->createRequest(userId: 10, type: PrivacyRequestType::RESTRICTION);

        $userRequests = $this->service->getUserRequests(10);
        $this->assertCount(3, $userRequests);

        $types = array_map(fn (PrivacyRequest $r) => $r->getRequestType(), $userRequests);
        $this->assertContains(PrivacyRequestType::EXPORT, $types);
        $this->assertContains(PrivacyRequestType::ERASURE, $types);
        $this->assertContains(PrivacyRequestType::RESTRICTION, $types);
    }
}
