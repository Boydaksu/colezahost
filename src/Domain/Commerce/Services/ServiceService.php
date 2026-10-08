<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Services;

use Coleza\Domain\Commerce\Orders\Order;
use Coleza\Domain\Commerce\Recurring\BillingPeriod;
use Coleza\Domain\Pricing\Entities\PriceCycle;
use Coleza\Domain\Commerce\Services\Exceptions\ServiceConcurrencyException;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use RuntimeException;

final class ServiceService
{
    private string $servicesTable = 'services';
    private string $sequencesTable = 'service_sequences';
    private string $placementsTable = 'service_placements';
    private string $cancellationsTable = 'service_cancellations';

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

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                service_number VARCHAR(50) NOT NULL UNIQUE,
                user_id INT NOT NULL,
                organization_id INT NULL,
                order_id INT NULL,
                order_item_id INT NULL,
                product_id INT NOT NULL,
                status VARCHAR(50) NOT NULL DEFAULT "pending",
                billing_cycle VARCHAR(30) NOT NULL,
                recurring_amount_minor INT NOT NULL DEFAULT 0,
                currency_code VARCHAR(3) NOT NULL,
                registration_date VARCHAR(20) NOT NULL,
                next_due_date VARCHAR(20) NOT NULL,
                domain VARCHAR(255) NULL,
                username VARCHAR(100) NULL,
                password_encrypted VARCHAR(255) NULL,
                server_name VARCHAR(100) NULL,
                ip_address VARCHAR(100) NULL,
                suspension_reason VARCHAR(255) NULL,
                termination_date TIMESTAMP NULL,
                notes TEXT NULL,
                metadata_json TEXT NULL,
                auto_renew INT NOT NULL DEFAULT 1,
                grace_period_days INT NOT NULL DEFAULT 7,
                termination_grace_period_days INT NOT NULL DEFAULT 30,
                lock_version INT NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->servicesTable,
            $autoInc
        );
        $this->db->statement($sql);

        try {
            $this->db->statement(sprintf('ALTER TABLE %s ADD COLUMN auto_renew INT NOT NULL DEFAULT 1', $this->servicesTable));
        } catch (\Throwable) {
        }
        try {
            $this->db->statement(sprintf('ALTER TABLE %s ADD COLUMN grace_period_days INT NOT NULL DEFAULT 7', $this->servicesTable));
        } catch (\Throwable) {
        }
        try {
            $this->db->statement(sprintf('ALTER TABLE %s ADD COLUMN termination_grace_period_days INT NOT NULL DEFAULT 30', $this->servicesTable));
        } catch (\Throwable) {
        }
        try {
            $this->db->statement(sprintf('ALTER TABLE %s ADD COLUMN lock_version INT NOT NULL DEFAULT 1', $this->servicesTable));
        } catch (\Throwable) {
        }

        $sqlSeq = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                date_prefix VARCHAR(8) PRIMARY KEY,
                last_number INT NOT NULL DEFAULT 0
            )',
            $this->sequencesTable
        );
        $this->db->statement($sqlSeq);

        $sqlPlacements = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                service_id INT NOT NULL,
                server_id INT NULL,
                server_pool_id INT NULL,
                location_id INT NULL,
                status VARCHAR(50) NOT NULL DEFAULT "unassigned",
                package_identifier VARCHAR(100) NULL,
                dedicated_ip VARCHAR(100) NULL,
                hostname VARCHAR(255) NULL,
                disk_limit_mb INT NOT NULL DEFAULT 0,
                bandwidth_limit_mb INT NOT NULL DEFAULT 0,
                resource_quotas_json TEXT NULL,
                assigned_at TIMESTAMP NULL,
                released_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->placementsTable,
            $autoInc
        );
        $this->db->statement($sqlPlacements);

        $sqlCancellations = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                service_id INT NOT NULL,
                user_id INT NOT NULL,
                type VARCHAR(30) NOT NULL DEFAULT "end_of_period",
                reason TEXT NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT "pending",
                requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                processed_at TIMESTAMP NULL
            )',
            $this->cancellationsTable,
            $autoInc
        );
        $this->db->statement($sqlCancellations);
    }

    /**
     * Create a new service record manually or from order.
     *
     * @param array<string, mixed> $data
     */
    public function createService(array $data): Service
    {
        $userId = (int)($data['user_id'] ?? 0);
        if ($userId <= 0) {
            throw new ValidationException(['user_id' => 'Valid user_id is required.'], 'Invalid service user');
        }

        $productId = (int)($data['product_id'] ?? 0);
        if ($productId <= 0) {
            throw new ValidationException(['product_id' => 'Valid product_id is required.'], 'Invalid product');
        }

        $cycle = (string)($data['billing_cycle'] ?? PriceCycle::MONTHLY);
        if (!PriceCycle::isValid($cycle)) {
            throw new ValidationException(['billing_cycle' => "Invalid billing cycle: {$cycle}"], 'Invalid billing cycle');
        }

        $currencyCode = strtoupper(trim((string)($data['currency_code'] ?? 'USD')));
        $recurringAmountMinor = max(0, (int)($data['recurring_amount_minor'] ?? 0));
        $orgId = isset($data['organization_id']) && $data['organization_id'] !== null ? (int)$data['organization_id'] : null;
        $orderId = isset($data['order_id']) && $data['order_id'] !== null ? (int)$data['order_id'] : null;
        $orderItemId = isset($data['order_item_id']) && $data['order_item_id'] !== null ? (int)$data['order_item_id'] : null;
        $regDate = (string)($data['registration_date'] ?? date('Y-m-d'));
        $nextDueDate = (string)($data['next_due_date'] ?? BillingPeriod::calculateNextDueDate($regDate, $cycle));
        $status = (string)($data['status'] ?? ServiceStateMachine::STATUS_PENDING);

        $domain = isset($data['domain']) ? (string)$data['domain'] : null;
        $username = isset($data['username']) ? (string)$data['username'] : null;
        $password = isset($data['password_encrypted']) ? (string)$data['password_encrypted'] : null;
        $serverName = isset($data['server_name']) ? (string)$data['server_name'] : null;
        $ipAddress = isset($data['ip_address']) ? (string)$data['ip_address'] : null;
        $notes = isset($data['notes']) ? (string)$data['notes'] : null;
        $metadata = (array)($data['metadata'] ?? []);

        $serviceNumber = $this->nextServiceNumber();
        $autoRenew = isset($data['auto_renew']) ? ((bool)$data['auto_renew'] ? 1 : 0) : 1;
        $gracePeriodDays = isset($data['grace_period_days']) ? max(0, (int)$data['grace_period_days']) : 7;
        $termGraceDays = isset($data['termination_grace_period_days']) ? max(0, (int)$data['termination_grace_period_days']) : 30;

        $sql = sprintf(
            'INSERT INTO %s (service_number, user_id, organization_id, order_id, order_item_id, product_id, status, billing_cycle, recurring_amount_minor, currency_code, registration_date, next_due_date, domain, username, password_encrypted, server_name, ip_address, notes, metadata_json, auto_renew, grace_period_days, termination_grace_period_days, lock_version)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
            $this->servicesTable
        );

        $this->db->statement($sql, [
            $serviceNumber,
            $userId,
            $orgId,
            $orderId,
            $orderItemId,
            $productId,
            $status,
            $cycle,
            $recurringAmountMinor,
            $currencyCode,
            $regDate,
            $nextDueDate,
            $domain,
            $username,
            $password,
            $serverName,
            $ipAddress,
            $notes,
            json_encode($metadata),
            $autoRenew,
            $gracePeriodDays,
            $termGraceDays,
        ]);

        $id = (int)$this->db->getPdo()->lastInsertId();

        $service = $this->findServiceById($id);
        if ($service === null) {
            throw new RuntimeException("Failed to load newly created service {$id}.");
        }

        return $service;
    }

    /**
     * Create services from a paid order's items.
     *
     * @return array<Service>
     */
    public function createServicesFromOrder(Order $order): array
    {
        $services = [];
        foreach ($order->getItems() as $item) {
            $metadata = $item->getMetadata();
            $domain = $metadata['domain'] ?? null;

            $service = $this->createService([
                'user_id' => $order->getUserId(),
                'organization_id' => $order->getOrganizationId(),
                'order_id' => $order->getId(),
                'order_item_id' => $item->getId(),
                'product_id' => $item->getProductId(),
                'billing_cycle' => $item->getCycle(),
                'recurring_amount_minor' => $item->getUnitPriceMinor(),
                'currency_code' => $order->getCurrencyCode(),
                'registration_date' => date('Y-m-d'),
                'domain' => $domain,
                'metadata' => $metadata,
            ]);
            $services[] = $service;
        }
        return $services;
    }

    /**
     * Update service properties with optimistic concurrency verification.
     *
     * @param array<string, mixed> $data
     * @throws ServiceConcurrencyException
     */
    public function updateService(int $serviceId, array $data, ?int $expectedLockVersion = null): Service
    {
        $existing = $this->findServiceById($serviceId);
        if ($existing === null) {
            throw new RuntimeException("Service {$serviceId} not found.");
        }

        if ($expectedLockVersion !== null && $existing->getLockVersion() !== $expectedLockVersion) {
            throw ServiceConcurrencyException::versionMismatch($serviceId, $expectedLockVersion, $existing->getLockVersion());
        }

        $fields = [];
        $bindings = [];

        $allowedStringFields = [
            'domain', 'username', 'password_encrypted', 'server_name', 'ip_address', 'notes'
        ];
        foreach ($allowedStringFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $bindings[] = $data[$field] !== null ? (string)$data[$field] : null;
            }
        }

        if (array_key_exists('billing_cycle', $data)) {
            $cycle = strtolower(trim((string)$data['billing_cycle']));
            if (!PriceCycle::isValid($cycle)) {
                throw new ValidationException("Invalid billing cycle '{$cycle}' for service.");
            }
            $fields[] = 'billing_cycle = ?';
            $bindings[] = $cycle;
        }

        if (array_key_exists('recurring_amount_minor', $data)) {
            $fields[] = 'recurring_amount_minor = ?';
            $bindings[] = max(0, (int)$data['recurring_amount_minor']);
        }

        if (array_key_exists('currency_code', $data)) {
            $fields[] = 'currency_code = ?';
            $bindings[] = strtoupper(trim((string)$data['currency_code']));
        }

        if (array_key_exists('registration_date', $data)) {
            $fields[] = 'registration_date = ?';
            $bindings[] = (string)$data['registration_date'];
        }

        if (array_key_exists('next_due_date', $data)) {
            $fields[] = 'next_due_date = ?';
            $bindings[] = (string)$data['next_due_date'];
        }

        if (array_key_exists('metadata', $data)) {
            $fields[] = 'metadata_json = ?';
            $bindings[] = json_encode((array)$data['metadata']);
        }

        if (array_key_exists('auto_renew', $data)) {
            $fields[] = 'auto_renew = ?';
            $bindings[] = (bool)$data['auto_renew'] ? 1 : 0;
        }

        if (array_key_exists('grace_period_days', $data)) {
            $fields[] = 'grace_period_days = ?';
            $bindings[] = max(0, (int)$data['grace_period_days']);
        }

        if (array_key_exists('termination_grace_period_days', $data)) {
            $fields[] = 'termination_grace_period_days = ?';
            $bindings[] = max(0, (int)$data['termination_grace_period_days']);
        }

        if (empty($fields)) {
            return $existing;
        }

        $fields[] = 'lock_version = lock_version + 1';

        if ($expectedLockVersion !== null) {
            $sql = sprintf(
                'UPDATE %s SET %s WHERE id = ? AND lock_version = ?',
                $this->servicesTable,
                implode(', ', $fields)
            );
            $bindings[] = $serviceId;
            $bindings[] = $expectedLockVersion;

            $affected = $this->db->affectingStatement($sql, $bindings);
            if ($affected === 0) {
                $current = $this->findServiceById($serviceId);
                if ($current === null) {
                    throw new RuntimeException("Service {$serviceId} not found.");
                }
                throw ServiceConcurrencyException::versionMismatch($serviceId, $expectedLockVersion, $current->getLockVersion());
            }
        } else {
            $sql = sprintf(
                'UPDATE %s SET %s WHERE id = ?',
                $this->servicesTable,
                implode(', ', $fields)
            );
            $bindings[] = $serviceId;
            $this->db->affectingStatement($sql, $bindings);
        }

        return $this->findServiceById($serviceId) ?? throw new RuntimeException("Failed to reload service {$serviceId}.");
    }

    /**
     * Activate a service and set provisioning parameters.
     *
     * @param array<string, mixed> $provisioningDetails
     */
    public function activateService(int $serviceId, array $provisioningDetails = [], ?int $expectedLockVersion = null): Service
    {
        $service = $this->findServiceById($serviceId);
        if ($service === null) {
            throw new RuntimeException("Service {$serviceId} not found.");
        }

        if ($expectedLockVersion !== null && $service->getLockVersion() !== $expectedLockVersion) {
            throw ServiceConcurrencyException::versionMismatch($serviceId, $expectedLockVersion, $service->getLockVersion());
        }

        ServiceStateMachine::assertCanTransition($service->getStatus(), ServiceStateMachine::STATUS_ACTIVE);

        $domain = $provisioningDetails['domain'] ?? $service->getDomain();
        $username = $provisioningDetails['username'] ?? $service->getUsername();
        $password = $provisioningDetails['password_encrypted'] ?? $service->getPasswordEncrypted();
        $serverName = $provisioningDetails['server_name'] ?? $service->getServerName();
        $ipAddress = $provisioningDetails['ip_address'] ?? $service->getIpAddress();

        if ($expectedLockVersion !== null) {
            $sql = sprintf(
                'UPDATE %s SET status = ?, domain = ?, username = ?, password_encrypted = ?, server_name = ?, ip_address = ?, suspension_reason = NULL, lock_version = lock_version + 1 WHERE id = ? AND lock_version = ?',
                $this->servicesTable
            );
            $affected = $this->db->affectingStatement($sql, [
                ServiceStateMachine::STATUS_ACTIVE,
                $domain,
                $username,
                $password,
                $serverName,
                $ipAddress,
                $serviceId,
                $expectedLockVersion,
            ]);
            if ($affected === 0) {
                $current = $this->findServiceById($serviceId);
                if ($current === null) {
                    throw new RuntimeException("Service {$serviceId} not found.");
                }
                throw ServiceConcurrencyException::versionMismatch($serviceId, $expectedLockVersion, $current->getLockVersion());
            }
        } else {
            $sql = sprintf(
                'UPDATE %s SET status = ?, domain = ?, username = ?, password_encrypted = ?, server_name = ?, ip_address = ?, suspension_reason = NULL, lock_version = lock_version + 1 WHERE id = ?',
                $this->servicesTable
            );
            $this->db->statement($sql, [
                ServiceStateMachine::STATUS_ACTIVE,
                $domain,
                $username,
                $password,
                $serverName,
                $ipAddress,
                $serviceId,
            ]);
        }

        return $this->findServiceById($serviceId) ?? throw new RuntimeException("Failed to reload service {$serviceId}.");
    }

    /**
     * Suspend a service.
     *
     * @throws ServiceConcurrencyException
     */
    public function suspendService(int $serviceId, string $reason, ?int $expectedLockVersion = null): Service
    {
        $service = $this->findServiceById($serviceId);
        if ($service === null) {
            throw new RuntimeException("Service {$serviceId} not found.");
        }

        if ($expectedLockVersion !== null && $service->getLockVersion() !== $expectedLockVersion) {
            throw ServiceConcurrencyException::versionMismatch($serviceId, $expectedLockVersion, $service->getLockVersion());
        }

        ServiceStateMachine::assertCanTransition($service->getStatus(), ServiceStateMachine::STATUS_SUSPENDED);

        if ($expectedLockVersion !== null) {
            $sql = sprintf(
                'UPDATE %s SET status = ?, suspension_reason = ?, lock_version = lock_version + 1 WHERE id = ? AND lock_version = ?',
                $this->servicesTable
            );
            $affected = $this->db->affectingStatement($sql, [ServiceStateMachine::STATUS_SUSPENDED, $reason, $serviceId, $expectedLockVersion]);
            if ($affected === 0) {
                $current = $this->findServiceById($serviceId);
                if ($current === null) {
                    throw new RuntimeException("Service {$serviceId} not found.");
                }
                throw ServiceConcurrencyException::versionMismatch($serviceId, $expectedLockVersion, $current->getLockVersion());
            }
        } else {
            $sql = sprintf('UPDATE %s SET status = ?, suspension_reason = ?, lock_version = lock_version + 1 WHERE id = ?', $this->servicesTable);
            $this->db->statement($sql, [ServiceStateMachine::STATUS_SUSPENDED, $reason, $serviceId]);
        }

        return $this->findServiceById($serviceId) ?? throw new RuntimeException("Failed to reload service {$serviceId}.");
    }

    /**
     * Unsuspend a suspended service.
     *
     * @throws ServiceConcurrencyException
     */
    public function unsuspendService(int $serviceId, ?int $expectedLockVersion = null): Service
    {
        $service = $this->findServiceById($serviceId);
        if ($service === null) {
            throw new RuntimeException("Service {$serviceId} not found.");
        }

        if ($expectedLockVersion !== null && $service->getLockVersion() !== $expectedLockVersion) {
            throw ServiceConcurrencyException::versionMismatch($serviceId, $expectedLockVersion, $service->getLockVersion());
        }

        ServiceStateMachine::assertCanTransition($service->getStatus(), ServiceStateMachine::STATUS_ACTIVE);

        if ($expectedLockVersion !== null) {
            $sql = sprintf(
                'UPDATE %s SET status = ?, suspension_reason = NULL, lock_version = lock_version + 1 WHERE id = ? AND lock_version = ?',
                $this->servicesTable
            );
            $affected = $this->db->affectingStatement($sql, [ServiceStateMachine::STATUS_ACTIVE, $serviceId, $expectedLockVersion]);
            if ($affected === 0) {
                $current = $this->findServiceById($serviceId);
                if ($current === null) {
                    throw new RuntimeException("Service {$serviceId} not found.");
                }
                throw ServiceConcurrencyException::versionMismatch($serviceId, $expectedLockVersion, $current->getLockVersion());
            }
        } else {
            $sql = sprintf('UPDATE %s SET status = ?, suspension_reason = NULL, lock_version = lock_version + 1 WHERE id = ?', $this->servicesTable);
            $this->db->statement($sql, [ServiceStateMachine::STATUS_ACTIVE, $serviceId]);
        }

        return $this->findServiceById($serviceId) ?? throw new RuntimeException("Failed to reload service {$serviceId}.");
    }

    /**
     * Terminate a service permanently.
     *
     * @throws ServiceConcurrencyException
     */
    public function terminateService(int $serviceId, string $reason = '', ?int $expectedLockVersion = null): Service
    {
        $service = $this->findServiceById($serviceId);
        if ($service === null) {
            throw new RuntimeException("Service {$serviceId} not found.");
        }

        if ($expectedLockVersion !== null && $service->getLockVersion() !== $expectedLockVersion) {
            throw ServiceConcurrencyException::versionMismatch($serviceId, $expectedLockVersion, $service->getLockVersion());
        }

        ServiceStateMachine::assertCanTransition($service->getStatus(), ServiceStateMachine::STATUS_TERMINATED);

        $notes = trim($service->getNotes() . "\nTerminated: " . $reason);

        if ($expectedLockVersion !== null) {
            $sql = sprintf(
                'UPDATE %s SET status = ?, termination_date = ?, notes = ?, lock_version = lock_version + 1 WHERE id = ? AND lock_version = ?',
                $this->servicesTable
            );
            $affected = $this->db->affectingStatement($sql, [ServiceStateMachine::STATUS_TERMINATED, date('Y-m-d H:i:s'), $notes, $serviceId, $expectedLockVersion]);
            if ($affected === 0) {
                $current = $this->findServiceById($serviceId);
                if ($current === null) {
                    throw new RuntimeException("Service {$serviceId} not found.");
                }
                throw ServiceConcurrencyException::versionMismatch($serviceId, $expectedLockVersion, $current->getLockVersion());
            }
        } else {
            $sql = sprintf('UPDATE %s SET status = ?, termination_date = ?, notes = ?, lock_version = lock_version + 1 WHERE id = ?', $this->servicesTable);
            $this->db->statement($sql, [ServiceStateMachine::STATUS_TERMINATED, date('Y-m-d H:i:s'), $notes, $serviceId]);
        }

        return $this->findServiceById($serviceId) ?? throw new RuntimeException("Failed to reload service {$serviceId}.");
    }

    /**
     * Cancel a service.
     *
     * @throws ServiceConcurrencyException
     */
    public function cancelService(int $serviceId, string $reason = '', ?int $expectedLockVersion = null): Service
    {
        $service = $this->findServiceById($serviceId);
        if ($service === null) {
            throw new RuntimeException("Service {$serviceId} not found.");
        }

        if ($expectedLockVersion !== null && $service->getLockVersion() !== $expectedLockVersion) {
            throw ServiceConcurrencyException::versionMismatch($serviceId, $expectedLockVersion, $service->getLockVersion());
        }

        ServiceStateMachine::assertCanTransition($service->getStatus(), ServiceStateMachine::STATUS_CANCELLED);

        $notes = trim($service->getNotes() . "\nCancelled: " . $reason);

        if ($expectedLockVersion !== null) {
            $sql = sprintf(
                'UPDATE %s SET status = ?, notes = ?, lock_version = lock_version + 1 WHERE id = ? AND lock_version = ?',
                $this->servicesTable
            );
            $affected = $this->db->affectingStatement($sql, [ServiceStateMachine::STATUS_CANCELLED, $notes, $serviceId, $expectedLockVersion]);
            if ($affected === 0) {
                $current = $this->findServiceById($serviceId);
                if ($current === null) {
                    throw new RuntimeException("Service {$serviceId} not found.");
                }
                throw ServiceConcurrencyException::versionMismatch($serviceId, $expectedLockVersion, $current->getLockVersion());
            }
        } else {
            $sql = sprintf('UPDATE %s SET status = ?, notes = ?, lock_version = lock_version + 1 WHERE id = ?', $this->servicesTable);
            $this->db->statement($sql, [ServiceStateMachine::STATUS_CANCELLED, $notes, $serviceId]);
        }

        return $this->findServiceById($serviceId) ?? throw new RuntimeException("Failed to reload service {$serviceId}.");
    }

    /**
     * Advance next due date on renewal settlement. Reactivates suspended service if suspended for overdue.
     *
     * @throws ServiceConcurrencyException
     */
    public function renewService(int $serviceId, ?int $expectedLockVersion = null): Service
    {
        $service = $this->findServiceById($serviceId);
        if ($service === null) {
            throw new RuntimeException("Service {$serviceId} not found.");
        }

        if ($expectedLockVersion !== null && $service->getLockVersion() !== $expectedLockVersion) {
            throw ServiceConcurrencyException::versionMismatch($serviceId, $expectedLockVersion, $service->getLockVersion());
        }

        $newDueDate = BillingPeriod::calculateNextDueDate($service->getNextDueDate(), $service->getBillingCycle());
        $newStatus = $service->isSuspended() ? ServiceStateMachine::STATUS_ACTIVE : $service->getStatus();
        $suspensionReason = $service->isSuspended() ? null : $service->getSuspensionReason();

        if ($expectedLockVersion !== null) {
            $sql = sprintf(
                'UPDATE %s SET next_due_date = ?, status = ?, suspension_reason = ?, lock_version = lock_version + 1 WHERE id = ? AND lock_version = ?',
                $this->servicesTable
            );
            $affected = $this->db->affectingStatement($sql, [$newDueDate, $newStatus, $suspensionReason, $serviceId, $expectedLockVersion]);
            if ($affected === 0) {
                $current = $this->findServiceById($serviceId);
                if ($current === null) {
                    throw new RuntimeException("Service {$serviceId} not found.");
                }
                throw ServiceConcurrencyException::versionMismatch($serviceId, $expectedLockVersion, $current->getLockVersion());
            }
        } else {
            $sql = sprintf('UPDATE %s SET next_due_date = ?, status = ?, suspension_reason = ?, lock_version = lock_version + 1 WHERE id = ?', $this->servicesTable);
            $this->db->statement($sql, [$newDueDate, $newStatus, $suspensionReason, $serviceId]);
        }

        return $this->findServiceById($serviceId) ?? throw new RuntimeException("Failed to reload service {$serviceId}.");
    }

    /**
     * Assign or update placement for a service.
     *
     * @param array<string, mixed> $data
     */
    public function assignPlacement(int $serviceId, array $data): ServicePlacement
    {
        $service = $this->findServiceById($serviceId);
        if ($service === null) {
            throw new RuntimeException("Service {$serviceId} not found.");
        }

        // Release/evict any active previous placement
        $this->db->statement(
            sprintf(
                'UPDATE %s SET status = ?, released_at = ? WHERE service_id = ? AND released_at IS NULL',
                $this->placementsTable
            ),
            [ServicePlacement::STATUS_EVICTED, date('Y-m-d H:i:s'), $serviceId]
        );

        $serverId = isset($data['server_id']) && $data['server_id'] !== null ? (int)$data['server_id'] : null;
        $serverPoolId = isset($data['server_pool_id']) && $data['server_pool_id'] !== null ? (int)$data['server_pool_id'] : null;
        $locationId = isset($data['location_id']) && $data['location_id'] !== null ? (int)$data['location_id'] : null;
        $status = (string)($data['status'] ?? ServicePlacement::STATUS_PLACED);
        $packageIdentifier = isset($data['package_identifier']) ? (string)$data['package_identifier'] : null;
        $dedicatedIp = isset($data['dedicated_ip']) ? (string)$data['dedicated_ip'] : null;
        $hostname = isset($data['hostname']) ? (string)$data['hostname'] : null;
        $diskLimitMb = max(0, (int)($data['disk_limit_mb'] ?? 0));
        $bandwidthLimitMb = max(0, (int)($data['bandwidth_limit_mb'] ?? 0));
        $resourceQuotas = (array)($data['resource_quotas'] ?? []);
        $assignedAt = date('Y-m-d H:i:s');

        $sql = sprintf(
            'INSERT INTO %s (service_id, server_id, server_pool_id, location_id, status, package_identifier, dedicated_ip, hostname, disk_limit_mb, bandwidth_limit_mb, resource_quotas_json, assigned_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $this->placementsTable
        );

        $this->db->statement($sql, [
            $serviceId,
            $serverId,
            $serverPoolId,
            $locationId,
            $status,
            $packageIdentifier,
            $dedicatedIp,
            $hostname,
            $diskLimitMb,
            $bandwidthLimitMb,
            json_encode($resourceQuotas),
            $assignedAt,
        ]);

        // If dedicated IP or hostname provided, sync to service server_name / ip_address if blank
        $serverName = $hostname ?? $service->getServerName();
        $ip = $dedicatedIp ?? $service->getIpAddress();
        if ($serverName !== $service->getServerName() || $ip !== $service->getIpAddress()) {
            $this->db->statement(
                sprintf('UPDATE %s SET server_name = ?, ip_address = ? WHERE id = ?', $this->servicesTable),
                [$serverName, $ip, $serviceId]
            );
        }

        $placement = $this->getPlacementForService($serviceId);
        if ($placement === null) {
            throw new RuntimeException("Failed to load assigned placement for service {$serviceId}.");
        }
        return $placement;
    }

    /**
     * Release placement for a service.
     */
    public function releasePlacement(int $serviceId, string $status = ServicePlacement::STATUS_EVICTED): ?ServicePlacement
    {
        $this->db->statement(
            sprintf(
                'UPDATE %s SET status = ?, released_at = ? WHERE service_id = ? AND released_at IS NULL',
                $this->placementsTable
            ),
            [$status, date('Y-m-d H:i:s'), $serviceId]
        );

        return $this->getPlacementForService($serviceId);
    }

    public function getPlacementForService(int $serviceId): ?ServicePlacement
    {
        try {
            $row = $this->db->selectOne(
                sprintf('SELECT * FROM %s WHERE service_id = ? ORDER BY id DESC LIMIT 1', $this->placementsTable),
                [$serviceId]
            );
            if (!$row) {
                return null;
            }
            return $this->hydratePlacement($row);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Request cancellation for a service.
     */
    public function requestCancellation(
        int $serviceId,
        int $userId,
        string $type = ServiceCancellationRequest::TYPE_END_OF_PERIOD,
        string $reason = ''
    ): ServiceCancellationRequest {
        $service = $this->findServiceById($serviceId);
        if ($service === null) {
            throw new RuntimeException("Service {$serviceId} not found.");
        }

        if ($service->getUserId() !== $userId) {
            throw new ValidationException(['user_id' => 'Unauthorized service cancellation request.'], 'Unauthorized cancellation');
        }

        if ($service->isTerminated() || $service->isCancelled()) {
            throw new ValidationException(['status' => 'Cannot cancel a service that is already terminated or cancelled.'], 'Service already inactive');
        }

        $validTypes = [ServiceCancellationRequest::TYPE_IMMEDIATE, ServiceCancellationRequest::TYPE_END_OF_PERIOD];
        if (!in_array($type, $validTypes, true)) {
            throw new ValidationException(['type' => "Invalid cancellation type: {$type}"], 'Invalid cancellation type');
        }

        $existing = $this->db->selectOne(
            sprintf('SELECT id FROM %s WHERE service_id = ? AND status = ?', $this->cancellationsTable),
            [$serviceId, ServiceCancellationRequest::STATUS_PENDING]
        );
        if ($existing) {
            throw new ValidationException(['service_id' => 'A pending cancellation request already exists for this service.'], 'Cancellation already pending');
        }

        $now = date('Y-m-d H:i:s');
        if ($type === ServiceCancellationRequest::TYPE_IMMEDIATE) {
            $sql = sprintf(
                'INSERT INTO %s (service_id, user_id, type, reason, status, requested_at, processed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                $this->cancellationsTable
            );
            $this->db->statement($sql, [
                $serviceId,
                $userId,
                $type,
                $reason,
                ServiceCancellationRequest::STATUS_PROCESSED,
                $now,
                $now,
            ]);

            $this->cancelService($serviceId, $reason);
            $this->releasePlacement($serviceId, ServicePlacement::STATUS_EVICTED);
        } else {
            $sql = sprintf(
                'INSERT INTO %s (service_id, user_id, type, reason, status, requested_at, processed_at)
                 VALUES (?, ?, ?, ?, ?, ?, NULL)',
                $this->cancellationsTable
            );
            $this->db->statement($sql, [
                $serviceId,
                $userId,
                $type,
                $reason,
                ServiceCancellationRequest::STATUS_PENDING,
                $now,
            ]);
        }

        $cancellation = $this->getCancellationForService($serviceId);
        if ($cancellation === null) {
            throw new RuntimeException("Failed to load cancellation request for service {$serviceId}.");
        }
        return $cancellation;
    }

    /**
     * Revoke a pending cancellation request.
     */
    public function revokeCancellation(int $serviceId, int $userId): ServiceCancellationRequest
    {
        $row = $this->db->selectOne(
            sprintf('SELECT * FROM %s WHERE service_id = ? AND status = ? ORDER BY id DESC LIMIT 1', $this->cancellationsTable),
            [$serviceId, ServiceCancellationRequest::STATUS_PENDING]
        );

        if (!$row) {
            throw new RuntimeException("No pending cancellation request found for service {$serviceId}.");
        }

        if ((int)$row['user_id'] !== $userId) {
            throw new ValidationException(['user_id' => 'Unauthorized cancellation revocation.'], 'Unauthorized revocation');
        }

        $now = date('Y-m-d H:i:s');
        $this->db->statement(
            sprintf('UPDATE %s SET status = ?, processed_at = ? WHERE id = ?', $this->cancellationsTable),
            [ServiceCancellationRequest::STATUS_REVOKED, $now, (int)$row['id']]
        );

        $cancellation = $this->getCancellationForService($serviceId);
        if ($cancellation === null) {
            throw new RuntimeException("Failed to reload cancellation request for service {$serviceId}.");
        }
        return $cancellation;
    }

    public function getCancellationForService(int $serviceId): ?ServiceCancellationRequest
    {
        try {
            $row = $this->db->selectOne(
                sprintf('SELECT * FROM %s WHERE service_id = ? ORDER BY id DESC LIMIT 1', $this->cancellationsTable),
                [$serviceId]
            );
            if (!$row) {
                return null;
            }
            return $this->hydrateCancellation($row);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Process pending cancellations whose next_due_date has arrived.
     *
     * @return array<array<string, mixed>>
     */
    public function processDueCancellations(?string $referenceDate = null): array
    {
        $refDate = $referenceDate ?? date('Y-m-d');
        $sql = sprintf(
            'SELECT c.id as cancellation_id, c.service_id, c.reason, s.next_due_date
             FROM %s c
             JOIN %s s ON c.service_id = s.id
             WHERE c.status = ? AND c.type = ? AND s.next_due_date <= ?',
            $this->cancellationsTable,
            $this->servicesTable
        );

        $rows = $this->db->select($sql, [
            ServiceCancellationRequest::STATUS_PENDING,
            ServiceCancellationRequest::TYPE_END_OF_PERIOD,
            $refDate,
        ]);

        $processed = [];
        $now = date('Y-m-d H:i:s');

        foreach ($rows as $row) {
            $serviceId = (int)$row['service_id'];
            $cancellationId = (int)$row['cancellation_id'];
            $reason = (string)$row['reason'];

            $this->cancelService($serviceId, $reason);
            $this->releasePlacement($serviceId, ServicePlacement::STATUS_EVICTED);

            $this->db->statement(
                sprintf('UPDATE %s SET status = ?, processed_at = ? WHERE id = ?', $this->cancellationsTable),
                [ServiceCancellationRequest::STATUS_PROCESSED, $now, $cancellationId]
            );

            $processed[] = [
                'cancellation_id' => $cancellationId,
                'service_id' => $serviceId,
                'reason' => $reason,
                'next_due_date' => $row['next_due_date'],
            ];
        }

        return $processed;
    }

    /**
     * Evaluate service billing lifecycle status relative to a reference date.
     *
     * @return array<string, mixed>
     */
    public function evaluateBillingLifecycle(int $serviceId, ?string $referenceDate = null): array
    {
        $service = $this->findServiceById($serviceId);
        if ($service === null) {
            throw new RuntimeException("Service {$serviceId} not found.");
        }

        $billing = $service->getBillingRelation();
        $isOverdue = $billing->isOverdue($referenceDate);
        $daysOverdue = $billing->daysOverdue($referenceDate);
        $isSuspensionDue = $billing->isSuspensionDue($referenceDate);
        $isTerminationDue = $billing->isTerminationDue($referenceDate);

        $recommendedAction = match (true) {
            $service->isTerminated() || $service->isCancelled() => 'none',
            $service->hasPendingCancellation() && $isOverdue => 'process_cancellation',
            $service->isSuspended() && $isTerminationDue => 'terminate',
            $service->isActive() && $isSuspensionDue => 'suspend',
            $isOverdue => 'send_reminder',
            default => 'none',
        };

        return [
            'service_id' => $serviceId,
            'service_number' => $service->getServiceNumber(),
            'status' => $service->getStatus(),
            'next_due_date' => $service->getNextDueDate(),
            'next_invoice_date' => $billing->getNextInvoiceDate(),
            'is_overdue' => $isOverdue,
            'days_overdue' => $daysOverdue,
            'grace_period_days' => $billing->getGracePeriodDays(),
            'termination_grace_period_days' => $billing->getTerminationGracePeriodDays(),
            'is_suspension_due' => $isSuspensionDue,
            'is_termination_due' => $isTerminationDue,
            'recommended_action' => $recommendedAction,
            'has_pending_cancellation' => $service->hasPendingCancellation(),
        ];
    }

    /**
     * Process overdue services: automatically suspend or terminate services based on grace thresholds.
     *
     * @return array<array<string, mixed>>
     */
    public function processOverdueServices(?string $referenceDate = null): array
    {
        $refDate = $referenceDate ?? date('Y-m-d');
        $actions = [];

        // 1. Check suspended services for termination
        $suspended = $this->listServicesByStatus(ServiceStateMachine::STATUS_SUSPENDED);
        foreach ($suspended as $service) {
            $billing = $service->getBillingRelation();
            if ($billing->isTerminationDue($refDate)) {
                $days = $billing->daysOverdue($refDate);
                $this->terminateService($service->getId(), "Automatic termination: overdue by {$days} days exceeding termination grace threshold");
                $this->releasePlacement($service->getId(), ServicePlacement::STATUS_EVICTED);
                $actions[] = [
                    'service_id' => $service->getId(),
                    'action' => 'terminated',
                    'days_overdue' => $days,
                ];
            }
        }

        // 2. Check active services for suspension
        $active = $this->listServicesByStatus(ServiceStateMachine::STATUS_ACTIVE);
        foreach ($active as $service) {
            $billing = $service->getBillingRelation();
            if ($billing->isSuspensionDue($refDate)) {
                $days = $billing->daysOverdue($refDate);
                $this->suspendService($service->getId(), "Automatic suspension: overdue by {$days} days exceeding grace threshold");
                $actions[] = [
                    'service_id' => $service->getId(),
                    'action' => 'suspended',
                    'days_overdue' => $days,
                ];
            }
        }

        return $actions;
    }

    public function findServiceById(int $id): ?Service
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE id = ?', $this->servicesTable), [$id]);
        return $row ? $this->hydrateService($row) : null;
    }

    public function findServiceByNumber(string $serviceNumber): ?Service
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE service_number = ?', $this->servicesTable), [$serviceNumber]);
        return $row ? $this->hydrateService($row) : null;
    }

    /**
     * @return array<Service>
     */
    public function listServicesForUser(int $userId, ?int $orgId = null): array
    {
        $sql = sprintf('SELECT * FROM %s WHERE user_id = ?', $this->servicesTable);
        $params = [$userId];

        if ($orgId !== null) {
            $sql .= ' AND organization_id = ?';
            $params[] = $orgId;
        }

        $sql .= ' ORDER BY id DESC';
        $rows = $this->db->select($sql, $params);
        return array_map([$this, 'hydrateService'], $rows);
    }

    /**
     * @return array<Service>
     */
    public function listServicesByStatus(string $status): array
    {
        $rows = $this->db->select(sprintf('SELECT * FROM %s WHERE status = ? ORDER BY id DESC', $this->servicesTable), [$status]);
        return array_map([$this, 'hydrateService'], $rows);
    }

    public function nextServiceNumber(): string
    {
        $date = date('Ymd');

        $this->db->statement(
            sprintf(
                'INSERT INTO %s (date_prefix, last_number) VALUES (?, 1)
                 ON CONFLICT(date_prefix) DO UPDATE SET last_number = last_number + 1',
                $this->sequencesTable
            ),
            [$date]
        );

        $row = $this->db->selectOne(
            sprintf('SELECT last_number FROM %s WHERE date_prefix = ?', $this->sequencesTable),
            [$date]
        );

        $num = $row ? (int)$row['last_number'] : 1;
        $formattedNum = str_pad((string)$num, 6, '0', STR_PAD_LEFT);

        return "SRV-{$date}-{$formattedNum}";
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydratePlacement(array $row): ServicePlacement
    {
        $quotas = !empty($row['resource_quotas_json']) ? json_decode((string)$row['resource_quotas_json'], true) : [];

        return new ServicePlacement(
            id: (int)$row['id'],
            serviceId: (int)$row['service_id'],
            serverId: $row['server_id'] !== null ? (int)$row['server_id'] : null,
            serverPoolId: $row['server_pool_id'] !== null ? (int)$row['server_pool_id'] : null,
            locationId: $row['location_id'] !== null ? (int)$row['location_id'] : null,
            status: (string)($row['status'] ?? ServicePlacement::STATUS_UNASSIGNED),
            packageIdentifier: $row['package_identifier'] !== null ? (string)$row['package_identifier'] : null,
            dedicatedIp: $row['dedicated_ip'] !== null ? (string)$row['dedicated_ip'] : null,
            hostname: $row['hostname'] !== null ? (string)$row['hostname'] : null,
            diskLimitMb: (int)($row['disk_limit_mb'] ?? 0),
            bandwidthLimitMb: (int)($row['bandwidth_limit_mb'] ?? 0),
            resourceQuotas: is_array($quotas) ? $quotas : [],
            assignedAt: $row['assigned_at'] !== null ? (string)$row['assigned_at'] : null,
            releasedAt: $row['released_at'] !== null ? (string)$row['released_at'] : null
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateCancellation(array $row): ServiceCancellationRequest
    {
        return new ServiceCancellationRequest(
            id: (int)$row['id'],
            serviceId: (int)$row['service_id'],
            userId: (int)$row['user_id'],
            type: (string)($row['type'] ?? ServiceCancellationRequest::TYPE_END_OF_PERIOD),
            reason: (string)($row['reason'] ?? ''),
            status: (string)($row['status'] ?? ServiceCancellationRequest::STATUS_PENDING),
            requestedAt: $row['requested_at'] !== null ? (string)$row['requested_at'] : null,
            processedAt: $row['processed_at'] !== null ? (string)$row['processed_at'] : null
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateService(array $row): Service
    {
        $meta = !empty($row['metadata_json']) ? json_decode((string)$row['metadata_json'], true) : [];
        $serviceId = (int)$row['id'];

        $billingRelation = new ServiceBillingRelation(
            billingCycle: (string)$row['billing_cycle'],
            recurringAmountMinor: (int)$row['recurring_amount_minor'],
            currencyCode: (string)$row['currency_code'],
            registrationDate: (string)$row['registration_date'],
            nextDueDate: (string)$row['next_due_date'],
            autoRenew: isset($row['auto_renew']) ? (bool)$row['auto_renew'] : true,
            gracePeriodDays: isset($row['grace_period_days']) ? (int)$row['grace_period_days'] : 7,
            terminationGracePeriodDays: isset($row['termination_grace_period_days']) ? (int)$row['termination_grace_period_days'] : 30
        );

        $placement = $this->getPlacementForService($serviceId);
        $cancellationRequest = $this->getCancellationForService($serviceId);

        return new Service(
            id: $serviceId,
            serviceNumber: (string)$row['service_number'],
            userId: (int)$row['user_id'],
            organizationId: $row['organization_id'] !== null ? (int)$row['organization_id'] : null,
            orderId: $row['order_id'] !== null ? (int)$row['order_id'] : null,
            orderItemId: $row['order_item_id'] !== null ? (int)$row['order_item_id'] : null,
            productId: (int)$row['product_id'],
            status: (string)$row['status'],
            billingCycle: (string)$row['billing_cycle'],
            recurringAmountMinor: (int)$row['recurring_amount_minor'],
            currencyCode: (string)$row['currency_code'],
            registrationDate: (string)$row['registration_date'],
            nextDueDate: (string)$row['next_due_date'],
            domain: $row['domain'] !== null ? (string)$row['domain'] : null,
            username: $row['username'] !== null ? (string)$row['username'] : null,
            passwordEncrypted: $row['password_encrypted'] !== null ? (string)$row['password_encrypted'] : null,
            serverName: $row['server_name'] !== null ? (string)$row['server_name'] : null,
            ipAddress: $row['ip_address'] !== null ? (string)$row['ip_address'] : null,
            suspensionReason: $row['suspension_reason'] !== null ? (string)$row['suspension_reason'] : null,
            terminationDate: $row['termination_date'] !== null ? (string)$row['termination_date'] : null,
            notes: $row['notes'] !== null ? (string)$row['notes'] : null,
            metadata: is_array($meta) ? $meta : [],
            createdAt: (string)$row['created_at'],
            placement: $placement,
            billingRelation: $billingRelation,
            cancellationRequest: $cancellationRequest,
            lockVersion: isset($row['lock_version']) ? (int)$row['lock_version'] : 1
        );
    }
}
