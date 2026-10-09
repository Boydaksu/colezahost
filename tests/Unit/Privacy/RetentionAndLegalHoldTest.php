<?php

declare(strict_types=1);

namespace Tests\Unit\Privacy;

use Coleza\Domain\Privacy\Erasure\PrivacyErasureService;
use Coleza\Domain\Privacy\Retention\LegalHoldScopeType;
use Coleza\Domain\Privacy\Retention\RetentionAndLegalHoldService;
use Coleza\Domain\Privacy\Retention\RetentionPolicy;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class RetentionAndLegalHoldTest extends TestCase
{
    private Connection $db;
    private RetentionAndLegalHoldService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');
        $this->service = new RetentionAndLegalHoldService($this->db);
        $this->service->ensureTables();
    }

    public function testDefaultRetentionPoliciesSeededAndEvaluated(): void
    {
        $financialPolicy = $this->service->getPolicy('financial_documents');
        $this->assertNotNull($financialPolicy);
        $this->assertSame('finance', $financialPolicy->getCategory());
        $this->assertSame(2555, $financialPolicy->getRetentionDays());
        $this->assertTrue($financialPolicy->isStatutory());
        $this->assertSame('archive', $financialPolicy->getActionOnExpiry());

        // Test record evaluation
        $now = new DateTimeImmutable('2026-10-09 12:00:00');

        // Invoice created 3 years ago (approx 1095 days) -> Not expired
        $threeYearsAgo = $now->modify('-1095 days');
        $evalRecent = $this->service->evaluateRetention('financial_documents', $threeYearsAgo, $now);
        $this->assertFalse($evalRecent['is_expired']);

        // Invoice created 8 years ago (approx 2920 days) -> Expired under policy
        $eightYearsAgo = $now->modify('-2920 days');
        $evalOld = $this->service->evaluateRetention('financial_documents', $eightYearsAgo, $now);
        $this->assertTrue($evalOld['is_expired']);
    }

    public function testEphemeralSessionRetentionExpiration(): void
    {
        $now = new DateTimeImmutable('2026-10-09 12:00:00');

        // Session from 10 days ago -> Not expired
        $tenDaysAgo = $now->modify('-10 days');
        $evalRecent = $this->service->evaluateRetention('ephemeral_sessions', $tenDaysAgo, $now);
        $this->assertFalse($evalRecent['is_expired']);

        // Session from 40 days ago -> Expired
        $fortyDaysAgo = $now->modify('-40 days');
        $evalOld = $this->service->evaluateRetention('ephemeral_sessions', $fortyDaysAgo, $now);
        $this->assertTrue($evalOld['is_expired']);
    }

    public function testPlaceAndReleaseUserLegalHold(): void
    {
        $userId = 44;

        $this->assertFalse($this->service->isSubjectUnderLegalHold($userId));

        // Place hold
        $hold = $this->service->placeLegalHold(
            holdReference: 'LH-2026-COURT-889',
            title: 'SEC Subpoena Preservation Order',
            reason: 'Federal court preservation order regarding transaction audits.',
            scopeType: LegalHoldScopeType::USER,
            issuedByAuthority: 'U.S. Securities and Exchange Commission',
            createdByStaffId: 101,
            scopeId: $userId
        );

        $this->assertNotNull($hold->getId());
        $this->assertSame('LH-2026-COURT-889', $hold->getHoldReference());
        $this->assertTrue($hold->isActive());
        $this->assertTrue($hold->matchesUser($userId));
        $this->assertTrue($this->service->isSubjectUnderLegalHold($userId));

        // Assert purge is prohibited
        try {
            $this->service->assertCanPurgeOrErase($userId);
            $this->fail('Expected ValidationException when under legal hold');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Data destruction or erasure strictly prohibited', $e->getMessage());
        }

        // Release hold
        $released = $this->service->releaseLegalHold(
            holdId: $hold->getId(),
            staffId: 101,
            releaseNotes: 'Investigation closed with no adverse findings; preservation order vacated.'
        );

        $this->assertFalse($released->isActive());
        $this->assertNotNull($released->getReleasedAt());
        $this->assertSame(101, $released->getReleasedByStaffId());
        $this->assertFalse($this->service->isSubjectUnderLegalHold($userId));

        // Assert purge now permitted
        $this->service->assertCanPurgeOrErase($userId);
        $this->assertTrue(true);
    }

    public function testLegalHoldScopeMatching(): void
    {
        // Organization scoped hold
        $orgHold = $this->service->placeLegalHold(
            holdReference: 'LH-ORG-TAX-01',
            title: 'Corporate Tax Audit Hold',
            reason: 'State revenue department inquiry.',
            scopeType: LegalHoldScopeType::ORGANIZATION,
            issuedByAuthority: 'State Tax Bureau',
            createdByStaffId: 10,
            scopeId: 15
        );
        $this->assertTrue($this->service->isSubjectUnderLegalHold(userId: 999, orgId: 15));
        $this->assertFalse($this->service->isSubjectUnderLegalHold(userId: 999, orgId: 16));

        // Resource scoped hold
        $resHold = $this->service->placeLegalHold(
            holdReference: 'LH-RES-DMCA-99',
            title: 'Evidence Preservation on Domain',
            reason: 'Federal copyright trial.',
            scopeType: LegalHoldScopeType::RESOURCE,
            issuedByAuthority: 'Federal District Court',
            createdByStaffId: 10,
            scopeIdentifier: 'infringing-domain.com'
        );
        $this->assertTrue($this->service->isSubjectUnderLegalHold(userId: 999, resourceIdentifier: 'infringing-domain.com'));
        $this->assertFalse($this->service->isSubjectUnderLegalHold(userId: 999, resourceIdentifier: 'clean-domain.com'));

        // Global hold
        $globalHold = $this->service->placeLegalHold(
            holdReference: 'LH-GLOBAL-FREEZE',
            title: 'Platform System-Wide Freeze',
            reason: 'Regulatory compliance review.',
            scopeType: LegalHoldScopeType::GLOBAL,
            issuedByAuthority: 'Chief Compliance Officer',
            createdByStaffId: 1
        );
        $this->assertTrue($this->service->isSubjectUnderLegalHold(userId: 12345));
    }

    public function testLegalHoldBlocksPrivacyErasureDryRunAndExecution(): void
    {
        $userId = 77;
        // Mock user table
        $this->db->statement('CREATE TABLE users (id INT, name VARCHAR(100), email VARCHAR(100))');
        $this->db->insert('users', ['id' => $userId, 'name' => 'Target User', 'email' => 'target@company.com']);

        $erasureService = new PrivacyErasureService($this->db, $this->service);
        $erasureService->ensureTables();

        // 1. Without hold, dry-run is eligible
        $planBefore = $erasureService->executeDryRun($userId);
        $this->assertTrue($planBefore->isEligible());
        $this->assertEmpty($planBefore->getBlockers());

        // 2. Place hold on user
        $hold = $this->service->placeLegalHold(
            holdReference: 'LH-POLICE-007',
            title: 'Law Enforcement Inquiry',
            reason: 'Active criminal investigation inquiry.',
            scopeType: LegalHoldScopeType::USER,
            issuedByAuthority: 'Cyber Crime Unit',
            createdByStaffId: 99,
            scopeId: $userId
        );

        // 3. Dry-run now identifies legal hold blocker
        $planAfter = $erasureService->executeDryRun($userId);
        $this->assertFalse($planAfter->isEligible());
        $this->assertNotEmpty($planAfter->getBlockers());
        $this->assertStringContainsString('Legal Hold (LH-POLICE-007)', $planAfter->getBlockers()[0]);

        // 4. Executing erasure throws ValidationException
        try {
            $erasureService->executeErasure($userId, executedBy: 1, reason: 'User requested erasure');
            $this->fail('Expected ValidationException when executing erasure during active legal hold');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Legal Hold', $e->getMessage());
        }

        // 5. Release legal hold
        $this->service->releaseLegalHold($hold->getId(), staffId: 99, releaseNotes: 'Inquiry satisfied');

        // 6. Now dry-run is eligible again and erasure proceeds
        $planRestored = $erasureService->executeDryRun($userId);
        $this->assertTrue($planRestored->isEligible());

        $result = $erasureService->executeErasure($userId, executedBy: 1, reason: 'Erasure executed following hold release');
        $this->assertTrue($result->isSuccess());
    }
}
