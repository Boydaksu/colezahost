<?php

declare(strict_types=1);

namespace Coleza\Domain\Domains\Registrar\Reconciliation;

use Coleza\Foundation\Database\Connection;
use DateTimeImmutable;

final class DomainOperationRepository
{
    private string $table = 'domain_registrar_operations';

    public function __construct(private readonly Connection $db, private readonly ?\Coleza\Domain\Vault\Encryptor $encryptor = null)
    {
    }

    public static function schemaRequirements(): array
    {
        return ['domain_registrar_operations' => ['id', 'domain_id', 'operation_type', 'idempotency_key', 'status', 'remote_transaction_id', 'error_code', 'error_message', 'payload_json', 'result_json', 'attempts', 'last_attempt_at', 'reconciled_at', 'created_at', 'updated_at']];
    }
    private function prepare(): void
    {
        \Coleza\Domain\Vault\SecretStorageUpgrade::assertStorage($this->db, self::schemaRequirements()
            + \Coleza\Domain\Vault\VaultService::schemaRequirements() + ['security_audit_logs' => ['id', 'actor_user_id', 'impersonator_user_id', 'event_type', 'target_resource', 'ip_address', 'user_agent', 'payload', 'created_at']]);
    }
    private function store(): \Coleza\Domain\Domains\DomainSecretStore { return new \Coleza\Domain\Domains\DomainSecretStore($this->db, $this->encryptor); }
    private function resultReference(int $id, array $result): ?string
    {
        $owner = $this->db->selectOne('SELECT * FROM domain_registrar_operations WHERE id = ?' . $this->db->forUpdate(), [$id]);
        if ($owner === null) { throw new \RuntimeException('Domain operation not found.'); }
        return $this->store()->write($this->table, $owner, 'result_json', $result === [] ? null : json_encode($result, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    public function ensureTable(): void
    {
        if (\Coleza\Foundation\Database\PdoSchema::useInstalledSchema($this->db, self::schemaRequirements())) {
            \Coleza\Domain\Vault\VaultReferenceStore::ensureSchema($this->db); return;
        }
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                domain_id INT NOT NULL,
                operation_type VARCHAR(50) NOT NULL,
                idempotency_key VARCHAR(128) NOT NULL UNIQUE,
                status VARCHAR(30) NOT NULL,
                remote_transaction_id VARCHAR(128) NULL,
                error_code VARCHAR(64) NULL,
                error_message TEXT NULL,
                payload_json TEXT NULL,
                result_json TEXT NULL,
                attempts INT DEFAULT 1,
                last_attempt_at TIMESTAMP NULL,
                reconciled_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->table,
            $autoInc
        );

        $this->db->statement($sql . ($driver === 'mysql' ? ' ENGINE=InnoDB' : ''));
        \Coleza\Domain\Vault\VaultReferenceStore::ensureSchema($this->db);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(#[\SensitiveParameter] array $data): DomainOperation
    {
        $this->prepare();
        return $this->db->transaction(function () use ($data): DomainOperation {
            $this->store()->guard();
            $now = date('Y-m-d H:i:s');
            $payloadJson = null;
            $resultJson = null;

            $sql = sprintf(
                'INSERT INTO %s (
                    domain_id, operation_type, idempotency_key, status,
                    remote_transaction_id, error_code, error_message,
                    payload_json, result_json, attempts, last_attempt_at, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                $this->table
            );

            $this->db->statement($sql, [
                (int) $data['domain_id'],
                (string) $data['operation_type'],
                (string) $data['idempotency_key'],
                (string) ($data['status'] ?? DomainOperation::STATUS_PENDING),
                isset($data['remote_transaction_id']) ? (string) $data['remote_transaction_id'] : null,
                isset($data['error_code']) ? (string) $data['error_code'] : null,
                isset($data['error_message']) ? \Coleza\Foundation\Security\SecretRedactor::message((string) $data['error_message'], $data) : null,
                $payloadJson,
                $resultJson,
                (int) ($data['attempts'] ?? 1),
                $now,
                $now,
                $now,
            ]);

            $id = (int) $this->db->getPdo()->lastInsertId();
            $owner = $this->db->selectOne('SELECT * FROM domain_registrar_operations WHERE id = ?', [$id]);
            $refs = [];
            foreach (['payload' => 'payload_json', 'result' => 'result_json'] as $field => $column) {
                if (isset($data[$field])) { $refs[$column] = $this->store()->write($this->table, $owner, $column, json_encode($data[$field], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)); }
            }
            if ($refs !== []) { $this->db->update($this->table, $refs, 'id = :id', ['id' => $id]); }
            return $this->findById($id) ?? throw new \RuntimeException('Failed to load created operation');
        });
    }

    public function findById(int $id): ?DomainOperation
    {
        $this->prepare();
        return $this->db->transaction(function () use ($id): ?DomainOperation {
            $this->store()->guard();
            $rows = $this->db->select(
                sprintf('SELECT * FROM %s WHERE id = ? LIMIT 1', $this->table) . $this->db->forUpdate(),
                [$id]
            );

            if (empty($rows)) {
                return null;
            }

            return $this->hydrate($rows[0]);
        });
    }

    public function findByIdempotencyKey(string $key): ?DomainOperation
    {
        $this->prepare();
        return $this->db->transaction(function () use ($key): ?DomainOperation {
            $this->store()->guard();
            $rows = $this->db->select(
                sprintf('SELECT * FROM %s WHERE idempotency_key = ? LIMIT 1', $this->table) . $this->db->forUpdate(),
                [$key]
            );

            if (empty($rows)) {
                return null;
            }

            return $this->hydrate($rows[0]);
        });
    }

    /**
     * @return list<DomainOperation>
     */
    public function listUncertainOperations(int $limit = 50): array
    {
        $this->prepare();
        return $this->db->transaction(function () use ($limit): array {
            $this->store()->guard();
            if ($limit < 0) { throw new \InvalidArgumentException('Limit cannot be negative.'); }
            $rows = $this->db->select(
                sprintf('SELECT * FROM %s WHERE status = ? ORDER BY id ASC LIMIT %d', $this->table, $limit) . $this->db->forUpdate(),
                [DomainOperation::STATUS_UNCERTAIN]
            );

            return array_map([$this, 'hydrate'], $rows);
        });
    }

    public function markProcessing(int $id): void
    {
        $this->prepare();
        $this->db->transaction(function () use ($id): void {
            $this->store()->guard();
            if ($this->findById($id) === null) { throw new \RuntimeException('Domain operation not found.'); }
            $now = date('Y-m-d H:i:s');
            $sql = sprintf(
                'UPDATE %s SET status = ?, attempts = attempts + 1, last_attempt_at = ?, updated_at = ? WHERE id = ?',
                $this->table
            );
            $this->db->statement($sql, [DomainOperation::STATUS_PROCESSING, $now, $now, $id]);
        });
    }

    /**
     * @param array<string, mixed> $result
     */
    public function markSucceeded(int $id, ?string $remoteTransactionId = null, #[\SensitiveParameter] array $result = []): void
    {
        $this->prepare();
        $this->db->transaction(function () use ($id, $remoteTransactionId, $result): void {
            $this->store()->guard();
            if ($this->findById($id) === null) { throw new \RuntimeException('Domain operation not found.'); }
            $now = date('Y-m-d H:i:s');
            $resultJson = $this->resultReference($id, $result);

            $sql = sprintf(
                'UPDATE %s SET status = ?, remote_transaction_id = COALESCE(?, remote_transaction_id), result_json = ?, updated_at = ? WHERE id = ?',
                $this->table
            );
            $this->db->statement($sql, [
                DomainOperation::STATUS_SUCCEEDED,
                $remoteTransactionId,
                $resultJson,
                $now,
                $id,
            ]);
        });
    }

    /**
     * @param array<string, mixed> $result
     */
    public function markFailed(int $id, string $errorCode, string $errorMessage, #[\SensitiveParameter] array $result = []): void
    {
        $this->prepare();
        $this->db->transaction(function () use ($id, $errorCode, $errorMessage, $result): void {
            $this->store()->guard();
            $existing = $this->findById($id);
            if ($existing === null) { throw new \RuntimeException('Domain operation not found.'); }
            if ($errorMessage !== null) { $errorMessage = \Coleza\Foundation\Security\SecretRedactor::message($errorMessage, ['payload' => $existing->getPayload(), 'result' => $result]); }
            $now = date('Y-m-d H:i:s');
            $resultJson = $this->resultReference($id, $result);

            $sql = sprintf(
                'UPDATE %s SET status = ?, error_code = ?, error_message = ?, result_json = ?, updated_at = ? WHERE id = ?',
                $this->table
            );
            $this->db->statement($sql, [
                DomainOperation::STATUS_FAILED,
                $errorCode,
                $errorMessage,
                $resultJson,
                $now,
                $id,
            ]);
        });
    }

    /**
     * @param array<string, mixed> $result
     */
    public function markUncertain(int $id, string $errorMessage, ?string $errorCode = 'TIMEOUT', #[\SensitiveParameter] array $result = []): void
    {
        $this->prepare();
        $this->db->transaction(function () use ($id, $errorMessage, $errorCode, $result): void {
            $this->store()->guard();
            $existing = $this->findById($id);
            if ($existing === null) { throw new \RuntimeException('Domain operation not found.'); }
            if ($errorMessage !== null) { $errorMessage = \Coleza\Foundation\Security\SecretRedactor::message($errorMessage, ['payload' => $existing->getPayload(), 'result' => $result]); }
            $now = date('Y-m-d H:i:s');
            $resultJson = $this->resultReference($id, $result);

            $sql = sprintf(
                'UPDATE %s SET status = ?, error_code = ?, error_message = ?, result_json = ?, updated_at = ? WHERE id = ?',
                $this->table
            );
            $this->db->statement($sql, [
                DomainOperation::STATUS_UNCERTAIN,
                $errorCode,
                $errorMessage,
                $resultJson,
                $now,
                $id,
            ]);
        });
    }

    /**
     * @param array<string, mixed> $result
     */
    public function markReconciled(
        int $id,
        string $status,
        ?string $remoteTransactionId = null,
        ?string $errorMessage = null,
        #[\SensitiveParameter] array $result = []
    ): void {
        $this->prepare();
        $this->db->transaction(function () use ($id, $status, $remoteTransactionId, $errorMessage, $result): void {
            $this->store()->guard();
            $existing = $this->findById($id);
            if ($existing === null) { throw new \RuntimeException('Domain operation not found.'); }
            if ($errorMessage !== null) { $errorMessage = \Coleza\Foundation\Security\SecretRedactor::message($errorMessage, ['payload' => $existing->getPayload(), 'result' => $result]); }
            $now = date('Y-m-d H:i:s');
            $resultJson = $this->resultReference($id, $result);

            $sql = sprintf(
                'UPDATE %s SET status = ?, remote_transaction_id = COALESCE(?, remote_transaction_id), error_message = ?, result_json = ?, reconciled_at = ?, updated_at = ? WHERE id = ?',
                $this->table
            );
            $this->db->statement($sql, [
                $status,
                $remoteTransactionId,
                $errorMessage,
                $resultJson,
                $now,
                $now,
                $id,
            ]);
        });
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): DomainOperation
    {
        $payload = $this->store()->readData($row, 'payload_json');
        $result = $this->store()->readData($row, 'result_json');

        return new DomainOperation(
            id: (int) $row['id'],
            domainId: (int) $row['domain_id'],
            operationType: (string) $row['operation_type'],
            idempotencyKey: (string) $row['idempotency_key'],
            status: (string) $row['status'],
            remoteTransactionId: isset($row['remote_transaction_id']) ? (string) $row['remote_transaction_id'] : null,
            errorCode: isset($row['error_code']) ? (string) $row['error_code'] : null,
            errorMessage: isset($row['error_message']) ? \Coleza\Foundation\Security\SecretRedactor::message((string) $row['error_message'], ['payload' => $payload, 'result' => $result]) : null,
            payload: $payload,
            result: $result,
            attempts: (int) ($row['attempts'] ?? 1),
            lastAttemptAt: !empty($row['last_attempt_at']) ? new DateTimeImmutable((string) $row['last_attempt_at']) : null,
            reconciledAt: !empty($row['reconciled_at']) ? new DateTimeImmutable((string) $row['reconciled_at']) : null,
            createdAt: !empty($row['created_at']) ? new DateTimeImmutable((string) $row['created_at']) : null,
            updatedAt: !empty($row['updated_at']) ? new DateTimeImmutable((string) $row['updated_at']) : null
        );
    }
}
