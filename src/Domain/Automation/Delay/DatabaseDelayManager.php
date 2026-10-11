<?php
declare(strict_types=1);
namespace Coleza\Domain\Automation\Delay;
use Coleza\Domain\Automation\Persistence\PendingStore;
use Coleza\Domain\Automation\Triggers\TriggerContext;
use Coleza\Foundation\Database\Connection;
use DateTimeImmutable;

final class DatabaseDelayManager implements DelayManagerInterface
{
    private readonly PendingStore $store;
    public function __construct(Connection $db) { $this->store = new PendingStore($db, 'delay'); }
    public function schedule(string $ruleId, string $ruleName, TriggerContext $context, array $actions, int $delaySeconds): DelayedExecution
    {
        $entry = (new DelayManager())->schedule($ruleId, $ruleName, $context, $actions, $delaySeconds);
        $this->store->add($entry);
        return $entry;
    }
    public function findById(string $id): ?DelayedExecution { return $this->store->find($id); }
    public function getDueExecutions(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        return array_values(array_filter($this->store->list('SCHEDULED'), static fn(DelayedExecution $entry) => $entry->isDue($now)));
    }
    public function claimForDispatch(string $id): bool
    {
        return $this->store->mutate($id, static fn(?DelayedExecution $entry): bool =>
            $entry !== null && $entry->isDue(new DateTimeImmutable()) && $entry->claim());
    }
    public function markDispatched(string $id): void
    {
        $this->store->mutate($id, static function (?DelayedExecution $entry): void {
            if ($entry === null) { throw new \InvalidArgumentException('Delayed execution not found'); }
            if ($entry->getStatus() !== DelayStatus::DISPATCHING) { throw new \InvalidArgumentException('Delayed execution must be claimed first'); }
            $entry->markDispatched();
        });
    }
    public function cancel(string $id): void
    {
        $this->store->mutate($id, static function (?DelayedExecution $entry): void { $entry?->cancel(); });
    }
}
