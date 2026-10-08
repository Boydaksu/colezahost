<?php

declare(strict_types=1);

namespace Coleza\Domain\Provisioning\Services;

use Coleza\Domain\Providers\DTO\ProviderOperationResult;
use Coleza\Domain\Provisioning\Classification\ProvisioningErrorClassification;
use Coleza\Domain\Provisioning\Classification\ProvisioningErrorClassifier;
use Coleza\Domain\Provisioning\Entities\ProvisioningAuditLog;
use Coleza\Domain\Provisioning\Entities\ProvisioningOperation;
use Coleza\Foundation\Database\Connection;
use DateTimeImmutable;
use RuntimeException;

final class ProvisioningOperationService
{
    private string $opsTable = 'provisioning_operations';
    private string $auditTable = 'provisioning_audit_logs';

    public function __construct(
        private Connection $db
    ) {
    }

    public function ensureTables(): void
    {
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        // 1. Operations table
        $sqlOps = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                operation_uuid VARCHAR(64) NOT NULL UNIQUE,
                service_id INT NOT NULL,
                server_id INT NULL,
                provider_slug VARCHAR(100) NOT NULL,
                action VARCHAR(50) NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT "queued",
                attempt_count INT NOT NULL DEFAULT 0,
                max_attempts INT NOT NULL DEFAULT 3,
                next_attempt_at TIMESTAMP NULL,
                error_category VARCHAR(50) NULL,
                error_code VARCHAR(100) NULL,
                error_message TEXT NULL,
                admin_message TEXT NULL,
                payload_json TEXT NULL,
                result_json TEXT NULL,
                correlation_id VARCHAR(64) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->opsTable,
            $autoInc
        );
        $this->db->statement($sqlOps);

