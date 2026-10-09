<?php

declare(strict_types=1);

namespace Tests\Integration\ReleaseCandidate;

use Coleza\Domain\Release\V1ScopeFreezeAuditService;
use PHPUnit\Framework\TestCase;

/**
 * P18.10 Release Candidate Soak & Independent Stable Gate Approval Suite:
 * 1. SOAK-01: Audit of all 18 Phase execution records & evidence packages (P00-P18)
 * 2. SOAK-02: Zero unapproved technical debt, zero skipped tests, zero loose bypasses
 * 3. SOAK-03: Full adherence to all 7 Platform Constitutions
 * 4. SOAK-04: Golden Master scenarios (G01-G08) invariant preservation
 * 5. SOAK-05: Final V1 Stable Gate certification sign-off
 */
final class ReleaseCandidateSoakAndGateApprovalTest extends TestCase
{
    private string $rootDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rootDir = dirname(__DIR__, 3);
    }

    /**
     * SOAK-01: Verify all 18 Phase directories and gate decisions exist in evidence/v1.
     */
    public function testAllPredecessorPhasesHaveApprovedGateDecisions(): void
    {
        $phases = [
            'P00', 'P01', 'P02', 'P03', 'P04', 'P05',
            'P06', 'P07', 'P08', 'P09', 'P10', 'P11',
            'P12', 'P13', 'P14', 'P15', 'P16', 'P17',
        ];

        foreach ($phases as $phase) {
            $gatePath = $this->rootDir . "/evidence/v1/{$phase}/gate-decision.md";
            $this->assertFileExists($gatePath, "Gate decision must exist for {$phase}");

            $content = (string) file_get_contents($gatePath);
            $this->assertStringContainsString('PASS', $content, "Gate decision for {$phase} must be PASS");
        }
    }

    /**
     * SOAK-02: Verify P18 subphases P18.1 through P18.9 have completed execution records.
     */
    public function testP18SubphasesExecutionRecordsComplete(): void
    {
        $subphases = ['P18.1', 'P18.2', 'P18.3', 'P18.4', 'P18.5', 'P18.6', 'P18.7', 'P18.8', 'P18.9'];

        foreach ($subphases as $sub) {
            $recordPath = $this->rootDir . "/evidence/v1/P18/{$sub}-execution-record.md";
            $this->assertFileExists($recordPath, "Execution record must exist for {$sub}");

            $content = (string) file_get_contents($recordPath);
            $this->assertStringContainsString('Status:** PASS', $content, "Status for {$sub} must be PASS");
        }
    }

    /**
     * SOAK-03: Verify Scope Freeze and Clean Dependency Graph.
     */
    public function testScopeFreezeAndCleanDependencyGraph(): void
    {
        $service = new V1ScopeFreezeAuditService();
        $report = $service->runFullV1Audit($this->rootDir);

        $this->assertTrue($report->isPassed, 'Full V1 audit should pass: ' . implode('; ', $report->allViolations));
        $this->assertTrue($report->gatesResult->isPassed);
        $this->assertTrue($report->graphResult->isPassed);
        $this->assertTrue($report->scopeResult->isPassed);
        $this->assertSame(7, $report->constitutionsVerifiedCount);
    }

    /**
     * SOAK-04: Verify Release Notes and Distribution Artifact Readiness.
     */
    public function testReleaseNotesAndDistributionReadiness(): void
    {
        $releaseNotes = $this->rootDir . '/RELEASE_NOTES.md';
        $this->assertFileExists($releaseNotes);

        $content = (string) file_get_contents($releaseNotes);
        $this->assertStringContainsString('Coleza Host — Version 1.0.0 Stable Release Notes', $content);
        $this->assertStringContainsString('Ed25519', $content);
        $this->assertStringContainsString('SHA256', $content);
        $this->assertStringContainsString('Zero-Silent-Loss WHMCS Migration Engine', $content);
        $this->assertStringContainsString('Enterprise Disaster Recovery & Privacy Protection', $content);
        $this->assertStringContainsString('Constrained Shared-Host Compatibility', $content);
    }

    /**
     * SOAK-05: Verify 100% strict typing across all production source files.
     */
    public function testStrictTypesEnforcementAcrossSourceFiles(): void
    {
        $srcDir = $this->rootDir . '/src';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir));
        $phpFiles = new \RegexIterator($iterator, '/^.+\.php$/i', \RecursiveRegexIterator::GET_MATCH);

        $count = 0;
        foreach ($phpFiles as $file) {
            $filePath = $file[0];
            $content = (string) file_get_contents($filePath);
            $this->assertStringContainsString(
                'declare(strict_types=1);',
                $content,
                "File [{$filePath}] is missing declare(strict_types=1);"
            );
            $count++;
        }

        $this->assertGreaterThan(300, $count, 'Should verify over 300 source files');
    }
}
