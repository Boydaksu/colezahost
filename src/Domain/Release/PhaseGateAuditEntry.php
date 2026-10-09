<?php

declare(strict_types=1);

namespace Coleza\Domain\Release;

final readonly class PhaseGateAuditEntry
{
    /**
     * @param list<string> $subphases
     * @param list<string> $hardDependencies
     */
    public function __construct(
        public string $phaseId,
        public string $title,
        public string $decisionStatus,
        public string $decisionPath,
        public array $subphases = [],
        public array $hardDependencies = [],
        public bool $isPass = true,
        public ?string $evaluationDate = null,
        public ?string $notes = null,
    ) {}
}
