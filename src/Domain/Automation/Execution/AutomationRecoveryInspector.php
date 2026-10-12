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
