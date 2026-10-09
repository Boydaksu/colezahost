<?php

declare(strict_types=1);

namespace Coleza\Domain\Support\Sla;

final class SlaBreachResult
{
    /**
     * @param array<int, int> $firstResponseBreaches
     * @param array<int, int> $resolutionBreaches
     * @param array<int, int> $escalatedTickets
     */
    public function __construct(
        public readonly int $evaluatedTicketsCount,
        public readonly array $firstResponseBreaches = [],
        public readonly array $resolutionBreaches = [],
        public readonly array $escalatedTickets = []
    ) {
    }

    public function hasBreaches(): bool
    {
        return count($this->firstResponseBreaches) > 0 || count($this->resolutionBreaches) > 0;
    }

    public function totalBreaches(): int
    {
        return count(array_unique(array_merge($this->firstResponseBreaches, $this->resolutionBreaches)));
    }
}
