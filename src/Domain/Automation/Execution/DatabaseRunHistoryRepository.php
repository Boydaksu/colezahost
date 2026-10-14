<?php
declare(strict_types=1);
namespace Coleza\Domain\Automation\Execution;
use Coleza\Foundation\Database\Connection;
use Coleza\Domain\Automation\Persistence\AutomationCodec;
use DateTimeImmutable;

final class DatabaseRunHistoryRepository implements RunHistoryRepositoryInterface
{
    public function __construct(private readonly Connection $db, private readonly ?\Coleza\Domain\Vault\Encryptor $encryptor = null) {}
    public function save(AutomationRunRecord $record): void
    {
        $this->db->transaction(function () use ($record): void {
            $store = new \Coleza\Domain\Automation\Persistence\AutomationSecretStore($this->db, $this->encryptor); $store->guard();
            $data = $record->toArray();
            $data['executed_at'] = $record->getExecutedAt()->format('Y-m-d\TH:i:s.uP');
            $owner = ['id' => $record->getRunId(), 'rule_id' => $record->getRuleId(), 'correlation_id' => $record->getCorrelationId(),
                'executed_at' => (new DateTimeImmutable($data['executed_at']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u')];
            $owner['payload'] = $store->write('automation_run_history', $owner, $data);
            $this->db->insert('automation_run_history', $owner);
        });
    }
    private function hydrate(array $row): AutomationRunRecord
    {
        $d = (new \Coleza\Domain\Automation\Persistence\AutomationSecretStore($this->db, $this->encryptor))->read('automation_run_history', $row);
        return new AutomationRunRecord($d['run_id'], $d['rule_id'], $d['rule_name'], $d['rule_version'], $d['event_name'],
            $d['correlation_id'], ExecutionMode::from($d['execution_mode']), RunStatus::from($d['status']),
            $d['condition_passed'], $d['branch_taken'], $d['action_results'], $d['context_summary'], $d['duration_ms'],
            $d['error_message'], new DateTimeImmutable($d['executed_at']), $d['metadata']);
    }
    public function findById(string $runId): ?AutomationRunRecord
    {
        $row = $this->db->selectOne('SELECT * FROM automation_run_history WHERE id = ?', [$runId]);
        return $row === null ? null : $this->hydrate($row);
    }
    public function findByRuleId(string $ruleId, int $limit = 50): array { return $this->query('rule_id = ?', [$ruleId], $limit); }
    public function findByCorrelationId(string $correlationId): array { return $this->query('correlation_id = ?', [$correlationId]); }
    public function listRecent(int $limit = 50): array { return $this->query('1 = 1', [], $limit); }
    private function query(string $where, array $params, ?int $limit = null): array
    {
        if ($limit !== null && $limit < 0) { throw new \InvalidArgumentException('History limit cannot be negative'); }
        return array_map($this->hydrate(...), $this->db->select('SELECT * FROM automation_run_history WHERE ' . $where
            . ' ORDER BY executed_at DESC, id DESC' . ($limit === null ? '' : ' LIMIT ' . $limit), $params));
    }
    public function count(): int { return (int) $this->db->selectOne('SELECT COUNT(*) AS total FROM automation_run_history')['total']; }
}
