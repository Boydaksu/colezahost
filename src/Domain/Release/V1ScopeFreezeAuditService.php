<?php

declare(strict_types=1);

namespace Coleza\Domain\Release;

use RuntimeException;

final class V1ScopeFreezeAuditService
{
    /**
     * V1 Release Candidate predecessor phases: P00 to P17.
     */
    public const array PREDECESSOR_PHASES = [
        'P00', 'P01', 'P02', 'P03', 'P04', 'P05', 'P06', 'P07', 'P08',
        'P09', 'P10', 'P11', 'P12', 'P13', 'P14', 'P15', 'P16', 'P17'
    ];

    /**
     * Mandatory 7 Constitutions that must exist and be locked.
     */
    public const array MANDATORY_CONSTITUTIONS = [
        'ARCHITECTURE_CONSTITUTION.md',
        'DATA_CONSTITUTION.md',
        'EXTENSION_CONSTITUTION.md',
        'PRODUCT_CONSTITUTION.md',
        'RELEASE_CONSTITUTION.md',
        'SECURITY_CONSTITUTION.md',
        'UI_CONSTITUTION.md',
    ];

    /**
     * Audits all predecessor phase gates (P00 through P17).
     *
     * @param string $evidenceBasePath Root path of evidence/v1
     * @param array<string, array{title?: string, hard_dependencies?: list<string>, subphases?: list<string>}> $phaseMetadata
     */
    public function auditPredecessorGates(string $evidenceBasePath, array $phaseMetadata = []): PredecessorGatesAuditResult
    {
        $entries = [];
        $violations = [];
        $passedCount = 0;

        foreach (self::PREDECESSOR_PHASES as $phaseId) {
            $phaseDir = rtrim($evidenceBasePath, '/\\') . DIRECTORY_SEPARATOR . $phaseId;
            $decisionPath = $phaseDir . DIRECTORY_SEPARATOR . 'gate-decision.md';

            $meta = $phaseMetadata[$phaseId] ?? [];
            $title = (string) ($meta['title'] ?? "Phase {$phaseId}");
            $hardDeps = (array) ($meta['hard_dependencies'] ?? []);
            $subphases = (array) ($meta['subphases'] ?? []);

            if (!is_dir($phaseDir)) {
                $violations[] = "Missing evidence directory for phase {$phaseId}: {$phaseDir}";
                $entries[] = new PhaseGateAuditEntry(
                    phaseId: $phaseId,
                    title: $title,
                    decisionStatus: 'MISSING',
                    decisionPath: $decisionPath,
                    subphases: $subphases,
                    hardDependencies: $hardDeps,
                    isPass: false,
                    notes: 'Evidence directory does not exist'
                );
                continue;
            }

            if (!is_file($decisionPath)) {
                $violations[] = "Missing gate-decision.md for phase {$phaseId}: {$decisionPath}";
                $entries[] = new PhaseGateAuditEntry(
                    phaseId: $phaseId,
                    title: $title,
                    decisionStatus: 'MISSING',
                    decisionPath: $decisionPath,
                    subphases: $subphases,
                    hardDependencies: $hardDeps,
                    isPass: false,
                    notes: 'gate-decision.md file not found'
                );
                continue;
            }

            $content = (string) file_get_contents($decisionPath);
            $status = $this->extractDecisionStatus($content);
            $evalDate = $this->extractEvaluationDate($content);

            $isPass = ($status === 'PASS');
            if ($isPass) {
                $passedCount++;
            } else {
                $violations[] = "Phase {$phaseId} gate decision is '{$status}', expected 'PASS'";
            }

            $entries[] = new PhaseGateAuditEntry(
                phaseId: $phaseId,
                title: $title,
                decisionStatus: $status,
                decisionPath: $decisionPath,
                subphases: $subphases,
                hardDependencies: $hardDeps,
                isPass: $isPass,
                evaluationDate: $evalDate
            );
        }

        $isPassed = (count($violations) === 0 && $passedCount === count(self::PREDECESSOR_PHASES));

        return new PredecessorGatesAuditResult(
            isPassed: $isPassed,
            totalPhasesAudited: count(self::PREDECESSOR_PHASES),
            passedPhasesCount: $passedCount,
            entries: $entries,
            violations: $violations
        );
    }

