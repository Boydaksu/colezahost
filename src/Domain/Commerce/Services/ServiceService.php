<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Services;

use Coleza\Domain\Commerce\Orders\Order;
use Coleza\Domain\Commerce\Recurring\BillingPeriod;
use Coleza\Domain\Pricing\Entities\PriceCycle;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use RuntimeException;

final class ServiceService
{
    private string $servicesTable = 'services';
    private string $sequencesTable = 'service_sequences';

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
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->servicesTable,
            $autoInc
        );
        $this->db->statement($sql);

        $sqlSeq = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                date_prefix VARCHAR(8) PRIMARY KEY,
                last_number INT NOT NULL DEFAULT 0
            )',
            $this->sequencesTable
        );
        $this->db->statement($sqlSeq);
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

        $sql = sprintf(
            'INSERT INTO %s (service_number, user_id, organization_id, order_id, order_item_id, product_id, status, billing_cycle, recurring_amount_minor, currency_code, registration_date, next_due_date, domain, username, password_encrypted, server_name, ip_address, notes, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
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
        ]);

        $id = (int)$this->db->getPdo()->lastInsertId();

        return new Service(
            id: $id,
            serviceNumber: $serviceNumber,
            userId: $userId,
            organizationId: $orgId,
            orderId: $orderId,
            orderItemId: $orderItemId,
            productId: $productId,
            status: $status,
            billingCycle: $cycle,
            recurringAmountMinor: $recurringAmountMinor,
            currencyCode: $currencyCode,
            registrationDate: $regDate,
            nextDueDate: $nextDueDate,
            domain: $domain,
            username: $username,
            passwordEncrypted: $password,
            serverName: $serverName,
            ipAddress: $ipAddress,
            notes: $notes,
            metadata: $metadata,
            createdAt: date('Y-m-d H:i:s')
        );
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
     * Activate a service and set provisioning parameters.
     *
     * @param array<string, mixed> $provisioningDetails
     */
    public function activateService(int $serviceId, array $provisioningDetails = []): Service
    {
        $service = $this->findServiceById($serviceId);
        if ($service === null) {
            throw new RuntimeException("Service {$serviceId} not found.");
        }

        ServiceStateMachine::assertCanTransition($service->getStatus(), ServiceStateMachine::STATUS_ACTIVE);

        $domain = $provisioningDetails['domain'] ?? $service->getDomain();
        $username = $provisioningDetails['username'] ?? $service->getUsername();
        $password = $provisioningDetails['password_encrypted'] ?? $service->getPasswordEncrypted();
        $serverName = $provisioningDetails['server_name'] ?? $service->getServerName();
        $ipAddress = $provisioningDetails['ip_address'] ?? $service->getIpAddress();

        $sql = sprintf(
            'UPDATE %s SET status = ?, domain = ?, username = ?, password_encrypted = ?, server_name = ?, ip_address = ?, suspension_reason = NULL WHERE id = ?',
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

        return $this->findServiceById($serviceId);
    }

    /**
     * Suspend a service.
     */
    public function suspendService(int $serviceId, string $reason): Service
    {
        $service = $this->findServiceById($serviceId);
        if ($service === null) {
            throw new RuntimeException("Service {$serviceId} not found.");
        }

        ServiceStateMachine::assertCanTransition($service->getStatus(), ServiceStateMachine::STATUS_SUSPENDED);

        $sql = sprintf('UPDATE %s SET status = ?, suspension_reason = ? WHERE id = ?', $this->servicesTable);
        $this->db->statement($sql, [ServiceStateMachine::STATUS_SUSPENDED, $reason, $serviceId]);

        return $this->findServiceById($serviceId);
    }

    /**
     * Unsuspend a suspended service.
     */
    public function unsuspendService(int $serviceId): Service
    {
        $service = $this->findServiceById($serviceId);
        if ($service === null) {
            throw new RuntimeException("Service {$serviceId} not found.");
        }

        ServiceStateMachine::assertCanTransition($service->getStatus(), ServiceStateMachine::STATUS_ACTIVE);

        $sql = sprintf('UPDATE %s SET status = ?, suspension_reason = NULL WHERE id = ?', $this->servicesTable);
        $this->db->statement($sql, [ServiceStateMachine::STATUS_ACTIVE, $serviceId]);

        return $this->findServiceById($serviceId);
    }

    /**
     * Terminate a service permanently.
     */
    public function terminateService(int $serviceId, string $reason = ''): Service
    {
        $service = $this->findServiceById($serviceId);
        if ($service === null) {
            throw new RuntimeException("Service {$serviceId} not found.");
        }

        ServiceStateMachine::assertCanTransition($service->getStatus(), ServiceStateMachine::STATUS_TERMINATED);

        $sql = sprintf('UPDATE %s SET status = ?, termination_date = ?, notes = ? WHERE id = ?', $this->servicesTable);
        $notes = trim($service->getNotes() . "\nTerminated: " . $reason);
        $this->db->statement($sql, [ServiceStateMachine::STATUS_TERMINATED, date('Y-m-d H:i:s'), $notes, $serviceId]);

        return $this->findServiceById($serviceId);
    }

    /**
     * Cancel a service.
     */
    public function cancelService(int $serviceId, string $reason = ''): Service
    {
        $service = $this->findServiceById($serviceId);
        if ($service === null) {
            throw new RuntimeException("Service {$serviceId} not found.");
        }

        ServiceStateMachine::assertCanTransition($service->getStatus(), ServiceStateMachine::STATUS_CANCELLED);

        $sql = sprintf('UPDATE %s SET status = ?, notes = ? WHERE id = ?', $this->servicesTable);
        $notes = trim($service->getNotes() . "\nCancelled: " . $reason);
        $this->db->statement($sql, [ServiceStateMachine::STATUS_CANCELLED, $notes, $serviceId]);

        return $this->findServiceById($serviceId);
    }

    /**
     * Advance next due date on renewal settlement. Reactivates suspended service if suspended for overdue.
     */
    public function renewService(int $serviceId): Service
    {
        $service = $this->findServiceById($serviceId);
        if ($service === null) {
            throw new RuntimeException("Service {$serviceId} not found.");
        }

        $newDueDate = BillingPeriod::calculateNextDueDate($service->getNextDueDate(), $service->getBillingCycle());
        $newStatus = $service->isSuspended() ? ServiceStateMachine::STATUS_ACTIVE : $service->getStatus();
        $suspensionReason = $service->isSuspended() ? null : $service->getSuspensionReason();

        $sql = sprintf('UPDATE %s SET next_due_date = ?, status = ?, suspension_reason = ? WHERE id = ?', $this->servicesTable);
        $this->db->statement($sql, [$newDueDate, $newStatus, $suspensionReason, $serviceId]);

        return $this->findServiceById($serviceId);
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
    private function hydrateService(array $row): Service
    {
        $meta = !empty($row['metadata_json']) ? json_decode((string)$row['metadata_json'], true) : [];

        return new Service(
            id: (int)$row['id'],
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
            createdAt: (string)$row['created_at']
        );
    }
}
