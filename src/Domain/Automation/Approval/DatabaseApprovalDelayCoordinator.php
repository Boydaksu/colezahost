<?php
declare(strict_types=1);
namespace Coleza\Domain\Automation\Approval;
use Coleza\Foundation\Database\Connection;
use Coleza\Domain\Automation\Persistence\PendingStore;
use Coleza\Domain\Automation\Delay\{DatabaseDelayManager, DelayedExecution};
use Coleza\Domain\Automation\Execution\{DatabaseRunHistoryRepository, AutomationRunRecord, ExecutionMode, RunStatus};
use Coleza\Domain\Automation\Versioning\DatabaseRuleVersionManager;

final class DatabaseApprovalDelayCoordinator implements ApprovalDelayCoordinatorInterface
{
    public function __construct(private readonly Connection $db) {}
    public function scheduleApproved(string $id, string $approvedBy): DelayedExecution
    {
        return (new PendingStore($this->db, 'approval'))->mutate($id, function (?PendingApproval $request) use ($id, $approvedBy): DelayedExecution {
            if ($request === null) { throw new \InvalidArgumentException('Approval request not found'); }
            $pause = new \Coleza\Domain\Automation\Safety\DatabaseEmergencyPauseManager($this->db);
            if ($pause->isPaused()) { throw new \LogicException('Emergency pause active; handoff deferred'); }
            foreach ($request->getActionsToExecute() as $action) {
                if ($pause->isPaused($action->getType())) { throw new \LogicException('Emergency pause blocks approval handoff'); }
            }
            $delay = new DatabaseDelayManager($this->db);
            if ($request->getDelayedExecutionId() !== null) {
                $linked = $delay->findById($request->getDelayedExecutionId());
                if ($request->getDecidedBy() !== $approvedBy) { throw new \InvalidArgumentException('Approval already decided by another actor'); }
                if ($linked === null || !ApprovalDelayLink::matches($request, $linked, new DatabaseRuleVersionManager($this->db))) {
                    throw new \LogicException('Linked approval/delay state is inconsistent; reconciliation required');
                }
                return $linked;
            }
            $seconds = ApprovalExecutionPolicy::delaySeconds($request, new DatabaseRuleVersionManager($this->db));
            if ($seconds < 1) { throw new \LogicException('Approval has no delayed execution policy'); }
            $request->approve($approvedBy);
            $snapshot = $request->getExecutionSnapshot();
            $snapshot['delay_seconds'] = $seconds;
            $snapshot['approval_id'] = $id;
            $linked = $delay->schedule($request->getRuleId(), $request->getRuleName(), $request->getContext(), $request->getActionsToExecute(), $seconds, $snapshot);
            $request->linkDelay($linked->getId());
            (new DatabaseRunHistoryRepository($this->db))->save(new AutomationRunRecord('approval_delay_' . hash('sha256', $id),
                $request->getRuleId(), $request->getRuleName(), $snapshot['rule_version'] ?? 0, $request->getContext()->getEventName(),
                $request->getContext()->getCorrelationId(), ExecutionMode::ACTIVE, RunStatus::DELAYED,
                $snapshot['condition_passed'] ?? false, $snapshot['branch_taken'] ?? 'UNKNOWN', contextSummary: $request->getContext()->all(),
                metadata: ['stage' => 'APPROVAL_TO_DELAY', 'approval_id' => $id, 'delay_id' => $linked->getId(),
                    'rule_version_unknown' => ($snapshot['rule_version'] ?? 0) === 0, 'condition_unknown' => !array_key_exists('condition_passed', $snapshot),
                    'branch_unknown' => !array_key_exists('branch_taken', $snapshot), 'rule_fingerprint' => $snapshot['rule_fingerprint'] ?? null]));
            return $linked;
        });
    }
}
