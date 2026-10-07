<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Domain\Privacy;

use Coleza\Domain\Identity\Audit\AuditLogger;
use Coleza\Domain\Privacy\DataClassification;
use Coleza\Domain\Privacy\PrivacyConsentService;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class PrivacyConsentServiceTest extends TestCase
{
    private Connection $connection;
    private AuditLogger $auditLogger;
    private PrivacyConsentService $privacy;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->connection = new Connection($pdo, 'sqlite');
        $this->auditLogger = new AuditLogger($this->connection);
        $this->privacy = new PrivacyConsentService($this->connection, $this->auditLogger);
    }

    public function testDataClassificationCategories(): void
    {
        $this->assertSame(DataClassification::RESTRICTED_PII, DataClassification::classify('users.email'));
        $this->assertSame(DataClassification::RESTRICTED_PII, DataClassification::classify('users.tax_number'));
        $this->assertSame(DataClassification::CONFIDENTIAL, DataClassification::classify('users.password_hash'));
        $this->assertSame(DataClassification::CONFIDENTIAL, DataClassification::classify('vault.secret'));
        $this->assertSame(DataClassification::PUBLIC, DataClassification::classify('brands.name'));
        $this->assertSame(DataClassification::INTERNAL, DataClassification::classify('non_existent.field'));

        $this->assertTrue(DataClassification::isPii('users.name'));
        $this->assertFalse(DataClassification::isPii('brands.name'));
    }

    public function testConsentGrantAndRevocationFlow(): void
    {
        $userId = 1;
        $purpose = 'marketing_email';

        $this->assertFalse($this->privacy->hasConsent($userId, $purpose));

        // Grant consent
        $this->privacy->grantConsent($userId, $purpose, ip: '127.0.0.1');
        $this->assertTrue($this->privacy->hasConsent($userId, $purpose));

        // Revoke consent
        $this->privacy->revokeConsent($userId, $purpose, ip: '127.0.0.1');
        $this->assertFalse($this->privacy->hasConsent($userId, $purpose));

        // Verify audit trail
        $logs = $this->auditLogger->getLogsForUser($userId);
        $this->assertCount(2, $logs);
        $this->assertSame('PRIVACY_CONSENT_REVOKED', $logs[0]['event_type']);
        $this->assertSame('PRIVACY_CONSENT_GRANTED', $logs[1]['event_type']);
    }

    public function testPrivacyTombstonesPreventResurrection(): void
    {
        $userId = 99;

        $this->assertFalse($this->privacy->isTombstoned($userId));

        // Customer requests account erasure under GDPR
        $this->privacy->recordTombstone($userId, erasureType: 'ANONYMIZE', reason: 'Right to be forgotten request');

        $this->assertTrue($this->privacy->isTombstoned($userId));

        // Verify tombstone creation logged in audit
        $logs = $this->auditLogger->getLogsForUser($userId);
        $this->assertNotEmpty($logs);
        $this->assertSame('PRIVACY_TOMBSTONE_CREATED', $logs[0]['event_type']);
    }
}
