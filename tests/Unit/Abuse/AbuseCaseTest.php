<?php

declare(strict_types=1);

namespace Tests\Unit\Abuse;

use Coleza\Domain\Abuse\AbuseCase;
use Coleza\Domain\Abuse\AbuseCaseService;
use Coleza\Domain\Abuse\AbuseCaseStatus;
use Coleza\Domain\Abuse\AbuseCategory;
use Coleza\Domain\Abuse\AbuseSeverity;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class AbuseCaseTest extends TestCase
{
    private Connection $db;
    private AbuseCaseService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');
        $this->service = new AbuseCaseService($this->db);
        $this->service->ensureTables();
    }

    public function testCreateAbuseCaseWithAutomaticDeadline(): void
    {
        $now = new DateTimeImmutable();
        $case = $this->service->createCase(
            category: AbuseCategory::PHISHING,
            severity: AbuseSeverity::HIGH,
            reporterEmail: 'cert@securityteam.com',
            resourceType: 'domain',
            resourceIdentifier: 'fake-bank-login.com',
            subject: 'Credential harvesting site active on domain',
            description: 'Phishing portal impersonating bank credentials detected.',
            reporterName: 'Global CERT Team',
            organizationId: 5,
            userId: 20,
            evidenceUrl: 'https://securityteam.com/reports/12345'
        );

        $this->assertNotNull($case->getId());
        $this->assertStringStartsWith('ABUSE-', $case->getCaseNumber());
        $this->assertSame(AbuseCategory::PHISHING, $case->getCategory());
        $this->assertSame(AbuseSeverity::HIGH, $case->getSeverity());
        $this->assertSame(AbuseCaseStatus::OPEN, $case->getStatus());
        $this->assertSame('fake-bank-login.com', $case->getResourceIdentifier());
        $this->assertSame('domain', $case->getResourceType());
        $this->assertSame(5, $case->getOrganizationId());
        $this->assertSame(20, $case->getUserId());

        // High severity defaults to 24 hours deadline
        $this->assertNotNull($case->getDeadlineAt());
        $diffHours = (int) round(($case->getDeadlineAt()->getTimestamp() - $now->getTimestamp()) / 3600);
        $this->assertSame(24, $diffHours);
        $this->assertFalse($case->isOverdue());
    }

    public function testCreateCaseValidationRequiresMandatoryFields(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Abuse case validation failed.');

        $this->service->createCase(
            category: AbuseCategory::SPAM,
            severity: AbuseSeverity::LOW,
            reporterEmail: 'invalid-email',
            resourceType: 'ip',
            resourceIdentifier: '',
            subject: '',
            description: ''
        );
    }

    public function testMessageWorkflowTriggersAutomaticStateTransitions(): void
    {
        $case = $this->service->createCase(
            category: AbuseCategory::SPAM,
            severity: AbuseSeverity::MEDIUM,
            reporterEmail: 'victim@provider.com',
            resourceType: 'service',
            resourceIdentifier: 'svc_894',
            subject: 'Port 25 outbound spam storm',
            description: 'Excessive spam relayed from VPS IP.'
        );

        // 1. Staff messages the accused client
        $staffMsg = $this->service->addMessage(
            caseId: $case->getId(),
            authorType: 'staff',
            message: 'Please review mail logs and clean infected scripts immediately.',
            authorId: 101,
            isInternal: false
        );

        $this->assertNotNull($staffMsg->getId());
        $updated = $this->service->getCase($case->getId());
        $this->assertSame(AbuseCaseStatus::WAITING_CLIENT_RESPONSE, $updated?->getStatus());

        // 2. Client responds
        $clientMsg = $this->service->addMessage(
            caseId: $case->getId(),
            authorType: 'client',
            message: 'Infected WordPress plugin removed, postfix queue cleared.',
            authorId: 50,
            isInternal: false
        );

        $this->assertNotNull($clientMsg->getId());
        $updatedClient = $this->service->getCase($case->getId());
        $this->assertSame(AbuseCaseStatus::CLIENT_RESPONDED, $updatedClient?->getStatus());

        // 3. Internal staff note does not change public client status
        $internalMsg = $this->service->addMessage(
            caseId: $case->getId(),
            authorType: 'staff',
            message: 'Analyst verified port 25 traffic dropped back to zero.',
            authorId: 101,
            isInternal: true
        );

        $this->assertTrue($internalMsg->isInternal());
        $updatedInternal = $this->service->getCase($case->getId());
        $this->assertSame(AbuseCaseStatus::CLIENT_RESPONDED, $updatedInternal?->getStatus());

        // 4. Message filtering by visibility
        $allMsgs = $this->service->getMessages($case->getId(), includeInternal: true);
        $this->assertCount(3, $allMsgs);

        $publicMsgs = $this->service->getMessages($case->getId(), includeInternal: false);
        $this->assertCount(2, $publicMsgs);
    }

    public function testOverdueCasesDetection(): void
    {
        $now = new DateTimeImmutable();
        $expiredDeadline = $now->modify('-2 hours');
        $futureDeadline = $now->modify('+10 hours');

        // Overdue case
        $overdueCase = $this->service->createCase(
            category: AbuseCategory::MALWARE,
            severity: AbuseSeverity::CRITICAL,
            reporterEmail: 'analyst@cert.gov',
            resourceType: 'ip',
            resourceIdentifier: '198.51.100.88',
            subject: 'Active trojan distribution server',
            description: 'Hosting ransomware binary payload.',
            customDeadline: $expiredDeadline
        );

        // Future case
        $futureCase = $this->service->createCase(
            category: AbuseCategory::DMCA_COPYRIGHT,
            severity: AbuseSeverity::LOW,
            reporterEmail: 'law@studios.com',
            resourceType: 'domain',
            resourceIdentifier: 'video-mirror.net',
            subject: 'Copyright complaint',
            description: 'Unauthorized movie streaming.',
            customDeadline: $futureDeadline
        );

        $this->assertTrue($overdueCase->isOverdue());
        $this->assertFalse($futureCase->isOverdue());

        $overdueList = $this->service->getOverdueCases();
        $this->assertCount(1, $overdueList);
        $this->assertSame($overdueCase->getId(), $overdueList[0]->getId());
    }

    public function testResolveAndDismissCaseWorkflows(): void
    {
        $case = $this->service->createCase(
            category: AbuseCategory::RESOURCE_ABUSE_DDOS,
            severity: AbuseSeverity::HIGH,
            reporterEmail: 'noc@tier1.com',
            resourceType: 'ip',
            resourceIdentifier: '203.0.113.15',
            subject: 'NTP reflection attack target',
            description: 'UDP 123 amplification traffic originating from host.'
        );

        $resolved = $this->service->resolveCase(
            caseId: $case->getId(),
            staffId: 99,
            resolutionNotes: 'NTP monlist disabled on customer firewall; traffic confirmed clean.'
        );

        $this->assertSame(AbuseCaseStatus::RESOLVED, $resolved->getStatus());
        $this->assertNotNull($resolved->getResolvedAt());
        $this->assertSame('NTP monlist disabled on customer firewall; traffic confirmed clean.', $resolved->getResolutionNotes());
        $this->assertFalse($resolved->isOverdue());

        // Dismissal test
        $falseReport = $this->service->createCase(
            category: AbuseCategory::SPAM,
            severity: AbuseSeverity::LOW,
            reporterEmail: 'crank@nowhere.com',
            resourceType: 'domain',
            resourceIdentifier: 'innocent.org',
            subject: 'Unfounded complaint',
            description: 'I do not like their website.'
        );

        $dismissed = $this->service->dismissCase(
            caseId: $falseReport->getId(),
            staffId: 99,
            reason: 'False positive; website does not send email.'
        );

        $this->assertSame(AbuseCaseStatus::DISMISSED, $dismissed->getStatus());
        $this->assertSame('False positive; website does not send email.', $dismissed->getResolutionNotes());
    }

    public function testSuspendResourceEmergencyAction(): void
    {
        $case = $this->service->createCase(
            category: AbuseCategory::BOTNET_C2,
            severity: AbuseSeverity::CRITICAL,
            reporterEmail: 'fbi@cyber.gov',
            resourceType: 'server',
            resourceIdentifier: 'srv-402',
            subject: 'Emergency C2 server takedown notice',
            description: 'Mirai botnet control infrastructure located on server.'
        );

        $suspended = $this->service->suspendResourceAction(
            caseId: $case->getId(),
            staffId: 100,
            notes: 'Emergency quarantine: network port shut down via API.'
        );

        $this->assertSame(AbuseCaseStatus::SUSPENDED, $suspended->getStatus());

        $messages = $this->service->getMessages($case->getId(), includeInternal: true);
        $this->assertCount(1, $messages);
        $this->assertTrue($messages[0]->isInternal());
        $this->assertStringContainsString('Resource suspended by staff #100', $messages[0]->getMessage());
    }

    public function testResourceAndUserIndexing(): void
    {
        $case1 = $this->service->createCase(
            category: AbuseCategory::PHISHING,
            severity: AbuseSeverity::HIGH,
            reporterEmail: 'agent@antiphish.com',
            resourceType: 'domain',
            resourceIdentifier: 'shared-domain.com',
            subject: 'Case 1 on domain',
            description: 'Phishing',
            organizationId: 12,
            userId: 77
        );

        $case2 = $this->service->createCase(
            category: AbuseCategory::MALWARE,
            severity: AbuseSeverity::CRITICAL,
            reporterEmail: 'agent@antiphish.com',
            resourceType: 'domain',
            resourceIdentifier: 'shared-domain.com',
            subject: 'Case 2 on domain',
            description: 'Malware',
            organizationId: 12,
            userId: 77
        );

        $byResource = $this->service->getCasesForResource('domain', 'shared-domain.com');
        $this->assertCount(2, $byResource);

        $byUser = $this->service->getCasesForUser(77);
        $this->assertCount(2, $byUser);

        $byOrg = $this->service->getCasesForOrganization(12);
        $this->assertCount(2, $byOrg);

        $byNumber = $this->service->getCaseByNumber($case1->getCaseNumber());
        $this->assertNotNull($byNumber);
        $this->assertSame($case1->getId(), $byNumber->getId());
    }
}
