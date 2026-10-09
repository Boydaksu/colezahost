<?php

declare(strict_types=1);

namespace Coleza\Domain\Release;

final readonly class FullReleaseAuditReport
{
    /**
     * @param list<string> $allViolations
     */
    public function __construct(
        public bool $isPassed,
        public string $auditTimestamp,
        public PredecessorGatesAuditResult $gatesResult,
        public DependencyGraphAuditResult $graphResult,
        public ScopeFreezeAuditResult $scopeResult,
        public int $constitutionsVerifiedCount = 7,
        public array $allViolations = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'is_passed' => $this->isPassed,
            'audit_timestamp' => $this->auditTimestamp,
            'constitutions_count' => $this->constitutionsVerifiedCount,
            'gates_audited' => $this->gatesResult->totalPhasesAudited,
            'gates_passed' => $this->gatesResult->passedPhasesCount,
            'graph_passed' => $this->graphResult->isPassed,
            'scope_freeze_passed' => $this->scopeResult->isPassed,
            'violations' => $this->allViolations,
        ];
    }
}
