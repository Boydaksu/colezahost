<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Delay;

use Coleza\Domain\Automation\Triggers\TriggerContext;
use DateTimeImmutable;
use InvalidArgumentException;

final class DelayManager implements DelayManagerInterface
{
    /** @var array<string, DelayedExecution> */
    private array $delayed = [];

    public function schedule(
        string $ruleId,
        string $ruleName,
        TriggerContext $context,
        array $actions,
        int $delaySeconds
    ): DelayedExecution {
        $id = 'delay_' . bin2hex(random_bytes(8));
        $now = new DateTimeImmutable();
        $executeAt = $now->modify('+' . max(1, $delaySeconds) . ' seconds');

        $execution = new DelayedExecution(
            $id,
            $ruleId,
            $ruleName,
            $context,
            $actions,
            $executeAt,
            $now
        );

        $this->delayed[$id] = $execution;

        return $execution;
    }

    public function findById(string $id): ?DelayedExecution
    {
        return $this->delayed[$id] ?? null;
    }

    public function getDueExecutions(?DateTimeImmutable $now = null): array
    {
        $currentTime = $now ?? new DateTimeImmutable();

        return array_values(array_filter(
            $this->delayed,
            fn (DelayedExecution $d) => $d->isDue($currentTime)
        ));
    }

    public function markDispatched(string $id): void
    {
        $execution = $this->findById($id);
        if ($execution === null) {
            throw new InvalidArgumentException("Delayed execution '{$id}' not found");
        }

        $execution->markDispatched();
    }

    public function cancel(string $id): void
    {
        $execution = $this->findById($id);
        if ($execution !== null) {
            $execution->cancel();
        }
    }
}
