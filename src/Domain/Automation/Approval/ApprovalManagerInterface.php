<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Approval;

use Coleza\Domain\Automation\Actions\ActionInterface;
use Coleza\Domain\Automation\Triggers\TriggerContext;
use DateTimeImmutable;

interface ApprovalManagerInterface
{
    /**
     * @param array<int, ActionInterface> $actions
     */
    public function createRequest(
        string $ruleId,
        string $ruleName,
        TriggerContext $context,
        array $actions,
        ApprovalRequirement $requirement
    ): PendingApproval;

    public function findById(string $id): ?PendingApproval;

    /**
     * @return array<int, PendingApproval>
     */
    public function listPending(): array;

    public function approve(string $id, string $approvedBy, ?string $notes = null): PendingApproval;

    public function reject(string $id, string $rejectedBy, string $notes): PendingApproval;

    public function processExpirations(?DateTimeImmutable $now = null): int;
}
