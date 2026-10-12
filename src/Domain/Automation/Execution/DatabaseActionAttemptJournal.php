<?php
declare(strict_types=1);
namespace Coleza\Domain\Automation\Execution;

use Coleza\Foundation\Database\{Connection, PdoSchema};
use Coleza\Domain\Automation\Actions\{ActionDefinition, ActionInterface};
use Coleza\Domain\Automation\Persistence\AutomationCodec;
use Coleza\Domain\Automation\Triggers\TriggerContext;

/** Committed observations, never a provider receipt or permission to retry. */
final class DatabaseActionAttemptJournal
{
    public function __construct(private readonly Connection $db) {}

    public static function installSchema(Connection $db): void
    {
        PdoSchema::autoIncrement($db->getPdo());
        $suffix = $db->getDriverName() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin' : '';
        $db->statement("CREATE TABLE IF NOT EXISTS automation_action_attempts (
            id VARCHAR(64) PRIMARY KEY, dispatch_id VARCHAR(64) NOT NULL,
            action_position INT NOT NULL, rule_id VARCHAR(128) NOT NULL,
            correlation_id VARCHAR(128) NOT NULL, pending_kind VARCHAR(16) NULL,
            pending_id VARCHAR(128) NULL, pending_fingerprint VARCHAR(64) NULL,
            action_id VARCHAR(128) NOT NULL, action_type VARCHAR(128) NOT NULL,
            rule_version INT NOT NULL, rule_fingerprint VARCHAR(64) NULL,
            action_definition_fingerprint VARCHAR(64) NOT NULL,
            planned_input_fingerprint VARCHAR(64) NOT NULL,
            input_fingerprint VARCHAR(64) NOT NULL, status VARCHAR(32) NOT NULL,
            reason_code VARCHAR(64) NULL, created_at VARCHAR(40) NOT NULL,
            started_at VARCHAR(40) NULL, finished_at VARCHAR(40) NULL,
            UNIQUE (dispatch_id, action_position)){$suffix}");
        foreach ([['automation_attempt_pending_idx', 'pending_kind, pending_id'],
            ['automation_attempt_status_idx', 'status, created_at, id']] as [$name, $columns]) {
            if ($db->getDriverName() === 'sqlite') {
                $db->statement("CREATE INDEX IF NOT EXISTS {$name} ON automation_action_attempts ({$columns})");
            } elseif ($db->selectOne('SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', ['automation_action_attempts', $name]) === null) {
                $db->statement("CREATE INDEX {$name} ON automation_action_attempts ({$columns})");
            }
        }
    }

    public function assertOutsideTransaction(): void
    {
        if ($this->db->inTransaction() || $this->db->getPdo()->inTransaction()) {
            throw new \LogicException('Action evidence must commit before a handler; caller transaction refused.');
        }
    }

    /** Validate all inputs and commit the ordered plan before the first effect. */
    public function prepare(string $ruleId, array $actions, TriggerContext $context,
        ?string $pendingKind = null, ?string $pendingId = null, ?string $pendingFingerprint = null, array $snapshot = []): array
    {
        $this->assertOutsideTransaction();
        $dispatch = bin2hex(random_bytes(16));
        $rows = [];
        foreach (array_values($actions) as $position => $action) {
            if (!$action instanceof ActionDefinition) { throw new \InvalidArgumentException('Persistent action journal requires ActionDefinition.'); }
            $rows[] = ['id' => hash('sha256', $dispatch . ':' . $position), 'dispatch_id' => $dispatch,
                'action_position' => $position, 'rule_id' => $ruleId, 'correlation_id' => $context->getCorrelationId(),
                'pending_kind' => $pendingKind, 'pending_id' => $pendingId, 'pending_fingerprint' => $pendingFingerprint,
                'action_id' => $action->getId(), 'action_type' => $action->getType(),
                'rule_version' => $snapshot['rule_version'] ?? 0, 'rule_fingerprint' => $snapshot['rule_fingerprint'] ?? null,
                'action_definition_fingerprint' => $this->definitionFingerprint($action),
                'planned_input_fingerprint' => $this->fingerprint($action, $context),
                'input_fingerprint' => $this->fingerprint($action, $context), 'status' => 'PREPARED',
                'reason_code' => null, 'created_at' => $this->now(), 'started_at' => null, 'finished_at' => null];
        }
        $this->db->transaction(function () use ($rows): void {
            foreach ($rows as $row) { $this->db->insert('automation_action_attempts', $row); }
        });
        return array_column($rows, 'id');
    }

