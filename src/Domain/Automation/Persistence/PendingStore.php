<?php
declare(strict_types=1);
namespace Coleza\Domain\Automation\Persistence;
use Coleza\Foundation\Database\Connection;
use Coleza\Domain\Automation\Approval\PendingApproval;
use Coleza\Domain\Automation\Delay\DelayedExecution;

final class PendingStore
{
    public function __construct(private readonly Connection $db, private readonly string $kind, private readonly ?\Coleza\Domain\Vault\Encryptor $encryptor = null) {}

    public function add(PendingApproval|DelayedExecution $entry): void
    {
        $this->db->transaction(function () use ($entry): void {
            $secrets = new AutomationSecretStore($this->db, $this->encryptor); $secrets->guard();
            $owner = ['id' => $entry->getId(), 'kind' => $this->kind, 'status' => $entry->getStatus()->value];
            $owner['payload'] = $secrets->write('automation_pending', $owner, AutomationCodec::pending($entry));
            $this->db->insert('automation_pending', $owner);
        });
    }

    public function find(string $id): PendingApproval|DelayedExecution|null
    {
        return $this->db->transaction(function () use ($id): PendingApproval|DelayedExecution|null {
            (new AutomationSecretStore($this->db, $this->encryptor))->guard();
            $row = $this->db->selectOne('SELECT * FROM automation_pending WHERE id = ? AND kind = ?' . $this->db->forUpdate(), [$id, $this->kind]);
            return $row === null ? null : $this->hydrateRow($row);
        });
    }

    public function list(string $status): array
    {
        return $this->db->transaction(function () use ($status): array {
            (new AutomationSecretStore($this->db, $this->encryptor))->guard();
            return array_map($this->hydrateRow(...),
                $this->db->select('SELECT * FROM automation_pending WHERE kind = ? AND status = ? ORDER BY id' . $this->db->forUpdate(), [$this->kind, $status]));
        });
    }

    public function hydrateRow(array $row, bool $readOnly = false): PendingApproval|DelayedExecution
    {
        $entry = AutomationCodec::hydrate((new AutomationSecretStore($this->db, $this->encryptor))->read('automation_pending', $row, $readOnly), $row['kind']);
        if ($entry->getId() !== $row['id'] || $entry->getStatus()->value !== $row['status']) {
            throw new \UnexpectedValueException('Pending identity or state mismatch; reconciliation required');
        }
        return $entry;
    }

    /** Lock before reading, including SQLite. Mutations and their stored payload commit together. */
    public function mutate(string $id, callable $change): mixed
    {
        return $this->db->transaction(function () use ($id, $change) {
            (new AutomationSecretStore($this->db, $this->encryptor))->guard();
            $this->db->lockRow('automation_state_lock', 1);
            if ($this->db->selectOne('SELECT id FROM automation_state_lock WHERE id = 1') === null) {
                throw new \RuntimeException('Automation state lock missing; mutation refused.');
            }
            $entry = $this->find($id);
            $result = $change($entry);
            if ($entry !== null) {
                $this->db->update('automation_pending', ['status' => $entry->getStatus()->value,
                    'payload' => (new AutomationSecretStore($this->db, $this->encryptor))->write('automation_pending', ['id' => $id, 'kind' => $this->kind, 'status' => $entry->getStatus()->value], AutomationCodec::pending($entry))], 'id = :id AND kind = :kind', ['id' => $id, 'kind' => $this->kind]);
            }
            return $result;
        });
    }
}
