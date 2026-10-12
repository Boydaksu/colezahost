<?php
declare(strict_types=1);
namespace Coleza\Domain\Automation\Execution;
use Coleza\Foundation\Database\Connection;
use Coleza\Domain\Automation\Persistence\AutomationCodec;

/** Read-only evidence inventory. Never retries an uncertain external operation. */
final class AutomationRecoveryInspector
{
    public function __construct(private readonly Connection $db) {}
    public function listUnresolved(): array
    {
        $outcomes = [];
        foreach ($this->db->select('SELECT payload FROM automation_run_history') as $row) {
            $run = AutomationCodec::decode($row['payload']);
            if (($run['metadata']['stage'] ?? null) === 'APPROVAL_TO_DELAY' || $run['execution_mode'] !== ExecutionMode::ACTIVE->value) { continue; }
            foreach (['approval_id' => 'approval', 'delay_id' => 'delay'] as $key => $kind) {
                if (isset($run['metadata'][$key])) {
                    $outcomes[$kind][$run['metadata'][$key]][] = $run;
                }
            }
        }
        $result = [];
        foreach ($this->db->select("SELECT * FROM automation_pending WHERE status IN ('APPROVED', 'DISPATCHING', 'DISPATCHED') ORDER BY id") as $row) {
            $entry = AutomationCodec::hydrate(AutomationCodec::decode($row['payload']), $row['kind']);
            if ($entry->getStatus()->value !== $row['status']) { throw new \UnexpectedValueException('Pending state mismatch'); }
            if ($entry instanceof \Coleza\Domain\Automation\Approval\PendingApproval && $entry->getDelayedExecutionId() !== null) {
                $linked = (new \Coleza\Domain\Automation\Persistence\PendingStore($this->db, 'delay'))->find($entry->getDelayedExecutionId());
                if ($linked !== null && ($linked->getExecutionSnapshot()['approval_id'] ?? null) === $entry->getId()
                    && $linked->getRuleId() === $entry->getRuleId() && $linked->getContext()->getCorrelationId() === $entry->getContext()->getCorrelationId()) {
                    continue; // The linked delay owns pending/completion/recovery state.
                }
                $result[] = ['id' => $row['id'], 'kind' => 'approval', 'status' => $row['status'], 'rule_id' => $entry->getRuleId(),
                    'correlation_id' => $entry->getContext()->getCorrelationId(), 'execution_snapshot' => $entry->getExecutionSnapshot(),
                    'reason' => 'LINKED_DELAY_MISSING_OR_MISMATCHED', 'requires_reconciliation' => true, 'automatic_retry_allowed' => false];
                continue;
            }
            $records = array_values(array_filter($outcomes[$row['kind']][$row['id']] ?? [], static fn(array $r) =>
                $r['rule_id'] === $entry->getRuleId() && $r['correlation_id'] === $entry->getContext()->getCorrelationId()));
            $completed = count($records) === 1 && $records[0]['status'] === RunStatus::SUCCESS->value
                && $row['status'] !== 'DISPATCHING';
            if ($completed) { continue; }
            $result[] = ['id' => $row['id'], 'kind' => $row['kind'], 'status' => $row['status'], 'rule_id' => $entry->getRuleId(),
                'correlation_id' => $entry->getContext()->getCorrelationId(), 'execution_snapshot' => $entry->getExecutionSnapshot(),
                'reason' => $records === [] ? 'MISSING_OUTCOME' : 'AMBIGUOUS_OR_FAILED_OUTCOME',
                'requires_reconciliation' => true, 'automatic_retry_allowed' => false];
        }
        return $result;
    }
}
