<?php

declare(strict_types=1);

namespace Tests\Unit\Privacy;

use Coleza\Domain\Privacy\Erasure\ErasureAction;
use Coleza\Domain\Privacy\Erasure\PrivacyErasureService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class PrivacyErasureAndRestrictionTest extends TestCase
{
    private Connection $db;
    private PrivacyErasureService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');
        $this->service = new PrivacyErasureService($this->db);
        $this->service->ensureTables();

        $this->createTestTables();
    }

    private function createTestTables(): void
    {
        $this->db->statement(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(100),
                email VARCHAR(100),
                phone VARCHAR(50),
                address TEXT,
                tax_number VARCHAR(50),
                password_hash VARCHAR(255),
                two_factor_secret VARCHAR(100),
                status VARCHAR(30) DEFAULT \'active\'
            )'
        );

        $this->db->statement(
            'CREATE TABLE hosting_services (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INT,
                domain VARCHAR(100),
                status VARCHAR(30)
            )'
        );

        $this->db->statement(
            'CREATE TABLE domains (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INT,
                fqdn VARCHAR(100),
                status VARCHAR(30)
            )'
        );

        $this->db->statement(
            'CREATE TABLE invoices (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INT,
                invoice_number VARCHAR(50),
                status VARCHAR(30)
            )'
        );

        $this->db->statement(
            'CREATE TABLE privacy_consents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INT,
                purpose VARCHAR(50)
            )'
        );
    }

    public function testDryRunIdentifiesActiveServiceBlockers(): void
    {
        $userId = 10;
        $this->db->insert('users', ['id' => $userId, 'name' => 'John Doe', 'email' => 'john@test.com']);
        $this->db->insert('hosting_services', ['user_id' => $userId, 'domain' => 'active-vps.com', 'status' => 'active']);

        $plan = $this->service->executeDryRun($userId);

        $this->assertFalse($plan->isEligible());
        $this->assertNotEmpty($plan->getBlockers());
        $this->assertStringContainsString('Active hosting service', $plan->getBlockers()[0]);

        // Trying to execute erasure throws ValidationException
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Account cannot be erased while active services');
        $this->service->executeErasure($userId, executedBy: 1, reason: 'Right to be forgotten');
    }

    public function testDryRunIdentifiesUnpaidInvoiceBlockers(): void
    {
        $userId = 11;
        $this->db->insert('users', ['id' => $userId, 'name' => 'Jane Debt', 'email' => 'jane@test.com']);
        $this->db->insert('invoices', ['user_id' => $userId, 'invoice_number' => 'INV-999', 'status' => 'unpaid']);

        $plan = $this->service->executeDryRun($userId);

        $this->assertFalse($plan->isEligible());
        $this->assertStringContainsString('Unpaid invoice', $plan->getBlockers()[0]);
    }

    public function testCleanAccountDryRunCategorizesDeleteAnonymizeRetain(): void
    {
        $userId = 12;
        $this->db->insert('users', ['id' => $userId, 'name' => 'Clean User', 'email' => 'clean@test.com']);
        // Terminated service
        $this->db->insert('hosting_services', ['user_id' => $userId, 'domain' => 'old.com', 'status' => 'terminated']);
        // Paid invoice (retained)
        $this->db->insert('invoices', ['user_id' => $userId, 'invoice_number' => 'INV-001', 'status' => 'paid']);
        // Marketing consent (deleted)
        $this->db->insert('privacy_consents', ['user_id' => $userId, 'purpose' => 'marketing']);

        $plan = $this->service->executeDryRun($userId);

        $this->assertTrue($plan->isEligible());
        $this->assertEmpty($plan->getBlockers());

        $this->assertGreaterThanOrEqual(1, $plan->getDeleteCount());
        $this->assertGreaterThanOrEqual(1, $plan->getAnonymizeCount());
        $this->assertGreaterThanOrEqual(1, $plan->getRetainCount());

        $summary = $plan->toArray()['summary'];
        $this->assertSame($plan->getDeleteCount(), $summary['delete_count']);
        $this->assertSame($plan->getAnonymizeCount(), $summary['anonymize_count']);
        $this->assertSame($plan->getRetainCount(), $summary['retain_count']);
    }

    public function testExecuteErasureAnonymizesProfileDeletesConsentsRetainsInvoices(): void
    {
        $userId = 15;
        $this->db->insert('users', [
            'id' => $userId,
            'name' => 'Robert Erasure',
            'email' => 'robert@real.com',
            'phone' => '+15551234567',
            'address' => '123 Real Street',
            'tax_number' => 'US-123456',
            'password_hash' => '$2y$10$realhash',
            'two_factor_secret' => 'SECRETKEY',
            'status' => 'active',
        ]);
        $this->db->insert('invoices', ['user_id' => $userId, 'invoice_number' => 'INV-888', 'status' => 'paid']);
        $this->db->insert('privacy_consents', ['user_id' => $userId, 'purpose' => 'analytics']);

        $result = $this->service->executeErasure(
            userId: $userId,
            executedBy: 99,
            reason: 'GDPR Article 17 Erasure request verified and accepted'
        );

        $this->assertTrue($result->isSuccess());
        $this->assertNotEmpty($result->getAuditChecksum());
        $this->assertSame($userId, $result->getUserId());

        // Check user table anonymization
        $user = $this->db->selectOne("SELECT * FROM users WHERE id = :id", ['id' => $userId]);
        $this->assertNotNull($user);
        $this->assertStringContainsString('[Anonymized User #15]', $user['name']);
        $this->assertStringContainsString('erased_15_', $user['email']);
        $this->assertStringEndsWith('@anonymized.local', $user['email']);
        $this->assertNull($user['phone']);
        $this->assertNull($user['address']);
        $this->assertNull($user['tax_number']);
        $this->assertNull($user['two_factor_secret']);
        $this->assertSame('ERASED', $user['password_hash']);
        $this->assertSame('erased', $user['status']);

        // Check consents deleted
        $consents = $this->db->select("SELECT * FROM privacy_consents WHERE user_id = :id", ['id' => $userId]);
        $this->assertEmpty($consents);

        // Check invoice retained
        $invoices = $this->db->select("SELECT * FROM invoices WHERE user_id = :id", ['id' => $userId]);
        $this->assertCount(1, $invoices);

        // Check audit log
        $log = $this->service->getErasureLog($userId);
        $this->assertNotNull($log);
        $this->assertSame(99, (int) $log['executed_by']);
        $this->assertSame($result->getAuditChecksum(), $log['audit_checksum']);
    }

    public function testRestrictionOfProcessingWorkflow(): void
    {
        $userId = 25;

        $this->assertFalse($this->service->isProcessingRestricted($userId));

        // Impose restriction
        $this->service->restrictProcessing(
            userId: $userId,
            staffOrUserId: 1,
            reason: 'Customer requested temporary restriction under GDPR Article 18 pending identity dispute.'
        );

        $this->assertTrue($this->service->isProcessingRestricted($userId));

        // Lift restriction
        $this->service->liftRestriction(
            userId: $userId,
            staffId: 99,
            liftReason: 'Identity dispute successfully resolved; normal billing processing resumed.'
        );

        $this->assertFalse($this->service->isProcessingRestricted($userId));
    }
}
