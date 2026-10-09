<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Release;

use Coleza\Domain\Release\V1ScopeFreezeAuditService;
use PHPUnit\Framework\TestCase;

final class V1ScopeFreezeAuditTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'colezahost_gate_audit_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testAuditPredecessorGatesPassesWithCompletePassEvidence(): void
    {
        $service = new V1ScopeFreezeAuditService();

        // Create mock evidence directory with P00..P17 all PASS
        foreach (V1ScopeFreezeAuditService::PREDECESSOR_PHASES as $phase) {
            $phaseDir = $this->tempDir . DIRECTORY_SEPARATOR . $phase;
            mkdir($phaseDir, 0777, true);
            file_put_contents(
                $phaseDir . DIRECTORY_SEPARATOR . 'gate-decision.md',
                "# Gate Decision — {$phase}\n\nEvaluator Decision: PASS\nGate Evaluation Date: 2026-10-09\n"
            );
        }

        $result = $service->auditPredecessorGates($this->tempDir);

        $this->assertTrue($result->isPassed);
        $this->assertSame(18, $result->totalPhasesAudited);
        $this->assertSame(18, $result->passedPhasesCount);
        $this->assertEmpty($result->violations);
        $this->assertCount(18, $result->entries);
    }

    public function testAuditPredecessorGatesFailsWhenPhaseDirectoryIsMissing(): void
    {
        $service = new V1ScopeFreezeAuditService();

        // Create only P00..P16, omit P17
        $phases = array_slice(V1ScopeFreezeAuditService::PREDECESSOR_PHASES, 0, 17);
        foreach ($phases as $phase) {
            $phaseDir = $this->tempDir . DIRECTORY_SEPARATOR . $phase;
            mkdir($phaseDir, 0777, true);
            file_put_contents(
                $phaseDir . DIRECTORY_SEPARATOR . 'gate-decision.md',
                "# Gate Decision — {$phase}\n\nEvaluator Decision: PASS\n"
            );
        }

        $result = $service->auditPredecessorGates($this->tempDir);

        $this->assertFalse($result->isPassed);
        $this->assertSame(17, $result->passedPhasesCount);
        $this->assertNotEmpty($result->violations);
        $this->assertStringContainsString('Missing evidence directory for phase P17', $result->violations[0]);
    }

    public function testAuditPredecessorGatesFailsWhenDecisionIsNotPass(): void
    {
        $service = new V1ScopeFreezeAuditService();

        foreach (V1ScopeFreezeAuditService::PREDECESSOR_PHASES as $phase) {
            $phaseDir = $this->tempDir . DIRECTORY_SEPARATOR . $phase;
            mkdir($phaseDir, 0777, true);
            $decision = ($phase === 'P15') ? 'IN_PROGRESS' : 'PASS';
            file_put_contents(
                $phaseDir . DIRECTORY_SEPARATOR . 'gate-decision.md',
                "# Gate Decision — {$phase}\n\nDecision: {$decision}\n"
            );
        }

        $result = $service->auditPredecessorGates($this->tempDir);

        $this->assertFalse($result->isPassed);
        $this->assertSame(17, $result->passedPhasesCount);
        $this->assertCount(1, $result->violations);
        $this->assertStringContainsString("Phase P15 gate decision is 'IN_PROGRESS', expected 'PASS'", $result->violations[0]);
    }

    public function testAuditDependencyGraphPassesWithAcyclicGraph(): void
    {
        $service = new V1ScopeFreezeAuditService();

        $phases = [
            ['id' => 'P00', 'hard_dependencies' => []],
            ['id' => 'P01', 'hard_dependencies' => ['P00']],
            ['id' => 'P02', 'hard_dependencies' => ['P01']],
            ['id' => 'P03', 'hard_dependencies' => ['P02']],
        ];

        $result = $service->auditDependencyGraph($phases);

        $this->assertTrue($result->isPassed);
        $this->assertSame(4, $result->totalPhasesChecked);
        $this->assertSame(['P00', 'P01', 'P02', 'P03'], $result->topologicalOrder);
        $this->assertEmpty($result->violations);
    }

    public function testAuditDependencyGraphFailsOnCycle(): void
    {
        $service = new V1ScopeFreezeAuditService();

        $phases = [
            ['id' => 'P00', 'hard_dependencies' => ['P02']],
            ['id' => 'P01', 'hard_dependencies' => ['P00']],
            ['id' => 'P02', 'hard_dependencies' => ['P01']],
        ];

        $result = $service->auditDependencyGraph($phases);

        $this->assertFalse($result->isPassed);
        $this->assertNotEmpty($result->violations);
        $this->assertStringContainsString('Circular dependency detected', $result->violations[0]);
    }

    public function testAuditDependencyGraphFailsOnMissingDependency(): void
    {
        $service = new V1ScopeFreezeAuditService();

        $phases = [
            ['id' => 'P00', 'hard_dependencies' => []],
            ['id' => 'P01', 'hard_dependencies' => ['P99_NON_EXISTENT']],
        ];

        $result = $service->auditDependencyGraph($phases);

        $this->assertFalse($result->isPassed);
        $this->assertNotEmpty($result->violations);
        $this->assertStringContainsString("depends on non-existent phase 'P99_NON_EXISTENT'", $result->violations[0]);
    }

    public function testAuditScopeFreezePassesWithCleanV1Scope(): void
    {
        $service = new V1ScopeFreezeAuditService();

        $scopeData = [
            'scope' => [
                'V1-MUST' => ['orders', 'billing', 'cpanel-hosting', 'installer'],
                'V1-FOUNDATION' => ['vault', 'database', 'queues'],
                'V1.1' => ['plesk', 'directadmin', 'reseller'],
                'V2+' => ['affiliate', 'live-chat', 'marketplace'],
            ],
        ];

        $activeModules = ['cpanel', 'manual-gateway', 'iyzico'];
        $featureFlags = [
            'enable_cpanel' => true,
            'enable_iyzico' => true,
            'enable_plesk' => false,
        ];

        $result = $service->auditScopeFreeze($scopeData, $activeModules, $featureFlags);

        $this->assertTrue($result->isPassed);
        $this->assertSame(4, $result->v1MustCount);
        $this->assertSame(3, $result->v1FoundationCount);
        $this->assertCount(6, $result->forbiddenScopesChecked);
        $this->assertEmpty($result->violations);
    }

    public function testAuditScopeFreezeFailsWhenForbiddenPostV1ModuleIsActive(): void
    {
        $service = new V1ScopeFreezeAuditService();

        $scopeData = [
            'scope' => [
                'V1-MUST' => ['cpanel-hosting'],
                'V1-FOUNDATION' => ['vault'],
                'V1.1' => ['plesk', 'directadmin'],
                'V2+' => ['marketplace'],
            ],
        ];

        $activeModules = ['cpanel', 'plesk'];

        $result = $service->auditScopeFreeze($scopeData, $activeModules);

        $this->assertFalse($result->isPassed);
        $this->assertNotEmpty($result->violations);
        $this->assertStringContainsString("Forbidden post-V1 module 'plesk' is active", $result->violations[0]);
    }

    public function testAuditScopeFreezeFailsWhenForbiddenFeatureFlagIsEnabled(): void
    {
        $service = new V1ScopeFreezeAuditService();

        $scopeData = [
            'scope' => [
                'V1-MUST' => ['cpanel-hosting'],
                'V1-FOUNDATION' => ['vault'],
                'V1.1' => ['directadmin'],
                'V2+' => ['live-chat', 'marketplace'],
            ],
        ];

        $featureFlags = [
            'live_chat_enabled' => true,
        ];

        $result = $service->auditScopeFreeze($scopeData, [], $featureFlags);

        $this->assertFalse($result->isPassed);
        $this->assertNotEmpty($result->violations);
        $this->assertStringContainsString("Forbidden post-V1 feature flag 'live_chat_enabled' is enabled", $result->violations[0]);
    }

    public function testFullV1AuditAgainstRepositoryPassesCleanly(): void
    {
        $service = new V1ScopeFreezeAuditService();
        $repoRoot = dirname(__DIR__, 3);

        $report = $service->runFullV1Audit($repoRoot);

        $this->assertTrue($report->isPassed, 'Full V1 Release Audit must PASS cleanly. Violations: ' . implode('; ', $report->allViolations));
        $this->assertSame(7, $report->constitutionsVerifiedCount);
        $this->assertSame(18, $report->gatesResult->totalPhasesAudited);
        $this->assertSame(18, $report->gatesResult->passedPhasesCount);
        $this->assertTrue($report->graphResult->isPassed);
        $this->assertTrue($report->scopeResult->isPassed);
        $this->assertEmpty($report->allViolations);

        $arrayReport = $report->toArray();
        $this->assertTrue($arrayReport['is_passed']);
        $this->assertSame(18, $arrayReport['gates_passed']);
    }
}
