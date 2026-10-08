<?php

declare(strict_types=1);

namespace Coleza\Domain\Domains\Registrar\Reconciliation;

use Coleza\Foundation\Database\Connection;
use DateTimeImmutable;

final class DomainOperationRepository
{
    private string $table = 'domain_registrar_operations';

    public function __construct(private readonly Connection $db)
    {
    }

    public function ensureTable(): void
    {
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

        $this->db->statement($sql);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): DomainOperation
    {
        $now = date('Y-m-d H:i:s');
        $payloadJson = isset($data['payload']) ? json_encode($data['payload']) : null;
        $resultJson = isset($data['result']) ? json_encode($data['result']) : null;

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
            isset($data['error_message']) ? (string) $data['error_message'] : null,
            $payloadJson,
            $resultJson,
            (int) ($data['attempts'] ?? 1),
            $now,
            $now,
            $now,
        ]);

        $id = (int) $this->db->getPdo()->lastInsertId();
        return $this->findById($id) ?? throw new \RuntimeException('Failed to load created operation');
    }

    public function findById(int $id): ?DomainOperation
    {
        $rows = $this->db->select(
            sprintf('SELECT * FROM %s WHERE id = ? LIMIT 1', $this->table),
            [$id]
        );

        if (empty($rows)) {
            return null;
        }

        return $this->hydrate($rows[0]);
    }

    public function findByIdempotencyKey(string $key): ?DomainOperation
    {
        $rows = $this->db->select(
            sprintf('SELECT * FROM %s WHERE idempotency_key = ? LIMIT 1', $this->table),
            [$key]
        );

        if (empty($rows)) {
            return null;
        }

        return $this->hydrate($rows[0]);
    }

    /**
     * @return list<DomainOperation>
     */
    public function listUncertainOperations(int $limit = 50): array
    {
        $rows = $this->db->select(
            sprintf('SELECT * FROM %s WHERE status = ? ORDER BY id ASC LIMIT %d', $this->table, $limit),
            [DomainOperation::STATUS_UNCERTAIN]
        );

        return array_map([$this, 'hydrate'], $rows);
    }

    public function markProcessing(int $id): void
    {
        $now = date('Y-m-d H:i:s');
        $sql = sprintf(
            'UPDATE %s SET status = ?, attempts = attempts + 1, last_attempt_at = ?, updated_at = ? WHERE id = ?',
            $this->table
        );
        $this->db->statement($sql, [DomainOperation::STATUS_PROCESSING, $now, $now, $id]);
    }

    /**
     * @param array<string, mixed> $result
     */
    public function markSucceeded(int $id, ?string $remoteTransactionId = null, array $result = []): void
    {
        $now = date('Y-m-d H:i:s');
        $resultJson = !empty($result) ? json_encode($result) : null;

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
    }

    /**
     * @param array<string, mixed> $result
     */
    public function markFailed(int $id, string $errorCode, string $errorMessage, array $result = []): void
    {
        $now = date('Y-m-d H:i:s');
        $resultJson = !empty($result) ? json_encode($result) : null;

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
    }

    /**
     * @param array<string, mixed> $result
     */
    public function markUncertain(int $id, string $errorMessage, ?string $errorCode = 'TIMEOUT', array $result = []): void
    {
        $now = date('Y-m-d H:i:s');
        $resultJson = !empty($result) ? json_encode($result) : null;

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
    }

    /**
     * @param array<string, mixed> $result
     */
    public function markReconciled(
        int $id,
        string $status,
        ?string $remoteTransactionId = null,
        ?string $errorMessage = null,
        array $result = []
    ): void {
        $now = date('Y-m-d H:i:s');
        $resultJson = !empty($result) ? json_encode($result) : null;

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
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): DomainOperation
    {
        $payload = [];
        if (!empty($row['payload_json'])) {
            $payload = json_decode((string) $row['payload_json'], true) ?? [];
        }

        $result = [];
        if (!empty($row['result_json'])) {
            $result = json_decode((string) $row['result_json'], true) ?? [];
        }

        return new DomainOperation(
            id: (int) $row['id'],
            domainId: (int) $row['domain_id'],
            operationType: (string) $row['operation_type'],
            idempotencyKey: (string) $row['idempotency_key'],
            status: (string) $row['status'],
            remoteTransactionId: isset($row['remote_transaction_id']) ? (string) $row['remote_transaction_id'] : null,
            errorCode: isset($row['error_code']) ? (string) $row['error_code'] : null,
            errorMessage: isset($row['error_message']) ? (string) $row['error_message'] : null,
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
