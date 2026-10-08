<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Engine;

use Coleza\Domain\Automation\Triggers\TriggerContext;

final class AutomationExecutionReport
{
    /**
     * @param array<int, RuleExecutionResult> $results
     */
    public function __construct(
        private readonly TriggerContext $context,
        private readonly array $results = [],
        private readonly float $totalDurationMs = 0.0
    ) {
    }

    public function getContext(): TriggerContext
    {
        return $this->context;
    }

    /**
     * @return array<int, RuleExecutionResult>
     */
    public function getResults(): array
    {
        return $this->results;
    }

    public function getTotalDurationMs(): float
    {
        return $this->totalDurationMs;
    }

    /**
     * @return array<int, RuleExecutionResult>
     */
    public function getExecutedResults(): array
    {
        return array_values(array_filter($this->results, fn (RuleExecutionResult $r) => $r->isExecuted()));
    }

    /**
     * @return array<int, RuleExecutionResult>
     */
    public function getSkippedResults(): array
    {
        return array_values(array_filter($this->results, fn (RuleExecutionResult $r) => $r->isSkipped()));
    }

    /**
     * @return array<int, RuleExecutionResult>
     */
    public function getFailedResults(): array
    {
        return array_values(array_filter($this->results, fn (RuleExecutionResult $r) => $r->hasActionFailures()));
    }

    public function hasFailures(): bool
    {
        return count($this->getFailedResults()) > 0;
    }

    public function count(): int
    {
        return count($this->results);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_name' => $this->context->getEventName(),
            'correlation_id' => $this->context->getCorrelationId(),
            'total_rules' => count($this->results),
            'executed_rules' => count($this->getExecutedResults()),
            'skipped_rules' => count($this->getSkippedResults()),
            'failed_rules' => count($this->getFailedResults()),
            'total_duration_ms' => $this->totalDurationMs,
            'results' => array_map(fn (RuleExecutionResult $r) => $r->toArray(), $this->results),
        ];
    }
}
