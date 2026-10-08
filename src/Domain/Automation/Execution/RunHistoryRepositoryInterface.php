<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Execution;

interface RunHistoryRepositoryInterface
{
    public function save(AutomationRunRecord $record): void;

    public function findById(string $runId): ?AutomationRunRecord;

    /**
     * @return array<int, AutomationRunRecord>
     */
    public function findByRuleId(string $ruleId, int $limit = 50): array;

    /**
     * @return array<int, AutomationRunRecord>
     */
    public function findByCorrelationId(string $correlationId): array;

    /**
     * @return array<int, AutomationRunRecord>
     */
    public function listRecent(int $limit = 50): array;

    public function count(): int;
}
