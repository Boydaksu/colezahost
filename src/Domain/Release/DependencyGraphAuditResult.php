<?php

declare(strict_types=1);

namespace Coleza\Domain\Release;

final readonly class DependencyGraphAuditResult
{
    /**
     * @param list<string> $topologicalOrder
     * @param list<string> $violations
     */
    public function __construct(
        public bool $isPassed,
        public int $totalPhasesChecked,
        public array $topologicalOrder = [],
        public array $violations = [],
    ) {}
}
