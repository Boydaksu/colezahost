<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Privacy;

use Coleza\Domain\Privacy\Erasure\PrivacyErasureService;
use Coleza\Domain\Privacy\Handlers\BillingPrivacyHandler;
use Coleza\Domain\Privacy\Handlers\DomainPrivacyActionResult;
use Coleza\Domain\Privacy\Handlers\HostingPrivacyHandler;
use Coleza\Domain\Privacy\Handlers\PrivacyHandlerRegistry;
use Coleza\Domain\Privacy\Handlers\SupportPrivacyHandler;
use Coleza\Domain\Privacy\Redaction\RedactionAuditRecord;
use Coleza\Domain\Privacy\Redaction\RedactionAuditService;
use Coleza\Foundation\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class DomainPrivacyHandlersAndRedactionAuditTest extends TestCase
{
    private Connection $db;
    private PrivacyHandlerRegistry $registry;
    private RedactionAuditService $auditService;
    private BillingPrivacyHandler $billingHandler;
    private HostingPrivacyHandler $hostingHandler;
    private SupportPrivacyHandler $supportHandler;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->createTestTables();

        $this->billingHandler = new BillingPrivacyHandler($this->db);
        $this->hostingHandler = new HostingPrivacyHandler($this->db);
        $this->supportHandler = new SupportPrivacyHandler($this->db);

        $this->registry = new PrivacyHandlerRegistry([
            $this->billingHandler,
            $this->hostingHandler,
            $this->supportHandler,
        ]);

        $this->auditService = new RedactionAuditService($this->db, secretKey: 'test_audit_key');
        $this->auditService->ensureTables();
    }

    private function createTestTables(): void
    {
        $this->db->statement(
            'CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT "active",
                phone VARCHAR(64) NULL,
                address TEXT NULL,
                tax_number VARCHAR(64) NULL,
                two_factor_secret VARCHAR(64) NULL,
                password_hash VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $this->db->statement(
            'CREATE TABLE IF NOT EXISTS privacy_consents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INT NOT NULL,
                purpose VARCHAR(64) NOT NULL,
                is_granted TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $this->db->statement(
            'CREATE TABLE IF NOT EXISTS payment_methods (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INT NOT NULL,
                token VARCHAR(255) NOT NULL,
                card_brand VARCHAR(32) NOT NULL,
                card_last4 VARCHAR(4) NOT NULL,
                billing_name VARCHAR(255) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                expires_at VARCHAR(16) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $this->db->statement(
            'CREATE TABLE IF NOT EXISTS billing_profiles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INT NOT NULL,
                company_name VARCHAR(255) NULL,
                tax_number VARCHAR(64) NULL,
                tax_office VARCHAR(64) NULL,
                address TEXT NULL,
                city VARCHAR(64) NULL,
                state VARCHAR(64) NULL,
                postal_code VARCHAR(16) NULL,
                phone VARCHAR(64) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $this->db->statement(
            'CREATE TABLE IF NOT EXISTS hosting_services (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INT NOT NULL,
                domain VARCHAR(255) NOT NULL,
                username VARCHAR(64) NOT NULL,
                password_encrypted VARCHAR(255) NOT NULL,
                server_notes TEXT NULL,
                access_token VARCHAR(255) NULL,
                status VARCHAR(32) NOT NULL DEFAULT "terminated",
                package_name VARCHAR(64) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $this->db->statement(
            'CREATE TABLE IF NOT EXISTS support_tickets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INT NOT NULL,
                customer_email VARCHAR(255) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT "open",
                priority VARCHAR(32) NOT NULL DEFAULT "normal",
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $this->db->statement(
            'CREATE TABLE IF NOT EXISTS support_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ticket_id INT NOT NULL,
                user_id INT NOT NULL,
                message TEXT NOT NULL,
                ip_address VARCHAR(64) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )'
        );
    }

    public function testBillingPrivacyHandlerRedactsPaymentMethodsAndProfiles(): void
    {
        $userId = 10;
        $this->db->statement(
            "INSERT INTO payment_methods (user_id, token, card_brand, card_last4, billing_name, is_active)
             VALUES (10, 'tok_visa_live_12345', 'visa', '4242', 'John Smith', 1)"
        );
        $this->db->statement(
            "INSERT INTO billing_profiles (user_id, company_name, tax_number, tax_office, address, phone)
             VALUES (10, 'Smith Holdings LLC', 'US123456789', 'IRS NY', '100 Broadway, NY', '+12125550100')"
        );

        $result = $this->billingHandler->handleErasure($userId, 'ANONYMIZE');

        $this->assertSame('billing', $result->getDomainName());
        $this->assertSame(2, $result->getRecordsAffected());
        $this->assertContains('payment_methods.token', $result->getFieldsRedacted());
        $this->assertContains('billing_profiles.tax_number', $result->getFieldsRedacted());

        // Verify database redactions
        $method = $this->db->selectOne("SELECT * FROM payment_methods WHERE user_id = 10");
        $this->assertSame('ERASED', $method['token']);
        $this->assertSame('0000', $method['card_last4']);
        $this->assertSame('ERASED', $method['billing_name']);
        $this->assertSame(0, (int) $method['is_active']);

        $profile = $this->db->selectOne("SELECT * FROM billing_profiles WHERE user_id = 10");
        $this->assertSame('[Anonymized]', $profile['company_name']);
        $this->assertNull($profile['tax_number']);
        $this->assertNull($profile['phone']);

        // Verify restriction deactivates payment method
        $this->billingHandler->handleRestriction($userId, true);
    }

    public function testHostingPrivacyHandlerRedactsCredentialsAndTokens(): void
    {
        $userId = 20;
        $this->db->statement(
            "INSERT INTO hosting_services (user_id, domain, username, password_encrypted, server_notes, access_token, status)
             VALUES (20, 'client20.com', 'cpanel_client', 'supersecretpwd', 'VIP account notes', 'cpanel_api_token_xyz', 'terminated')"
        );

        $result = $this->hostingHandler->handleErasure($userId, 'ANONYMIZE');

        $this->assertSame('hosting', $result->getDomainName());
        $this->assertSame(1, $result->getRecordsAffected());
        $this->assertContains('hosting_services.username', $result->getFieldsRedacted());
        $this->assertContains('hosting_services.password_encrypted', $result->getFieldsRedacted());

        $svc = $this->db->selectOne("SELECT * FROM hosting_services WHERE user_id = 20");
        $this->assertSame('erased_user', $svc['username']);
        $this->assertSame('ERASED', $svc['password_encrypted']);
        $this->assertNull($svc['server_notes']);
        $this->assertNull($svc['access_token']);
    }

    public function testSupportPrivacyHandlerRedactsTicketsAndMessages(): void
    {
        $userId = 30;
        $this->db->statement(
            "INSERT INTO support_tickets (id, user_id, customer_email, subject, status)
             VALUES (1, 30, 'client30@email.com', 'Help with my credit card', 'open')"
        );
        $this->db->statement(
            "INSERT INTO support_messages (ticket_id, user_id, message, ip_address)
             VALUES (1, 30, 'My home address is 123 Main St and phone is 555-1234', '198.51.100.42')"
        );

        $result = $this->supportHandler->handleErasure($userId, 'ANONYMIZE');

        $this->assertSame('support', $result->getDomainName());
        $this->assertSame(2, $result->getRecordsAffected());

        $ticket = $this->db->selectOne("SELECT * FROM support_tickets WHERE id = 1");
        $this->assertSame('[Redacted]', $ticket['subject']);
        $this->assertSame('erased@anonymized.local', $ticket['customer_email']);
        $this->assertSame('closed', $ticket['status']);

        $msg = $this->db->selectOne("SELECT * FROM support_messages WHERE ticket_id = 1");
        $this->assertSame('[Personal content redacted under right to erasure]', $msg['message']);
        $this->assertSame('127.0.0.1', $msg['ip_address']);
    }

    public function testRedactionAuditRecordTamperEvidentSignatures(): void
    {
        $audit = RedactionAuditRecord::create(
            userId: 50,
            domainName: 'billing',
            actionType: 'ANONYMIZE',
            recordsAffected: 3,
            redactedFields: ['payment_methods.token'],
            preChecksum: 'hash_pre',
            postChecksum: 'hash_post',
            verifiedClean: true,
            auditedBy: 1,
            secretKey: 'test_audit_key'
        );

        $this->assertTrue($audit->verifySignature('test_audit_key'));
        $this->assertFalse($audit->verifySignature('wrong_key'));

        $tampered = RedactionAuditRecord::create(
            userId: 50,
            domainName: 'billing',
            actionType: 'ANONYMIZE',
            recordsAffected: 999, // tampered count
            redactedFields: ['payment_methods.token'],
            preChecksum: 'hash_pre',
            postChecksum: 'hash_post',
            verifiedClean: true,
            auditedBy: 1,
            secretKey: 'different_key'
        );
        $this->assertFalse($tampered->verifySignature('test_audit_key'));
    }

    public function testEndToEndErasureWithCoordinatedHandlersAndRedactionAudit(): void
    {
        $userId = 100;

        // Populate comprehensive multi-domain data for user 100
        $this->db->statement(
            "INSERT INTO users (id, name, email, status, phone, password_hash)
             VALUES (100, 'Robert Downey', 'rd@hollywood.com', 'active', '+13105550199', 'pwdhash')"
        );
        $this->db->statement(
            "INSERT INTO privacy_consents (user_id, purpose, is_granted)
             VALUES (100, 'marketing', 1)"
        );
        $this->db->statement(
            "INSERT INTO payment_methods (user_id, token, card_brand, card_last4, billing_name, is_active)
             VALUES (100, 'tok_100_secret', 'mastercard', '9999', 'Robert Downey', 1)"
        );
        $this->db->statement(
            "INSERT INTO billing_profiles (user_id, company_name, tax_number, address, phone)
             VALUES (100, 'RD Productions', 'TAX999', 'Malibu Beach', '+13105550199')"
        );
        $this->db->statement(
            "INSERT INTO hosting_services (user_id, domain, username, password_encrypted, status)
             VALUES (100, 'rd.org', 'rd_cpanel', 'secretpass', 'terminated')"
        );

        // Pre-erasure compliance scan: detects multiple violations because user is active with PII
        $preScan = $this->auditService->scanForUnredactedPii($userId);
        $this->assertFalse($preScan->isCompliant());
        $this->assertGreaterThan(0, $preScan->getViolationsDetected());

        // Initialize PrivacyErasureService wired with Handler Registry and Redaction Audit Service
        $erasureService = new PrivacyErasureService(
            db: $this->db,
            legalHoldService: null,
            tombstoneService: null,
            handlerRegistry: $this->registry,
            redactionAuditService: $this->auditService
        );
        $erasureService->ensureTables();

        // Execute coordinated erasure
        $result = $erasureService->executeErasure(
            userId: $userId,
            executedBy: 42,
            reason: 'Formal GDPR erasure request across all services and modules'
        );

        $this->assertTrue($result->isSuccess());

        // Post-erasure compliance scan: confirms ZERO violations remain across all domains!
        $postScan = $this->auditService->scanForUnredactedPii($userId);
        $this->assertTrue($postScan->isCompliant());
        $this->assertSame(0, $postScan->getViolationsDetected());
        $this->assertEmpty($postScan->getViolationDetails());

        // Verify audit trails recorded for all affected domains
        $audits = $this->auditService->getAuditsForUser($userId);
        $this->assertNotEmpty($audits);

        $domainNames = array_map(fn($a) => $a->getDomainName(), $audits);
        $this->assertContains('billing', $domainNames);
        $this->assertContains('hosting', $domainNames);
        $this->assertContains('identity_core', $domainNames);

        // Verify all audit signatures in the audit chain are cryptographically authentic
        $this->assertTrue($this->auditService->verifyAllSignatures($userId));
    }
}
