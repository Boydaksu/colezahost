<?php

declare(strict_types=1);

namespace Coleza\Domain\Servers\Capacity\Services;

use Coleza\Domain\Servers\Capacity\Entities\CapacityReservation;
use Coleza\Domain\Servers\Capacity\Exceptions\CapacityExceededException;
use Coleza\Domain\Servers\Capacity\Exceptions\ReservationException;
use Coleza\Domain\Servers\Services\ServerService;
use Coleza\Foundation\Database\Connection;
use DateTimeImmutable;
use RuntimeException;

final class CapacityReservationService
{
    private string $table = 'capacity_reservations';

    public function __construct(
        private Connection $db,
        private ServerService $serverService
    ) {
    }

    public function ensureTables(): void
    {
        if ($this->db->getDriverName() === 'mysql' && $this->db->inTransaction()) { throw new RuntimeException('Initialize reservation schema before starting a transaction.'); }
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                reservation_token VARCHAR(64) NOT NULL UNIQUE,
                scope_key VARCHAR(64) NULL UNIQUE,
                order_item_scope_key VARCHAR(64) NULL UNIQUE,
                server_id INT NOT NULL,
                server_pool_id INT NULL,
                service_id INT NULL,
                order_id INT NULL,
                order_item_id INT NULL,
                accounts_count INT NOT NULL DEFAULT 1,
                disk_mb INT NOT NULL DEFAULT 0,
                bandwidth_mb INT NOT NULL DEFAULT 0,
                status VARCHAR(30) NOT NULL DEFAULT "reserved",
                release_reason VARCHAR(255) NULL,
                expires_at TIMESTAMP NOT NULL,
                committed_at TIMESTAMP NULL,
                released_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->table,
            $autoInc
        );
        $this->db->statement($sql);
    }

    /**
     * Reserve server capacity temporarily (e.g. during checkout/provisioning prep).
     */
    public function reserve(
        int $serverId,
        int $accountsCount = 1,
        int $diskMb = 0,
        int $bandwidthMb = 0,
        int $ttlSeconds = 900,
        ?int $serviceId = null,
        ?int $orderId = null,
        ?int $orderItemId = null,
        ?int $poolId = null
    ): CapacityReservation
    {
        $ownsTransaction = !$this->db->inTransaction();
        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->db->transaction(function () use ($serverId, $accountsCount, $diskMb, $bandwidthMb, $ttlSeconds, $serviceId, $orderId, $orderItemId, $poolId) {
                    $this->db->lockRow('servers', $serverId);
                    return $this->reserveLocked($serverId, $accountsCount, $diskMb, $bandwidthMb, $ttlSeconds, $serviceId, $orderId, $orderItemId, $poolId);
                });
            } catch (\PDOException $error) {
                $retryable = in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213], true) || str_contains($error->getMessage(), 'scope_key');
                if (!$ownsTransaction || !$retryable || $attempt >= 2) { throw $error; }
                usleep(10000);
            }
        }
    }

    private function reserveLocked(
        int $serverId,
        int $accountsCount = 1,
        int $diskMb = 0,
        int $bandwidthMb = 0,
        int $ttlSeconds = 900,
        ?int $serviceId = null,
        ?int $orderId = null,
        ?int $orderItemId = null,
        ?int $poolId = null
    ): CapacityReservation {
        if ($accountsCount <= 0 || $diskMb < 0 || $bandwidthMb < 0 || $ttlSeconds <= 0
            || ($serviceId !== null && $serviceId <= 0) || ($orderItemId !== null && $orderItemId <= 0)) {
            throw new ReservationException('Invalid reservation amounts or identifiers.', 'INVALID_REQUEST');
        }
        $scopeKey = $serviceId !== null ? 'service:' . $serviceId : ($orderItemId !== null ? 'order_item:' . $orderItemId : null);
        $orderItemKey = $orderItemId !== null ? 'order_item:' . $orderItemId : null;
        if ($scopeKey !== null) {
            $existing = $this->db->selectOne('SELECT * FROM capacity_reservations WHERE scope_key = ? OR order_item_scope_key = ?' . $this->db->forUpdate(), [$scopeKey, $orderItemKey]);
            if ($existing !== null) {
                $res = $this->hydrate($existing);
                if ($res->getServerId() !== $serverId || $res->getAccountsCount() !== $accountsCount
                    || ($serviceId !== null && $res->getServiceId() !== null && $serviceId !== $res->getServiceId())
                    || ($orderItemId !== null && $res->getOrderItemId() !== $orderItemId)
                    || $res->getDiskMb() !== $diskMb || $res->getBandwidthMb() !== $bandwidthMb) {
                    throw new ReservationException('Existing active scope has different resources.', 'SCOPE_CONFLICT');
                }
                if (!$res->hasExpired() || $res->isCommitted()) { return $res; }
                $this->releaseLocked($res->getToken(), 'expired_ttl');
            }
        }
        $server = $this->serverService->findServerById($serverId);
        if ($server === null) {
            throw new ReservationException("Server {$serverId} not found.", 'SERVER_NOT_FOUND', ['server_id' => $serverId]);
        }

        if (!$server->isActive()) {
            throw new ReservationException("Server {$serverId} is not active ({$server->getStatus()}).", 'SERVER_INACTIVE', ['server_id' => $serverId]);
        }

        if ($poolId !== null && $poolId !== $server->getServerPoolId()) {
            throw new ReservationException('Server does not belong to the requested pool.', 'POOL_MISMATCH');
        }
        $capacity = $server->getCapacity();

        // Check account capacity
        if (!$capacity->hasAccountHeadroom($accountsCount)) {
            throw new CapacityExceededException(
                serverId: $serverId,
                resource: 'account',
                requested: $accountsCount,
                available: $capacity->getAvailableAccounts()
            );
        }

        // Check disk capacity
        if (!$capacity->hasDiskHeadroom($diskMb)) {
            $availableDisk = max(0, $capacity->getDiskCapacityMb() - $capacity->getDiskUsedMb());
            throw new CapacityExceededException(
                serverId: $serverId,
                resource: 'disk',
                requested: $diskMb,
                available: $availableDisk
            );
        }

        // Check bandwidth capacity
        if (!$capacity->hasBandwidthHeadroom($bandwidthMb)) {
            $availableBw = max(0, $capacity->getBandwidthCapacityMb() - $capacity->getBandwidthUsedMb());
            throw new CapacityExceededException(
                serverId: $serverId,
                resource: 'bandwidth',
                requested: $bandwidthMb,
                available: $availableBw
            );
        }

        // Increment server allocation
        $this->serverService->incrementAccountCount($serverId, $accountsCount, $diskMb, $bandwidthMb);

        $token = 'RES-' . bin2hex(random_bytes(16));
        $now = new DateTimeImmutable('now');
        $expiresAt = $now->modify("+{$ttlSeconds} seconds")->format('Y-m-d H:i:s');

        $sql = sprintf(
            'INSERT INTO %s (reservation_token, scope_key, order_item_scope_key, server_id, server_pool_id, service_id, order_id, order_item_id, accounts_count, disk_mb, bandwidth_mb, status, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $this->table
        );

        $this->db->statement($sql, [
            $token,
            $scopeKey,
            $orderItemKey,
            $serverId,
            $poolId,
            $serviceId,
            $orderId,
            $orderItemId,
            $accountsCount,
            $diskMb,
            $bandwidthMb,
            CapacityReservation::STATUS_RESERVED,
            $expiresAt,
        ]);

        $id = (int)$this->db->getPdo()->lastInsertId();

        $res = $this->findReservationById($id);
        if ($res === null) {
            throw new RuntimeException("Failed to reload newly created reservation {$id}.");
        }
        return $res;
    }

    /**
     * Commit a reserved allocation permanently (e.g. after successful provisioning).
     */
    public function commit(string $token, ?int $serviceId = null): CapacityReservation
    {
        $result = $this->reservationTransaction($token, fn () => $this->commitLocked($token, $serviceId));
        if ($result instanceof ReservationException) { throw $result; }
        return $result;
    }

    private function commitLocked(string $token, ?int $serviceId = null): CapacityReservation|ReservationException
    {
        if ($serviceId !== null && $serviceId <= 0) { throw new ReservationException('Service ID must be positive.', 'INVALID_REQUEST'); }
        $res = $this->findReservationByToken($token);
        if ($res === null) {
            throw new ReservationException("Reservation '{$token}' not found.", 'RESERVATION_NOT_FOUND', ['token' => $token]);
        }

        if ($serviceId !== null && $res->getServiceId() !== null && $serviceId !== $res->getServiceId()) {
            throw new ReservationException('Reservation belongs to a different service.', 'SCOPE_CONFLICT');
        }
        if ($res->isCommitted()) {
            if ($serviceId !== null && $serviceId !== $res->getServiceId()) {
                throw new ReservationException('Committed reservation belongs to a different service.', 'SCOPE_CONFLICT');
            }
            return $res;
        }

        if ($res->isReleased() || $res->isExpired()) {
            throw new ReservationException(
                message: "Cannot commit reservation '{$token}' in status '{$res->getStatus()}'.",
                errorCode: 'INVALID_STATUS',
                context: ['token' => $token, 'status' => $res->getStatus()]
            );
        }

        // Check if expired prior to commit
        if ($res->hasExpired()) {
            $this->releaseLocked($token, 'expired_ttl');
            return new ReservationException(
                message: "Reservation '{$token}' has expired and cannot be committed.",
                errorCode: 'RESERVATION_EXPIRED',
                context: ['token' => $token]
            );
        }

        $now = date('Y-m-d H:i:s');
        $resolvedServiceId = $serviceId ?? $res->getServiceId();
        $scopeKey = $resolvedServiceId !== null ? 'service:' . $resolvedServiceId
            : ($res->getOrderItemId() !== null ? 'order_item:' . $res->getOrderItemId() : null);

        $sql = sprintf(
            'UPDATE %s SET status = ?, committed_at = ?, service_id = ?, scope_key = ? WHERE reservation_token = ?',
            $this->table
        );
        $this->db->statement($sql, [
            CapacityReservation::STATUS_COMMITTED,
            $now,
            $resolvedServiceId,
            $scopeKey,
            $token,
        ]);

        return $this->findReservationByToken($token) ?? throw new RuntimeException("Reservation '{$token}' disappeared.");
    }

    /**
     * Release a reserved or committed allocation and return quota to the server.
     */
    public function release(string $token, string $reason = 'cancelled'): CapacityReservation
    {
        $result = $this->reservationTransaction($token, fn () => $this->releaseLocked($token, $reason));
        return $result;
    }

    private function releaseLocked(string $token, string $reason = 'cancelled'): CapacityReservation
    {
        $res = $this->findReservationByToken($token);
        if ($res === null) {
            throw new ReservationException("Reservation '{$token}' not found.", 'RESERVATION_NOT_FOUND', ['token' => $token]);
        }

        if ($res->isReleased() || $res->isExpired()) {
            return $res;
        }

        $now = date('Y-m-d H:i:s');
        $newStatus = ($reason === 'expired_ttl') ? CapacityReservation::STATUS_EXPIRED : CapacityReservation::STATUS_RELEASED;

        $sql = sprintf(
            'UPDATE %s SET status = ?, released_at = ?, release_reason = ?, scope_key = NULL, order_item_scope_key = NULL WHERE reservation_token = ?',
            $this->table
        );
        $this->db->statement($sql, [$newStatus, $now, $reason, $token]);
        // Both the terminal status and returned quota commit together; the floor excludes this allocation.
        if ($res->isReserved() || $res->isCommitted()) {
            $this->serverService->decrementAccountCount($res->getServerId(), $res->getAccountsCount(), $res->getDiskMb(), $res->getBandwidthMb());
        }

        return $this->findReservationByToken($token) ?? throw new RuntimeException("Reservation '{$token}' disappeared.");
    }

    /**
     * Scan and release all stale reservations that have exceeded their TTL.
     *
     * @return array<CapacityReservation>
     */
    public function expireStaleReservations(?string $referenceTime = null): array
    {
        $refTime = $referenceTime ?? date('Y-m-d H:i:s');

        $sql = sprintf(
            'SELECT * FROM %s WHERE status = ? AND expires_at < ?',
            $this->table
        );
        $rows = $this->db->select($sql, [CapacityReservation::STATUS_RESERVED, $refTime]);

        $expired = [];
        foreach ($rows as $row) {
            $token = (string)$row['reservation_token'];
            $result = $this->reservationTransaction($token, function () use ($token, $refTime): ?CapacityReservation {
                $res = $this->findReservationByToken($token);
                if ($res === null || !$res->isReserved() || $res->getExpiresAt() >= $refTime) { return null; }
                return $this->releaseLocked($token, 'expired_ttl');
            });
            if ($result !== null) { $expired[] = $result; }
        }

        return $expired;
    }

    public function findReservationByToken(string $token): ?CapacityReservation
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE reservation_token = ?', $this->table) . $this->db->forUpdate(), [$token]);
        return $row ? $this->hydrate($row) : null;
    }

    public function findReservationById(int $id): ?CapacityReservation
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE id = ?', $this->table) . $this->db->forUpdate(), [$id]);
        return $row ? $this->hydrate($row) : null;
    }

    public function findActiveReservationForService(int $serviceId): ?CapacityReservation
    {
        $row = $this->db->selectOne(
            sprintf('SELECT * FROM %s WHERE service_id = ? AND status IN (?, ?) ORDER BY id DESC LIMIT 1', $this->table),
            [$serviceId, CapacityReservation::STATUS_RESERVED, CapacityReservation::STATUS_COMMITTED]
        );
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * @return array<CapacityReservation>
     */
    public function listReservationsForServer(int $serverId, ?string $status = null): array
    {
        $sql = sprintf('SELECT * FROM %s WHERE server_id = ?', $this->table);
        $params = [$serverId];

        if ($status !== null) {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }

        $sql .= ' ORDER BY id DESC';
        $rows = $this->db->select($sql, $params);
        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function reservationTransaction(string $token, callable $operation): mixed
    {
        $scope = $this->db->selectOne('SELECT id, server_id FROM capacity_reservations WHERE reservation_token = ?', [$token]);
        if ($scope === null) { throw new ReservationException('Reservation not found.', 'RESERVATION_NOT_FOUND'); }
        return $this->db->transaction(function () use ($scope, $operation) {
            $this->db->lockRow('servers', (int) $scope['server_id']);
            $this->db->lockRow($this->table, (int) $scope['id']);
            return $operation();
        });
    }

    private function hydrate(array $row): CapacityReservation
    {
        return new CapacityReservation(
            id: (int)$row['id'],
            token: (string)$row['reservation_token'],
            serverId: (int)$row['server_id'],
            serverPoolId: $row['server_pool_id'] !== null ? (int)$row['server_pool_id'] : null,
            serviceId: $row['service_id'] !== null ? (int)$row['service_id'] : null,
            orderId: $row['order_id'] !== null ? (int)$row['order_id'] : null,
            orderItemId: $row['order_item_id'] !== null ? (int)$row['order_item_id'] : null,
            accountsCount: (int)$row['accounts_count'],
            diskMb: (int)$row['disk_mb'],
            bandwidthMb: (int)$row['bandwidth_mb'],
            status: (string)$row['status'],
            releaseReason: $row['release_reason'] !== null ? (string)$row['release_reason'] : null,
            expiresAt: (string)$row['expires_at'],
            committedAt: $row['committed_at'] !== null ? (string)$row['committed_at'] : null,
            releasedAt: $row['released_at'] !== null ? (string)$row['released_at'] : null,
            createdAt: (string)$row['created_at']
        );
    }
}
