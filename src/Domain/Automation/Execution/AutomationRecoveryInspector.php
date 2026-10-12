<?php
declare(strict_types=1);
namespace Coleza\Domain\Automation\Execution;
use Coleza\Foundation\Database\Connection;
use Coleza\Domain\Automation\Persistence\AutomationCodec;
use Coleza\Domain\Automation\Persistence\PendingStore;
use Coleza\Domain\Automation\Approval\{PendingApproval, ApprovalDelayLink};
use Coleza\Domain\Automation\Delay\DelayedExecution;
use Coleza\Domain\Automation\Versioning\DatabaseRuleVersionManager;

/** Read-only evidence inventory. Never retries an uncertain external operation. */
final class AutomationRecoveryInspector
{
    public function __construct(private readonly Connection $db) {}
    /** Includes direct ACTIVE runs even when no approval/delay or final history exists. */
    public function listUnresolvedActionAttempts(): array
    {
        return (new DatabaseActionAttemptJournal($this->db))->listUnresolved();
    }
    public function listUnresolved(): array
    {
        $attempts = [];
        $dispatchSizes = [];
        $pendingSizes = [];
        foreach ($this->db->select('SELECT * FROM automation_action_attempts') as $attempt) {
            $attempts[$attempt['id']] = $attempt;
            $dispatchSizes[$attempt['dispatch_id']] = ($dispatchSizes[$attempt['dispatch_id']] ?? 0) + 1;
            if ($attempt['pending_kind'] !== null && $attempt['pending_id'] !== null) {
                $kind = $attempt['pending_kind']; $id = $attempt['pending_id'];
                $pendingSizes[$kind][$id] = ($pendingSizes[$kind][$id] ?? 0) + 1;
            }
        }
        $outcomes = [];
        foreach ($this->db->select('SELECT * FROM automation_run_history') as $row) {
            $run = AutomationCodec::decode($row['payload']);
            if (($run['run_id'] ?? null) !== $row['id'] || ($run['rule_id'] ?? null) !== $row['rule_id']
                || ($run['correlation_id'] ?? null) !== $row['correlation_id'] || !is_array($run['metadata'] ?? null)) {
                throw new \UnexpectedValueException('History identity mismatch; reconciliation required');
            }
            ExecutionMode::from($run['execution_mode']); RunStatus::from($run['status']);
            if (($run['metadata']['stage'] ?? null) === 'APPROVAL_TO_DELAY' || $run['execution_mode'] !== ExecutionMode::ACTIVE->value) { continue; }
            foreach (['approval_id' => 'approval', 'delay_id' => 'delay'] as $key => $kind) {
                if (isset($run['metadata'][$key])) {
                    if (!is_string($run['metadata'][$key]) || $run['metadata'][$key] === '') { throw new \UnexpectedValueException('Invalid history pending reference'); }
                    $outcomes[$kind][$run['metadata'][$key]][] = $run;
                }
            }
        }
        $result = [];
        $rows = $this->db->select('SELECT * FROM automation_pending ORDER BY id');
        $entries = [];
        foreach ($rows as $row) { $entries[$row['id']] = PendingStore::hydrateRow($row); }
        $versions = new DatabaseRuleVersionManager($this->db);
        foreach ($rows as $row) {
            $entry = $entries[$row['id']];
            if ($entry instanceof DelayedExecution && isset($entry->getExecutionSnapshot()['approval_id'])) {
                $parent = $entries[$entry->getExecutionSnapshot()['approval_id']] ?? null;
                if (!$parent instanceof PendingApproval || !ApprovalDelayLink::matches($parent, $entry, $versions)) {
                    if ($parent instanceof PendingApproval && $parent->getDelayedExecutionId() === $entry->getId()) { continue; } // Parent reports this same broken link once.
                    $result[] = $this->unresolved($row, $entry, 'LINKED_APPROVAL_MISSING_OR_MISMATCHED');
                    continue;
                }
            }
            if (!in_array($row['status'], ['APPROVED', 'DISPATCHING', 'DISPATCHED'], true)) { continue; }
            if ($entry instanceof PendingApproval && $entry->getDelayedExecutionId() !== null) {
                $linked = $entries[$entry->getDelayedExecutionId()] ?? null;
                if ($linked instanceof DelayedExecution && ApprovalDelayLink::matches($entry, $linked, $versions)) {
                    continue; // The linked delay owns pending/completion/recovery state.
                }
                $result[] = $this->unresolved($row, $entry, 'LINKED_DELAY_MISSING_OR_MISMATCHED');
                continue;
            }
            $records = array_values(array_filter($outcomes[$row['kind']][$row['id']] ?? [], static fn(array $r) =>
                $r['rule_id'] === $entry->getRuleId() && $r['correlation_id'] === $entry->getContext()->getCorrelationId()));
            $completed = count($records) === 1 && PendingExecutionIdentity::matchesSuccessfulOutcome($entry, $records[0])
                && ActionAttemptIdentity::matchesPendingOutcome($entry, $records[0], $attempts, $dispatchSizes, $pendingSizes)
                && $row['status'] !== 'DISPATCHING';
            if ($completed) { continue; }
            $result[] = $this->unresolved($row, $entry, $records === [] ? 'MISSING_OUTCOME' : 'AMBIGUOUS_OR_FAILED_OUTCOME');
        }
        return $result;
    }
    private function unresolved(array $row, PendingApproval|DelayedExecution $entry, string $reason): array
    {
        return ['id' => $row['id'], 'kind' => $row['kind'], 'status' => $row['status'], 'rule_id' => $entry->getRuleId(),
            'correlation_id' => $entry->getContext()->getCorrelationId(), 'execution_snapshot' => $entry->getExecutionSnapshot(),
            'reason' => $reason, 'requires_reconciliation' => true, 'automatic_retry_allowed' => false];
    }
}
