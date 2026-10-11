<?php
declare(strict_types=1);
namespace Coleza\Domain\Automation\Approval;
use Coleza\Domain\Automation\Persistence\PendingStore;
use Coleza\Domain\Automation\Triggers\TriggerContext;
use Coleza\Foundation\Database\Connection;
use DateTimeImmutable;

final class DatabaseApprovalManager implements ApprovalManagerInterface
{
    private readonly PendingStore $store;
    public function __construct(Connection $db) { $this->store = new PendingStore($db, 'approval'); }
    public function createRequest(string $ruleId, string $ruleName, TriggerContext $context, array $actions, ApprovalRequirement $requirement): PendingApproval
    {
        $entry = (new ApprovalManager())->createRequest($ruleId, $ruleName, $context, $actions, $requirement);
        $this->store->add($entry);
        return $entry;
    }
    public function findById(string $id): ?PendingApproval { return $this->store->find($id); }
    public function listPending(): array { return $this->store->list('PENDING'); }
    public function approve(string $id, string $approvedBy, ?string $notes = null): PendingApproval
    {
        return $this->store->mutate($id, static function (?PendingApproval $entry) use ($approvedBy, $notes) {
            if ($entry === null) { throw new \InvalidArgumentException('Approval request not found'); }
            $entry->approve($approvedBy, $notes);
            return $entry;
        });
    }
    public function reject(string $id, string $rejectedBy, string $notes): PendingApproval
    {
        return $this->store->mutate($id, static function (?PendingApproval $entry) use ($rejectedBy, $notes) {
            if ($entry === null) { throw new \InvalidArgumentException('Approval request not found'); }
            $entry->reject($rejectedBy, $notes);
            return $entry;
        });
    }
    public function processExpirations(?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable();
        $count = 0;
        foreach ($this->listPending() as $candidate) {
            $count += $this->store->mutate($candidate->getId(), static function (?PendingApproval $entry) use ($now): int {
                if ($entry === null || $entry->getStatus() !== ApprovalStatus::PENDING || !$entry->isExpired($now)) { return 0; }
                $entry->expire();
                return 1;
            });
        }
        return $count;
    }
}
