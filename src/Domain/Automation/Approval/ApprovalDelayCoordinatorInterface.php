<?php
declare(strict_types=1);
namespace Coleza\Domain\Automation\Approval;
use Coleza\Domain\Automation\Delay\DelayedExecution;
interface ApprovalDelayCoordinatorInterface
{
    public function scheduleApproved(string $id, string $approvedBy): DelayedExecution;
}
