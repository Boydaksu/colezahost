<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Delay;

use Coleza\Domain\Automation\Actions\ActionInterface;
use Coleza\Domain\Automation\Triggers\TriggerContext;
use DateTimeImmutable;

interface DelayManagerInterface
{
    /**
     * @param array<int, ActionInterface> $actions
     */
    public function schedule(
        string $ruleId,
        string $ruleName,
        TriggerContext $context,
        array $actions,
        int $delaySeconds
    ): DelayedExecution;

    public function findById(string $id): ?DelayedExecution;

    /**
     * @return array<int, DelayedExecution>
     */
    public function getDueExecutions(?DateTimeImmutable $now = null): array;

    public function markDispatched(string $id): void;

    public function cancel(string $id): void;
}
