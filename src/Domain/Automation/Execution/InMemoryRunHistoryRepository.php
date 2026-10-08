<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Execution;

final class InMemoryRunHistoryRepository implements RunHistoryRepositoryInterface
{
    /** @var array<string, AutomationRunRecord> */
    private array $records = [];

    public function save(AutomationRunRecord $record): void
    {
        $this->records[$record->getRunId()] = $record;
    }

    public function findById(string $runId): ?AutomationRunRecord
    {
        return $this->records[$runId] ?? null;
    }

    public function findByRuleId(string $ruleId, int $limit = 50): array
    {
        $matched = array_filter(
            $this->records,
            fn (AutomationRunRecord $r) => $r->getRuleId() === $ruleId
        );

        $list = array_values($matched);
        usort($list, fn (AutomationRunRecord $a, AutomationRunRecord $b) => $b->getExecutedAt() <=> $a->getExecutedAt());

        return array_slice($list, 0, $limit);
    }

    public function findByCorrelationId(string $correlationId): array
    {
        $matched = array_filter(
            $this->records,
            fn (AutomationRunRecord $r) => $r->getCorrelationId() === $correlationId
        );

        return array_values($matched);
    }

    public function listRecent(int $limit = 50): array
    {
        $list = array_values($this->records);
        usort($list, fn (AutomationRunRecord $a, AutomationRunRecord $b) => $b->getExecutedAt() <=> $a->getExecutedAt());

        return array_slice($list, 0, $limit);
    }

    public function count(): int
    {
        return count($this->records);
    }

    public function clear(): void
    {
        $this->records = [];
    }
}
