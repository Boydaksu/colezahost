<?php

declare(strict_types=1);

namespace Coleza\Domain\Release;

final readonly class PredecessorGatesAuditResult
{
    /**
     * @param list<PhaseGateAuditEntry> $entries
     * @param list<string> $violations
     */
    public function __construct(
        public bool $isPassed,
        public int $totalPhasesAudited,
        public int $passedPhasesCount,
        public array $entries = [],
        public array $violations = [],
    ) {}
}