    /**
     * Audits topological dependency graph ensuring no circular dependencies,
     * no broken dependencies, and that each phase's hard dependencies are strictly satisfied.
     *
     * @param list<array{id: string, hard_dependencies?: list<string>}> $phases
     */
    public function auditDependencyGraph(array $phases): DependencyGraphAuditResult
    {
        $phaseMap = [];
        $adj = [];
        $inDegree = [];
        $violations = [];

        foreach ($phases as $p) {
            $id = (string) ($p['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $phaseMap[$id] = $p;
            $adj[$id] = [];
            $inDegree[$id] = 0;
        }

        // Build graph
        foreach ($phases as $p) {
            $id = (string) ($p['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $deps = (array) ($p['hard_dependencies'] ?? []);
            foreach ($deps as $dep) {
                $depId = (string) $dep;
                if (!isset($phaseMap[$depId])) {
                    $violations[] = "Phase '{$id}' depends on non-existent phase '{$depId}'";
                    continue;
                }
                // depId -> id
                $adj[$depId][] = $id;
                $inDegree[$id]++;
            }
        }

        // Topological Sort (Kahn's Algorithm)
        $queue = [];
        foreach ($inDegree as $id => $deg) {
            if ($deg === 0) {
                $queue[] = $id;
            }
        }

        $topologicalOrder = [];
        while (!empty($queue)) {
            $curr = array_shift($queue);
            $topologicalOrder[] = $curr;

            foreach ($adj[$curr] as $neighbor) {
                $inDegree[$neighbor]--;
                if ($inDegree[$neighbor] === 0) {
                    $queue[] = $neighbor;
                }
            }
        }

        if (count($topologicalOrder) < count($phaseMap)) {
            $violations[] = "Circular dependency detected among phases in roadmap graph";
        }

        $isPassed = (count($violations) === 0);

        return new DependencyGraphAuditResult(
            isPassed: $isPassed,
            totalPhasesChecked: count($phaseMap),
            topologicalOrder: $topologicalOrder,
            violations: $violations
        );
    }

    /**
     * Audits V1 feature scope freeze against defined scopes,
     * ensuring all V1-MUST and V1-FOUNDATION items are tracked and that NO V1.1/V2+ items
     * are active in enabled features or modules.
     *
     * @param array{scope?: array{V1-MUST?: list<string>, V1-FOUNDATION?: list<string>, V1.1?: list<string>, V2+?: list<string>}} $scopeDefinition
     * @param list<string> $activeModules
     * @param array<string, bool> $featureFlags
     */
    public function auditScopeFreeze(
        array $scopeDefinition,
        array $activeModules = [],
        array $featureFlags = []
    ): ScopeFreezeAuditResult {
        $violations = [];
        $scopes = $scopeDefinition['scope'] ?? $scopeDefinition;

        $v1Must = (array) ($scopes['V1-MUST'] ?? []);
        $v1Foundation = (array) ($scopes['V1-FOUNDATION'] ?? []);
        $v11 = (array) ($scopes['V1.1'] ?? []);
        $v2Plus = (array) ($scopes['V2+'] ?? []);

        if (empty($v1Must)) {
            $violations[] = "Missing or empty V1-MUST scope definition";
        }
        if (empty($v1Foundation)) {
            $violations[] = "Missing or empty V1-FOUNDATION scope definition";
        }

        $forbiddenScopes = array_unique(array_merge($v11, $v2Plus));

        // Check active modules
        foreach ($activeModules as $mod) {
            $norm = strtolower(trim((string) $mod));
            foreach ($forbiddenScopes as $fScope) {
                $normFScope = strtolower(trim((string) $fScope));
                if ($norm === $normFScope || str_contains($norm, $normFScope)) {
                    $violations[] = "Forbidden post-V1 module '{$mod}' is active in V1 release candidate";
                }
            }
        }

        // Check feature flags
        foreach ($featureFlags as $flag => $enabled) {
            if ($enabled !== true) {
                continue;
            }
            $normFlag = strtolower(str_replace(['_', '-'], '', $flag));
            foreach ($forbiddenScopes as $fScope) {
                $normFScope = strtolower(str_replace(['_', '-'], '', (string) $fScope));
                if ($normFlag === $normFScope || str_contains($normFlag, $normFScope)) {
                    $violations[] = "Forbidden post-V1 feature flag '{$flag}' is enabled in V1 release candidate";
                }
            }
        }

        $isPassed = (count($violations) === 0);

        return new ScopeFreezeAuditResult(
            isPassed: $isPassed,
            v1MustCount: count($v1Must),
            v1FoundationCount: count($v1Foundation),
            v1MustScopes: array_values($v1Must),
            v1FoundationScopes: array_values($v1Foundation),
            forbiddenScopesChecked: array_values($forbiddenScopes),
            violations: $violations
        );
    }

    /**
     * Runs full V1 Release Candidate Gate Audit against filesystem root.
     *
     * @param string $rootPath Absolute root directory of Coleza Host repository
     * @param list<string> $activeModules Optional active modules list
     * @param array<string, bool> $featureFlags Optional feature flags list
     */
    public function runFullV1Audit(
        string $rootPath,
        array $activeModules = [],
        array $featureFlags = []
    ): FullReleaseAuditReport {
        $allViolations = [];

        // 1. Audit Constitutions
        $constDir = rtrim($rootPath, '/\\') . DIRECTORY_SEPARATOR . '03-constitution';
        $verifiedConstCount = 0;
        foreach (self::MANDATORY_CONSTITUTIONS as $cName) {
            $cPath = $constDir . DIRECTORY_SEPARATOR . $cName;
            if (!is_file($cPath) || filesize($cPath) < 50) {
                $allViolations[] = "Missing or empty mandatory constitution: {$cName}";
            } else {
                $verifiedConstCount++;
            }
        }

        // 2. Load Roadmap
        $roadmapPath = rtrim($rootPath, '/\\') . DIRECTORY_SEPARATOR . '09-machine-readable' . DIRECTORY_SEPARATOR . 'roadmap.json';
        if (!is_file($roadmapPath)) {
            throw new RuntimeException("Missing roadmap.json at {$roadmapPath}");
        }
        /** @var array{phases?: list<array{id: string, title?: string, hard_dependencies?: list<string>, subphases?: list<array{id: string}>}>} $roadmapData */
        $roadmapData = json_decode((string) file_get_contents($roadmapPath), true) ?? [];
        $rawPhases = (array) ($roadmapData['phases'] ?? []);

        $phaseMeta = [];
        foreach ($rawPhases as $rp) {
            $id = (string) ($rp['id'] ?? '');
            if ($id !== '') {
                $phaseMeta[$id] = [
                    'title' => (string) ($rp['title'] ?? ''),
                    'hard_dependencies' => (array) ($rp['hard_dependencies'] ?? []),
                    'subphases' => array_column((array) ($rp['subphases'] ?? []), 'id'),
                ];
            }
        }

        // 3. Audit Predecessor Gates (P00 through P17)
        $evidenceBase = rtrim($rootPath, '/\\') . DIRECTORY_SEPARATOR . 'evidence' . DIRECTORY_SEPARATOR . 'v1';
        $gatesResult = $this->auditPredecessorGates($evidenceBase, $phaseMeta);
        foreach ($gatesResult->violations as $gv) {
            $allViolations[] = "[Gate Audit] {$gv}";
        }

        // 4. Audit Dependency Graph
        $graphResult = $this->auditDependencyGraph($rawPhases);
        foreach ($graphResult->violations as $grv) {
            $allViolations[] = "[Dependency Graph] {$grv}";
        }

        // 5. Load Scope & Audit Freeze
        $scopePath = rtrim($rootPath, '/\\') . DIRECTORY_SEPARATOR . '09-machine-readable' . DIRECTORY_SEPARATOR . 'scope.json';
        if (!is_file($scopePath)) {
            throw new RuntimeException("Missing scope.json at {$scopePath}");
        }
        /** @var array{scope?: array{V1-MUST?: list<string>, V1-FOUNDATION?: list<string>, V1.1?: list<string>, V2+?: list<string>}} $scopeData */
        $scopeData = json_decode((string) file_get_contents($scopePath), true) ?? [];
        $scopeResult = $this->auditScopeFreeze($scopeData, $activeModules, $featureFlags);
        foreach ($scopeResult->violations as $sv) {
            $allViolations[] = "[Scope Freeze] {$sv}";
        }

        $isPassed = (count($allViolations) === 0);

        return new FullReleaseAuditReport(
            isPassed: $isPassed,
            auditTimestamp: gmdate('Y-m-d\TH:i:s\Z'),
            gatesResult: $gatesResult,
            graphResult: $graphResult,
            scopeResult: $scopeResult,
            constitutionsVerifiedCount: $verifiedConstCount,
            allViolations: $allViolations
        );
    }

    private function extractDecisionStatus(string $content): string
    {
        if (preg_match('/(?:Evaluator\s+Decision|Gatekeeper\s+Formal\s+Decision|Gate\s+Decision|Decision)\s*[\*:]+\s*([A-Za-z_-]+)/i', $content, $matches) === 1) {
            return strtoupper(trim($matches[1]));
        }
        return 'UNKNOWN';
    }

    private function extractEvaluationDate(string $content): ?string
    {
        if (preg_match('/(?:Gate\s+Evaluation\s+Date|Date)\s*[\*:]+\s*([0-9]{4}-[0-9]{2}-[0-9]{2})/i', $content, $matches) === 1) {
            return trim($matches[1]);
        }
        return null;
    }
}
