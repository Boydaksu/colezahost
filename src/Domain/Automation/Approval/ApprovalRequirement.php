<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Approval;

final class ApprovalRequirement
{
    /**
     * @param string $requiredRole Role authorized to approve (e.g. "admin", "billing_manager")
     * @param int $timeoutSeconds Timeout before approval expires (0 = no timeout)
     * @param string $reason Justification or guidance for the human approver
     */
    public function __construct(
        private readonly string $requiredRole = 'admin',
        private readonly int $timeoutSeconds = 86400, // 24 hours default
        private readonly string $reason = 'Manual approval required by automation policy'
    ) {
    }

    public function getRequiredRole(): string
    {
        return $this->requiredRole;
    }

    public function getTimeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