        // 2. Audit logs table
        $sqlAudit = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                operation_id INT NOT NULL,
                service_id INT NOT NULL,
                action VARCHAR(50) NOT NULL,
                attempt_number INT NOT NULL,
                success INT NOT NULL,
                error_category VARCHAR(50) NULL,
                error_code VARCHAR(100) NULL,
                client_safe_message TEXT NULL,
                admin_actionable_message TEXT NULL,
                raw_response_json TEXT NULL,
                logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->auditTable,
            $autoInc
        );
        $this->db->statement($sqlAudit);
    }

    /**
     * Queue a new provisioning operation.
     *
     * @param array<string, mixed> $payload
     */
    public function queueOperation(
        int $serviceId,
        string $providerSlug,
        string $action,
        array $payload,
        ?int $serverId = null,
        int $maxAttempts = 3,
        ?string $correlationId = null
    ): ProvisioningOperation {
        $uuid = 'OP-' . bin2hex(random_bytes(16));
        $corrId = $correlationId ?? ('REQ-' . bin2hex(random_bytes(12)));
        $now = date('Y-m-d H:i:s');

        $sql = sprintf(
            'INSERT INTO %s (operation_uuid, service_id, server_id, provider_slug, action, status, attempt_count, max_attempts, next_attempt_at, payload_json, correlation_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $this->opsTable
        );

        $this->db->statement($sql, [
            $uuid,
            $serviceId,
            $serverId,
            $providerSlug,
            $action,
            ProvisioningOperation::STATUS_QUEUED,
            0,
            $maxAttempts,
            $now,
            json_encode($payload),
            $corrId,
            $now,
            $now,
        ]);

        $id = (int)$this->db->getPdo()->lastInsertId();
        return $this->findOperationById($id) ?? throw new RuntimeException("Failed to reload newly queued operation {$id}.");
    }

    /**
     * Record an execution attempt outcome.
     */
    public function recordAttempt(
        string $operationUuid,
        ProviderOperationResult $result,
        ?ProvisioningErrorClassification $classification = null
    ): ProvisioningOperation {
        $op = $this->findOperationByUuid($operationUuid);
        if ($op === null) {
            throw new RuntimeException("Provisioning operation '{$operationUuid}' not found.");
        }

        $now = date('Y-m-d H:i:s');
        $newAttemptCount = $op->getAttemptCount() + 1;
        $opId = $op->getId() ?? 0;

        if ($result->isSuccess()) {
            // Success transition
            $sql = sprintf(
                'UPDATE %s SET status = ?, attempt_count = ?, next_attempt_at = NULL, error_category = NULL, error_code = NULL, error_message = NULL, admin_message = NULL, result_json = ?, updated_at = ?
                 WHERE id = ?',
                $this->opsTable
            );
            $this->db->statement($sql, [
                ProvisioningOperation::STATUS_COMPLETED,
                $newAttemptCount,
                json_encode($result->getData()),
                $now,
                $opId,
            ]);

            // Insert audit log
            $sqlAudit = sprintf(
                'INSERT INTO %s (operation_id, service_id, action, attempt_number, success, raw_response_json, logged_at)
                 VALUES (?, ?, ?, ?, 1, ?, ?)',
                $this->auditTable
            );
            $this->db->statement($sqlAudit, [
                $opId,
                $op->getServiceId(),
                $op->getAction(),
                $newAttemptCount,
                json_encode($result->getRawResponse()),
                $now,
            ]);
        } else {
            // Failure transition
            $errClass = $classification ?? ProvisioningErrorClassifier::classifyResult($result);

            $shouldRetry = $errClass->isRetryable() && ($newAttemptCount < $op->getMaxAttempts());
            $newStatus = $shouldRetry ? ProvisioningOperation::STATUS_RETRYING : ProvisioningOperation::STATUS_FAILED;

            $nextAttempt = null;
            if ($shouldRetry) {
                $delay = max(10, $errClass->getSuggestedRetryDelaySeconds());
                $dt = new DateTimeImmutable('now');
                $nextAttempt = $dt->modify("+{$delay} seconds")->format('Y-m-d H:i:s');
            }

            $sql = sprintf(
                'UPDATE %s SET status = ?, attempt_count = ?, next_attempt_at = ?, error_category = ?, error_code = ?, error_message = ?, admin_message = ?, result_json = ?, updated_at = ?
                 WHERE id = ?',
                $this->opsTable
            );
            $this->db->statement($sql, [
                $newStatus,
                $newAttemptCount,
                $nextAttempt,
                $errClass->getCategory(),
                $errClass->getErrorCode(),
                $errClass->getClientSafeMessage(),
                $errClass->getAdminActionableMessage(),
                json_encode($result->getData()),
                $now,
                $opId,
            ]);

            // Insert audit log
            $sqlAudit = sprintf(
                'INSERT INTO %s (operation_id, service_id, action, attempt_number, success, error_category, error_code, client_safe_message, admin_actionable_message, raw_response_json, logged_at)
                 VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?)',
                $this->auditTable
            );
            $this->db->statement($sqlAudit, [
                $opId,
                $op->getServiceId(),
                $op->getAction(),
                $newAttemptCount,
                $errClass->getCategory(),
                $errClass->getErrorCode(),
                $errClass->getClientSafeMessage(),
                $errClass->getAdminActionableMessage(),
                json_encode($result->getRawResponse()),
                $now,
            ]);
        }

        return $this->findOperationById($opId) ?? throw new RuntimeException("Operation {$opId} disappeared.");
    }

    public function findOperationByUuid(string $uuid): ?ProvisioningOperation
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE operation_uuid = ?', $this->opsTable), [$uuid]);
        return $row ? $this->hydrateOperation($row) : null;
    }

    public function findOperationById(int $id): ?ProvisioningOperation
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE id = ?', $this->opsTable), [$id]);
        return $row ? $this->hydrateOperation($row) : null;
    }

    /**
     * @return array<ProvisioningOperation>
     */
    public function listOperationsForService(int $serviceId): array
    {
        $rows = $this->db->select(
            sprintf('SELECT * FROM %s WHERE service_id = ? ORDER BY id DESC', $this->opsTable),
            [$serviceId]
        );
        return array_map([$this, 'hydrateOperation'], $rows);
    }

    public function findActiveOperationForService(int $serviceId, ?string $action = null): ?ProvisioningOperation
    {
        $statuses = [ProvisioningOperation::STATUS_QUEUED, ProvisioningOperation::STATUS_RETRYING];
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));

        if ($action !== null) {
            $sql = sprintf(
                'SELECT * FROM %s WHERE service_id = ? AND action = ? AND status IN (%s) ORDER BY id DESC LIMIT 1',
                $this->opsTable,
                $placeholders
            );
            $params = array_merge([$serviceId, $action], $statuses);
        } else {
            $sql = sprintf(
                'SELECT * FROM %s WHERE service_id = ? AND status IN (%s) ORDER BY id DESC LIMIT 1',
                $this->opsTable,
                $placeholders
            );
            $params = array_merge([$serviceId], $statuses);
        }

        $row = $this->db->selectOne($sql, $params);
        return $row ? $this->hydrateOperation($row) : null;
    }

    /**
     * @return array<ProvisioningOperation>
     */
    public function getDueRetryOperations(?string $referenceTime = null): array
    {
        $refTime = $referenceTime ?? date('Y-m-d H:i:s');
        $rows = $this->db->select(
            sprintf('SELECT * FROM %s WHERE status = ? AND next_attempt_at <= ? ORDER BY next_attempt_at ASC', $this->opsTable),
            [ProvisioningOperation::STATUS_RETRYING, $refTime]
        );
        return array_map([$this, 'hydrateOperation'], $rows);
    }

    /**
     * @return array<ProvisioningAuditLog>
     */
    public function listAuditLogsForOperation(int $operationId): array
    {
        $rows = $this->db->select(
            sprintf('SELECT * FROM %s WHERE operation_id = ? ORDER BY attempt_number ASC', $this->auditTable),
            [$operationId]
        );
        return array_map([$this, 'hydrateAuditLog'], $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateOperation(array $row): ProvisioningOperation
    {
        $classification = null;
        if (!empty($row['error_category'])) {
            $classification = new ProvisioningErrorClassification(
                category: (string)$row['error_category'],
                errorCode: (string)($row['error_code'] ?? ''),
                clientSafeMessage: (string)($row['error_message'] ?? ''),
                adminActionableMessage: (string)($row['admin_message'] ?? '')
            );
        }

        $payload = !empty($row['payload_json']) ? json_decode((string)$row['payload_json'], true) : [];
        $result = !empty($row['result_json']) ? json_decode((string)$row['result_json'], true) : [];

        return new ProvisioningOperation(
            id: (int)$row['id'],
            operationUuid: (string)$row['operation_uuid'],
            serviceId: (int)$row['service_id'],
            serverId: $row['server_id'] !== null ? (int)$row['server_id'] : null,
            providerSlug: (string)$row['provider_slug'],
            action: (string)$row['action'],
            status: (string)$row['status'],
            attemptCount: (int)$row['attempt_count'],
            maxAttempts: (int)$row['max_attempts'],
            nextAttemptAt: $row['next_attempt_at'] !== null ? (string)$row['next_attempt_at'] : null,
            errorClassification: $classification,
            payload: is_array($payload) ? $payload : [],
            resultData: is_array($result) ? $result : [],
            correlationId: (string)$row['correlation_id'],
            createdAt: (string)$row['created_at'],
            updatedAt: (string)$row['updated_at']
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateAuditLog(array $row): ProvisioningAuditLog
    {
        $raw = !empty($row['raw_response_json']) ? json_decode((string)$row['raw_response_json'], true) : [];

        return new ProvisioningAuditLog(
            id: (int)$row['id'],
            operationId: (int)$row['operation_id'],
            serviceId: (int)$row['service_id'],
            action: (string)$row['action'],
            attemptNumber: (int)$row['attempt_number'],
            success: (bool)$row['success'],
            errorCategory: $row['error_category'] !== null ? (string)$row['error_category'] : null,
            errorCode: $row['error_code'] !== null ? (string)$row['error_code'] : null,
            clientSafeMessage: $row['client_safe_message'] !== null ? (string)$row['client_safe_message'] : null,
            adminActionableMessage: $row['admin_actionable_message'] !== null ? (string)$row['admin_actionable_message'] : null,
            rawResponse: is_array($raw) ? $raw : null,
            loggedAt: (string)$row['logged_at']
        );
    }
}
