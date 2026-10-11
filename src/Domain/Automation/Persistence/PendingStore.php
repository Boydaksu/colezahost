<?php
declare(strict_types=1);
namespace Coleza\Domain\Automation\Persistence;
use Coleza\Foundation\Database\Connection;
use Coleza\Domain\Automation\Approval\PendingApproval;
use Coleza\Domain\Automation\Delay\DelayedExecution;

final class PendingStore
{
    public function __construct(private readonly Connection $db, private readonly string $kind) {}

    public function add(PendingApproval|DelayedExecution $entry): void
    {
        $this->db->insert('automation_pending', ['id' => $entry->getId(), 'kind' => $this->kind,
            'status' => $entry->getStatus()->value, 'payload' => AutomationCodec::encode(AutomationCodec::pending($entry))]);
    }

    public function find(string $id): PendingApproval|DelayedExecution|null
    {
        $row = $this->db->selectOne('SELECT payload FROM automation_pending WHERE id = ? AND kind = ?' . $this->db->forUpdate(), [$id, $this->kind]);
        return $row === null ? null : AutomationCodec::hydrate(AutomationCodec::decode($row['payload']), $this->kind);
    }

    public function list(string $status): array
    {
        return array_map(fn(array $r) => AutomationCodec::hydrate(AutomationCodec::decode($r['payload']), $this->kind),
            $this->db->select('SELECT payload FROM automation_pending WHERE kind = ? AND status = ? ORDER BY id', [$this->kind, $status]));
    }

    /** Lock before reading, including SQLite. Mutations and their stored payload commit together. */
    public function mutate(string $id, callable $change): mixed
    {
        return $this->db->transaction(function () use ($id, $change) {
            $this->db->lockRow('automation_state_lock', 1);
            if ($this->db->selectOne('SELECT id FROM automation_state_lock WHERE id = 1') === null) {
                throw new \RuntimeException('Automation state lock missing; mutation refused.');
            }
            $entry = $this->find($id);
            $result = $change($entry);
            if ($entry !== null) {
                $this->db->update('automation_pending', ['status' => $entry->getStatus()->value,
                    'payload' => AutomationCodec::encode(AutomationCodec::pending($entry))], 'id = :id AND kind = :kind', ['id' => $id, 'kind' => $this->kind]);
            }
            return $result;
        });
    }
}
