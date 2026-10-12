<?php
declare(strict_types=1);
namespace Coleza\Domain\Automation\Approval;

use Coleza\Domain\Automation\Delay\DelayedExecution;
use Coleza\Domain\Automation\Persistence\AutomationCodec;
use Coleza\Domain\Automation\Versioning\RuleVersionManagerInterface;

final class ApprovalDelayLink
{
    public static function matches(PendingApproval $approval, DelayedExecution $delay, RuleVersionManagerInterface $versions): bool
    {
        if ($approval->getStatus() !== ApprovalStatus::APPROVED || $approval->getDelayedExecutionId() !== $delay->getId()
            || $approval->getRuleId() !== $delay->getRuleId()) { return false; }
        $parent = AutomationCodec::pending($approval); $child = AutomationCodec::pending($delay);
        if ($parent['context'] !== $child['context'] || $parent['actions'] !== $child['actions']) { return false; }
        try { $seconds = ApprovalExecutionPolicy::delaySeconds($approval, $versions); }
        catch (\LogicException | \InvalidArgumentException) { return false; }
        $expected = $approval->getExecutionSnapshot();
        $expected['delay_seconds'] = $seconds; $expected['approval_id'] = $approval->getId();
        return $seconds > 0 && $expected === $delay->getExecutionSnapshot()
            && $delay->getExecuteAt() == $delay->getScheduledAt()->modify('+' . $seconds . ' seconds');
    }
}
