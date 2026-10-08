<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Approval;

use Coleza\Domain\Automation\Triggers\TriggerContext;
use DateTimeImmutable;
use InvalidArgumentException;

final class ApprovalManager implements ApprovalManagerInterface
{
    /** @var array<string, PendingApproval> */
    private array $requests = [];

    public function createRequest(
        string $ruleId,
        string $ruleName,
        TriggerContext $context,
        array $actions,
        ApprovalRequirement $requirement
    ): PendingApproval {
        $id = 'appr_' . bin2hex(random_bytes(8));
        $now = new DateTimeImmutable();
        $expiresAt = $requirement->getTimeoutSeconds() > 0
            ? $now->modify('+' . $requirement->getTimeoutSeconds() . ' seconds')
            : null;

        $request = new PendingApproval(
            $id,
            $ruleId,
            $ruleName,
            $context,
            $actions,
            $requirement,
            $now,
            $expiresAt
        );

        $this->requests[$id] = $request;

        return $request;
    }

    public function findById(string $id): ?PendingApproval
    {
        return $this->requests[$id] ?? null;
    }

    public function listPending(): array
    {
        return array_values(array_filter(
            $this->requests,
            fn (PendingApproval $p) => $p->getStatus() === ApprovalStatus::PENDING
        ));
    }

    public function approve(string $id, string $approvedBy, ?string $notes = null): PendingApproval
    {
        $request = $this->findById($id);
        if ($request === null) {
            throw new InvalidArgumentException("Approval request '{$id}' not found");
        }

        $request->approve($approvedBy, $notes);
        return $request;
    }

    public function reject(string $id, string $rejectedBy, string $notes): PendingApproval
    {
        $request = $this->findById($id);
        if ($request === null) {
            throw new InvalidArgumentException("Approval request '{$id}' not found");
        }

        $request->reject($rejectedBy, $notes);
        return $request;
    }

    public function processExpirations(?DateTimeImmutable $now = null): int
    {
        $currentTime = $now ?? new DateTimeImmutable();
        $expiredCount = 0;

        foreach ($this->requests as $request) {
            if ($request->getStatus() === ApprovalStatus::PENDING && $request->isExpired($currentTime)) {
                $request->expire();
                $expiredCount++;
            }
        }

        return $expiredCount;
    }
}