    public function start(string $id, ActionInterface $action, TriggerContext $context): void
    {
        $this->assertOutsideTransaction();
        $updated = $this->db->update('automation_action_attempts', ['status' => 'DISPATCHING',
            'input_fingerprint' => $this->fingerprint($action, $context), 'started_at' => $this->now()],
            'id = :id AND status = :expected AND action_id = :action AND action_type = :type AND action_definition_fingerprint = :definition AND correlation_id = :correlation',
            ['id' => $id, 'expected' => 'PREPARED', 'action' => $action->getId(), 'type' => $action->getType(),
                'definition' => $this->definitionFingerprint($action), 'correlation' => $context->getCorrelationId()]);
        if ($updated !== 1) { throw new \LogicException('Action attempt changed or missing; handler refused.'); }
    }

    public function finish(string $id, string $status, string $reason): void
    {
        $this->assertOutsideTransaction();
        $allowed = ['REPORTED_SUCCESS' => ['HANDLER_REPORTED_SUCCESS'],
            'BLOCKED' => ['PAUSED', 'HANDLER_MISSING', 'ADMISSION_DENIED'],
            'OUTCOME_UNKNOWN' => ['HANDLER_REPORTED_FAILURE', 'HANDLER_SKIPPED', 'HANDLER_EXCEPTION', 'HANDLER_RECEIPT_MISMATCH']];
        if (!in_array($reason, $allowed[$status] ?? [], true)) {
            throw new \InvalidArgumentException('Invalid action observation.');
        }
        $updated = $this->db->update('automation_action_attempts', ['status' => $status, 'reason_code' => $reason,
            'finished_at' => $this->now()], 'id = :id AND status = :expected', ['id' => $id, 'expected' => 'DISPATCHING']);
        if ($updated !== 1) { throw new \LogicException('Action outcome cannot overwrite an existing decision.'); }
    }

    public function listUnresolved(): array
    {
        $result = [];
        foreach ($this->db->select('SELECT * FROM automation_action_attempts ORDER BY dispatch_id, action_position') as $row) {
            if (!in_array($row['status'], ['PREPARED', 'DISPATCHING', 'OUTCOME_UNKNOWN', 'REPORTED_SUCCESS', 'BLOCKED'], true)
                || $row['id'] !== hash('sha256', $row['dispatch_id'] . ':' . $row['action_position'])
                || !preg_match('/^[a-f0-9]{64}$/D', $row['input_fingerprint'])
                || !preg_match('/^[a-f0-9]{64}$/D', $row['planned_input_fingerprint'])
                || !preg_match('/^[a-f0-9]{64}$/D', $row['action_definition_fingerprint'])) {
                throw new \UnexpectedValueException('Invalid action evidence; reconciliation required.');
            }
            if (in_array($row['status'], ['PREPARED', 'DISPATCHING', 'OUTCOME_UNKNOWN'], true)) {
                $result[] = $row + ['requires_reconciliation' => true, 'automatic_retry_allowed' => false, 'provider_outcome_proven' => false];
            }
        }
        return $result;
    }

    private function definitionFingerprint(ActionInterface $action): string
    {
        return hash('sha256', AutomationCodec::encode(['id' => $action->getId(), 'type' => $action->getType(), 'parameters' => $action->getParameters()]));
    }

    private function fingerprint(ActionInterface $action, TriggerContext $context): string
    {
        return hash('sha256', AutomationCodec::encode(['action_id' => $action->getId(), 'action_type' => $action->getType(),
            'parameters' => $action->getParameters(), 'resolved_parameters' => $action->resolveParameters($context),
            'event' => $context->getEventName(), 'context' => $context->all(), 'correlation_id' => $context->getCorrelationId()]));
    }
    private function now(): string { return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.uP'); }
}
