<?php

declare(strict_types=1);

namespace Coleza\Domain\Servers\Services;

use Coleza\Domain\Servers\Entities\Location;
use Coleza\Domain\Servers\Entities\Server;
use Coleza\Domain\Servers\Entities\ServerCapacity;
use Coleza\Domain\Servers\Entities\ServerPool;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use RuntimeException;

final class ServerService
{
    private string $locationsTable = 'locations';
    private string $poolsTable = 'server_pools';
    private string $serversTable = 'servers';

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

        // 1. Locations
        $sqlLocations = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                name VARCHAR(100) NOT NULL,
                slug VARCHAR(100) NOT NULL UNIQUE,
                country_code VARCHAR(2) NOT NULL,
                city VARCHAR(100) NOT NULL,
                datacenter VARCHAR(100) NULL,
                is_active INT NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->locationsTable,
            $autoInc
        );
        $this->db->statement($sqlLocations);

        // 2. Server Pools
        $sqlPools = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                name VARCHAR(100) NOT NULL,
                slug VARCHAR(100) NOT NULL UNIQUE,
                provider_slug VARCHAR(100) NOT NULL,
                location_id INT NULL,
                strategy VARCHAR(50) NOT NULL DEFAULT "least_loaded",
                is_active INT NOT NULL DEFAULT 1,
                metadata_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->poolsTable,
            $autoInc
        );
        $this->db->statement($sqlPools);

        // 3. Servers
        $sqlServers = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                name VARCHAR(100) NOT NULL,
                hostname VARCHAR(255) NOT NULL UNIQUE,
                ip_address VARCHAR(100) NOT NULL,
                provider_slug VARCHAR(100) NOT NULL,
                server_pool_id INT NULL,
                location_id INT NULL,
                status VARCHAR(50) NOT NULL DEFAULT "active",
                max_accounts INT NOT NULL DEFAULT 100,
                used_accounts INT NOT NULL DEFAULT 0,
                disk_capacity_mb INT NOT NULL DEFAULT 0,
                disk_used_mb INT NOT NULL DEFAULT 0,
                bandwidth_capacity_mb INT NOT NULL DEFAULT 0,
                bandwidth_used_mb INT NOT NULL DEFAULT 0,
                memory_capacity_mb INT NOT NULL DEFAULT 0,
                memory_used_mb INT NOT NULL DEFAULT 0,
                port INT NULL,
                secure INT NOT NULL DEFAULT 1,
                auth_type VARCHAR(50) NOT NULL DEFAULT "api_token",
                auth_secret_encrypted TEXT NULL,
                assigned_ip_pool_json TEXT NULL,
                metadata_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->serversTable,
            $autoInc
        );
        $this->db->statement($sqlServers);
    }

    // ==========================================
    // Location Operations
    // ==========================================

    /**
     * @param array<string, mixed> $data
     */
    public function createLocation(array $data): Location
    {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException(['name' => 'Location name is required.'], 'Invalid location name');
        }

        $slug = trim((string)($data['slug'] ?? ''));
        if ($slug === '') {
            throw new ValidationException(['slug' => 'Location slug is required.'], 'Invalid location slug');
        }

        if ($this->findLocationBySlug($slug) !== null) {
            throw new ValidationException(['slug' => "Location slug '{$slug}' already exists."], 'Duplicate location slug');
        }

        $countryCode = strtoupper(trim((string)($data['country_code'] ?? 'US')));
        $city = trim((string)($data['city'] ?? ''));
        $datacenter = isset($data['datacenter']) ? (string)$data['datacenter'] : null;
        $isActive = isset($data['is_active']) ? ((bool)$data['is_active'] ? 1 : 0) : 1;

        $sql = sprintf(
            'INSERT INTO %s (name, slug, country_code, city, datacenter, is_active) VALUES (?, ?, ?, ?, ?, ?)',
            $this->locationsTable
        );
        $this->db->statement($sql, [$name, $slug, $countryCode, $city, $datacenter, $isActive]);
        $id = (int)$this->db->getPdo()->lastInsertId();

        $loc = $this->findLocationById($id);
        if ($loc === null) {
            throw new RuntimeException("Failed to load newly created location {$id}.");
        }
        return $loc;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateLocation(int $id, array $data): Location
    {
        $existing = $this->findLocationById($id);
        if ($existing === null) {
            throw new RuntimeException("Location {$id} not found.");
        }

        $name = isset($data['name']) ? trim((string)$data['name']) : $existing->getName();
        $countryCode = isset($data['country_code']) ? strtoupper(trim((string)$data['country_code'])) : $existing->getCountryCode();
        $city = isset($data['city']) ? trim((string)$data['city']) : $existing->getCity();
        $datacenter = array_key_exists('datacenter', $data) ? ($data['datacenter'] !== null ? (string)$data['datacenter'] : null) : $existing->getDatacenter();
        $isActive = isset($data['is_active']) ? ((bool)$data['is_active'] ? 1 : 0) : ($existing->isActive() ? 1 : 0);

        $sql = sprintf(
            'UPDATE %s SET name = ?, country_code = ?, city = ?, datacenter = ?, is_active = ? WHERE id = ?',
            $this->locationsTable
        );
        $this->db->statement($sql, [$name, $countryCode, $city, $datacenter, $isActive, $id]);

        return $this->findLocationById($id) ?? throw new RuntimeException("Location {$id} disappeared.");
    }

    public function findLocationById(int $id): ?Location
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE id = ?', $this->locationsTable), [$id]);
        return $row ? $this->hydrateLocation($row) : null;
    }

    public function findLocationBySlug(string $slug): ?Location
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE slug = ?', $this->locationsTable), [$slug]);
        return $row ? $this->hydrateLocation($row) : null;
    }

    /**
     * @return array<Location>
     */
    public function listLocations(bool $onlyActive = true): array
    {
        $sql = sprintf('SELECT * FROM %s', $this->locationsTable);
        if ($onlyActive) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY name ASC';

        $rows = $this->db->select($sql);
        return array_map([$this, 'hydrateLocation'], $rows);
    }

    // ==========================================
    // Server Pool Operations
    // ==========================================

    /**
     * @param array<string, mixed> $data
     */
    public function createPool(array $data): ServerPool
    {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException(['name' => 'Pool name is required.'], 'Invalid pool name');
        }

        $slug = trim((string)($data['slug'] ?? ''));
        if ($slug === '') {
            throw new ValidationException(['slug' => 'Pool slug is required.'], 'Invalid pool slug');
        }

        if ($this->findPoolBySlug($slug) !== null) {
            throw new ValidationException(['slug' => "Pool slug '{$slug}' already exists."], 'Duplicate pool slug');
        }

        $providerSlug = trim((string)($data['provider_slug'] ?? ''));
        if ($providerSlug === '') {
            throw new ValidationException(['provider_slug' => 'Provider slug is required.'], 'Invalid provider slug');
        }

        $strategy = (string)($data['strategy'] ?? ServerPool::STRATEGY_LEAST_LOADED);
        if (!ServerPool::isValidStrategy($strategy)) {
            throw new ValidationException(['strategy' => "Invalid pool strategy: {$strategy}"], 'Invalid strategy');
        }

        $locationId = isset($data['location_id']) && $data['location_id'] !== null ? (int)$data['location_id'] : null;
        $isActive = isset($data['is_active']) ? ((bool)$data['is_active'] ? 1 : 0) : 1;
        $metadata = (array)($data['metadata'] ?? []);

        $sql = sprintf(
            'INSERT INTO %s (name, slug, provider_slug, location_id, strategy, is_active, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            $this->poolsTable
        );
        $this->db->statement($sql, [
            $name,
            $slug,
            $providerSlug,
            $locationId,
            $strategy,
            $isActive,
            json_encode($metadata),
        ]);
        $id = (int)$this->db->getPdo()->lastInsertId();

        $pool = $this->findPoolById($id);
        if ($pool === null) {
            throw new RuntimeException("Failed to load newly created pool {$id}.");
        }
        return $pool;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updatePool(int $id, array $data): ServerPool
    {
        $existing = $this->findPoolById($id);
        if ($existing === null) {
            throw new RuntimeException("Server pool {$id} not found.");
        }

        $name = isset($data['name']) ? trim((string)$data['name']) : $existing->getName();
        $providerSlug = isset($data['provider_slug']) ? trim((string)$data['provider_slug']) : $existing->getProviderSlug();
        $locationId = array_key_exists('location_id', $data)
            ? ($data['location_id'] !== null ? (int)$data['location_id'] : null)
            : $existing->getLocationId();
        $strategy = isset($data['strategy']) ? (string)$data['strategy'] : $existing->getStrategy();
        if (!ServerPool::isValidStrategy($strategy)) {
            throw new ValidationException(['strategy' => "Invalid pool strategy: {$strategy}"], 'Invalid strategy');
        }
        $isActive = isset($data['is_active']) ? ((bool)$data['is_active'] ? 1 : 0) : ($existing->isActive() ? 1 : 0);
        $metadata = isset($data['metadata']) ? (array)$data['metadata'] : $existing->getMetadata();

        $sql = sprintf(
            'UPDATE %s SET name = ?, provider_slug = ?, location_id = ?, strategy = ?, is_active = ?, metadata_json = ? WHERE id = ?',
            $this->poolsTable
        );
        $this->db->statement($sql, [$name, $providerSlug, $locationId, $strategy, $isActive, json_encode($metadata), $id]);

        return $this->findPoolById($id) ?? throw new RuntimeException("Server pool {$id} disappeared.");
    }

    public function findPoolById(int $id): ?ServerPool
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE id = ?', $this->poolsTable), [$id]);
        return $row ? $this->hydratePool($row) : null;
    }

    public function findPoolBySlug(string $slug): ?ServerPool
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE slug = ?', $this->poolsTable), [$slug]);
        return $row ? $this->hydratePool($row) : null;
    }

    /**
     * @return array<ServerPool>
     */
    public function listPools(bool $onlyActive = true): array
    {
        $sql = sprintf('SELECT * FROM %s', $this->poolsTable);
        if ($onlyActive) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY name ASC';

        $rows = $this->db->select($sql);
        return array_map([$this, 'hydratePool'], $rows);
    }

    // ==========================================
    // Server Operations
    // ==========================================

    /**
     * @param array<string, mixed> $data
     */
    public function createServer(array $data): Server
    {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException(['name' => 'Server name is required.'], 'Invalid server name');
        }

        $hostname = trim((string)($data['hostname'] ?? ''));
        if ($hostname === '') {
            throw new ValidationException(['hostname' => 'Server hostname is required.'], 'Invalid server hostname');
        }

        if ($this->findServerByHostname($hostname) !== null) {
            throw new ValidationException(['hostname' => "Server hostname '{$hostname}' already exists."], 'Duplicate server hostname');
        }

        $ipAddress = trim((string)($data['ip_address'] ?? ''));
        if ($ipAddress === '') {
            throw new ValidationException(['ip_address' => 'IP address is required.'], 'Invalid server IP');
        }

        $providerSlug = trim((string)($data['provider_slug'] ?? ''));
        if ($providerSlug === '') {
            throw new ValidationException(['provider_slug' => 'Provider slug is required.'], 'Invalid provider slug');
        }

        $poolId = isset($data['server_pool_id']) && $data['server_pool_id'] !== null ? (int)$data['server_pool_id'] : null;
        $locationId = isset($data['location_id']) && $data['location_id'] !== null ? (int)$data['location_id'] : null;
        $status = (string)($data['status'] ?? Server::STATUS_ACTIVE);
        if (!Server::isValidStatus($status)) {
            throw new ValidationException(['status' => "Invalid server status: {$status}"], 'Invalid status');
        }

        $maxAccounts = max(1, (int)($data['max_accounts'] ?? 100));
        $usedAccounts = max(0, (int)($data['used_accounts'] ?? 0));
        $diskCapacityMb = max(0, (int)($data['disk_capacity_mb'] ?? 0));
        $diskUsedMb = max(0, (int)($data['disk_used_mb'] ?? 0));
        $bandwidthCapacityMb = max(0, (int)($data['bandwidth_capacity_mb'] ?? 0));
        $bandwidthUsedMb = max(0, (int)($data['bandwidth_used_mb'] ?? 0));
        $memoryCapacityMb = max(0, (int)($data['memory_capacity_mb'] ?? 0));
        $memoryUsedMb = max(0, (int)($data['memory_used_mb'] ?? 0));

        $port = isset($data['port']) && $data['port'] !== null ? (int)$data['port'] : null;
        $secure = isset($data['secure']) ? ((bool)$data['secure'] ? 1 : 0) : 1;
        $authType = (string)($data['auth_type'] ?? 'api_token');
        $authSecret = isset($data['auth_secret']) ? (string)$data['auth_secret'] : null;
        $ipPool = (array)($data['assigned_ip_pool'] ?? []);
        $metadata = (array)($data['metadata'] ?? []);

        $sql = sprintf(
            'INSERT INTO %s (name, hostname, ip_address, provider_slug, server_pool_id, location_id, status, max_accounts, used_accounts, disk_capacity_mb, disk_used_mb, bandwidth_capacity_mb, bandwidth_used_mb, memory_capacity_mb, memory_used_mb, port, secure, auth_type, auth_secret_encrypted, assigned_ip_pool_json, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $this->serversTable
        );

        $this->db->statement($sql, [
            $name,
            $hostname,
            $ipAddress,
            $providerSlug,
            $poolId,
            $locationId,
            $status,
            $maxAccounts,
            $usedAccounts,
            $diskCapacityMb,
            $diskUsedMb,
            $bandwidthCapacityMb,
            $bandwidthUsedMb,
            $memoryCapacityMb,
            $memoryUsedMb,
            $port,
            $secure,
            $authType,
            $authSecret,
            json_encode($ipPool),
            json_encode($metadata),
        ]);
        $id = (int)$this->db->getPdo()->lastInsertId();

        $srv = $this->findServerById($id);
        if ($srv === null) {
            throw new RuntimeException("Failed to load newly created server {$id}.");
        }
        return $srv;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateServer(int $id, array $data): Server
    {
        $existing = $this->findServerById($id);
        if ($existing === null) {
            throw new RuntimeException("Server {$id} not found.");
        }

        $name = isset($data['name']) ? trim((string)$data['name']) : $existing->getName();
        $ipAddress = isset($data['ip_address']) ? trim((string)$data['ip_address']) : $existing->getIpAddress();
        $poolId = array_key_exists('server_pool_id', $data)
            ? ($data['server_pool_id'] !== null ? (int)$data['server_pool_id'] : null)
            : $existing->getServerPoolId();
        $locationId = array_key_exists('location_id', $data)
            ? ($data['location_id'] !== null ? (int)$data['location_id'] : null)
            : $existing->getLocationId();
        $status = isset($data['status']) ? (string)$data['status'] : $existing->getStatus();
        if (!Server::isValidStatus($status)) {
            throw new ValidationException(['status' => "Invalid server status: {$status}"], 'Invalid status');
        }

        $maxAccounts = isset($data['max_accounts']) ? max(1, (int)$data['max_accounts']) : $existing->getCapacity()->getMaxAccounts();
        $diskCapacityMb = isset($data['disk_capacity_mb']) ? max(0, (int)$data['disk_capacity_mb']) : $existing->getCapacity()->getDiskCapacityMb();
        $bwCapacityMb = isset($data['bandwidth_capacity_mb']) ? max(0, (int)$data['bandwidth_capacity_mb']) : $existing->getCapacity()->getBandwidthCapacityMb();
        $memCapacityMb = isset($data['memory_capacity_mb']) ? max(0, (int)$data['memory_capacity_mb']) : $existing->getCapacity()->getMemoryCapacityMb();

        $port = array_key_exists('port', $data)
            ? ($data['port'] !== null ? (int)$data['port'] : null)
            : $existing->getPort();
        $secure = isset($data['secure']) ? ((bool)$data['secure'] ? 1 : 0) : ($existing->isSecure() ? 1 : 0);
        $authType = isset($data['auth_type']) ? (string)$data['auth_type'] : $existing->getAuthType();
        $authSecret = array_key_exists('auth_secret', $data) ? (string)$data['auth_secret'] : $existing->getAuthSecret();
        $ipPool = isset($data['assigned_ip_pool']) ? (array)$data['assigned_ip_pool'] : $existing->getAssignedIpPool();
        $metadata = isset($data['metadata']) ? (array)$data['metadata'] : $existing->getMetadata();

        $sql = sprintf(
            'UPDATE %s SET name = ?, ip_address = ?, server_pool_id = ?, location_id = ?, status = ?, max_accounts = ?, disk_capacity_mb = ?, bandwidth_capacity_mb = ?, memory_capacity_mb = ?, port = ?, secure = ?, auth_type = ?, auth_secret_encrypted = ?, assigned_ip_pool_json = ?, metadata_json = ? WHERE id = ?',
            $this->serversTable
        );

        $this->db->statement($sql, [
            $name,
            $ipAddress,
            $poolId,
            $locationId,
            $status,
            $maxAccounts,
            $diskCapacityMb,
            $bwCapacityMb,
            $memCapacityMb,
            $port,
            $secure,
            $authType,
            $authSecret,
            json_encode($ipPool),
            json_encode($metadata),
            $id,
        ]);

        return $this->findServerById($id) ?? throw new RuntimeException("Server {$id} disappeared.");
    }

    public function findServerById(int $id): ?Server
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE id = ?', $this->serversTable), [$id]);
        return $row ? $this->hydrateServer($row) : null;
    }

    public function findServerByHostname(string $hostname): ?Server
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE hostname = ?', $this->serversTable), [$hostname]);
        return $row ? $this->hydrateServer($row) : null;
    }

    /**
     * @return array<Server>
     */
    public function listServers(?int $poolId = null, ?int $locationId = null, ?string $status = null): array
    {
        $sql = sprintf('SELECT * FROM %s WHERE 1=1', $this->serversTable);
        $params = [];

        if ($poolId !== null) {
            $sql .= ' AND server_pool_id = ?';
            $params[] = $poolId;
        }

        if ($locationId !== null) {
            $sql .= ' AND location_id = ?';
            $params[] = $locationId;
        }

        if ($status !== null) {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }

        $sql .= ' ORDER BY name ASC';
        $rows = $this->db->select($sql, $params);
        return array_map([$this, 'hydrateServer'], $rows);
    }

    // ==========================================
    // Capacity & Allocation Operations
    // ==========================================

    public function incrementAccountCount(int $serverId, int $accounts = 1, int $diskMb = 0, int $bandwidthMb = 0): Server
    {
        $sql = sprintf(
            'UPDATE %s SET used_accounts = used_accounts + ?, disk_used_mb = disk_used_mb + ?, bandwidth_used_mb = bandwidth_used_mb + ? WHERE id = ?',
            $this->serversTable
        );
        $this->db->statement($sql, [$accounts, $diskMb, $bandwidthMb, $serverId]);

        $server = $this->findServerById($serverId);
        if ($server === null) {
            throw new RuntimeException("Server {$serverId} not found.");
        }

        // Check if server is now full
        if ($server->getCapacity()->isAtCapacity() && $server->isActive()) {
            $this->setServerStatus($serverId, Server::STATUS_FULL);
            return $this->findServerById($serverId) ?? $server;
        }

        return $server;
    }

    public function decrementAccountCount(int $serverId, int $accounts = 1, int $diskMb = 0, int $bandwidthMb = 0): Server
    {
        $sql = sprintf(
            'UPDATE %s SET used_accounts = MAX(0, used_accounts - ?), disk_used_mb = MAX(0, disk_used_mb - ?), bandwidth_used_mb = MAX(0, bandwidth_used_mb - ?) WHERE id = ?',
            $this->serversTable
        );
        $this->db->statement($sql, [$accounts, $diskMb, $bandwidthMb, $serverId]);

        $server = $this->findServerById($serverId);
        if ($server === null) {
            throw new RuntimeException("Server {$serverId} not found.");
        }

        // If server was full and now has headroom, reactivate
        if ($server->getStatus() === Server::STATUS_FULL && $server->getCapacity()->hasAccountHeadroom(1)) {
            $this->setServerStatus($serverId, Server::STATUS_ACTIVE);
            return $this->findServerById($serverId) ?? $server;
        }

        return $server;
    }

    public function updateServerUsage(
        int $serverId,
        int $usedAccounts,
        int $diskUsedMb,
        int $bandwidthUsedMb,
        int $memoryUsedMb = 0
    ): Server {
        $sql = sprintf(
            'UPDATE %s SET used_accounts = ?, disk_used_mb = ?, bandwidth_used_mb = ?, memory_used_mb = ? WHERE id = ?',
            $this->serversTable
        );
        $this->db->statement($sql, [$usedAccounts, $diskUsedMb, $bandwidthUsedMb, $memoryUsedMb, $serverId]);

        $server = $this->findServerById($serverId);
        if ($server === null) {
            throw new RuntimeException("Server {$serverId} not found.");
        }

        if ($server->getCapacity()->isAtCapacity() && $server->isActive()) {
            $this->setServerStatus($serverId, Server::STATUS_FULL);
            return $this->findServerById($serverId) ?? $server;
        }

        return $server;
    }

    public function setServerStatus(int $serverId, string $status): Server
    {
        if (!Server::isValidStatus($status)) {
            throw new ValidationException(['status' => "Invalid status: {$status}"], 'Invalid server status');
        }

        $sql = sprintf('UPDATE %s SET status = ? WHERE id = ?', $this->serversTable);
        $this->db->statement($sql, [$status, $serverId]);

        return $this->findServerById($serverId) ?? throw new RuntimeException("Server {$serverId} not found.");
    }

    public function checkCapacity(int $serverId, int $requiredDiskMb = 0, int $requiredBandwidthMb = 0): bool
    {
        $server = $this->findServerById($serverId);
        if ($server === null) {
            return false;
        }

        return $server->canAcceptPlacement($requiredDiskMb, $requiredBandwidthMb);
    }

    // ==========================================
    // Hydrators
    // ==========================================

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateLocation(array $row): Location
    {
        return new Location(
            id: (int)$row['id'],
            name: (string)$row['name'],
            slug: (string)$row['slug'],
            countryCode: (string)$row['country_code'],
            city: (string)$row['city'],
            datacenter: $row['datacenter'] !== null ? (string)$row['datacenter'] : null,
            isActive: (bool)$row['is_active'],
            createdAt: (string)$row['created_at']
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydratePool(array $row): ServerPool
    {
        $meta = !empty($row['metadata_json']) ? json_decode((string)$row['metadata_json'], true) : [];

        return new ServerPool(
            id: (int)$row['id'],
            name: (string)$row['name'],
            slug: (string)$row['slug'],
            providerSlug: (string)$row['provider_slug'],
            locationId: $row['location_id'] !== null ? (int)$row['location_id'] : null,
            strategy: (string)($row['strategy'] ?? ServerPool::STRATEGY_LEAST_LOADED),
            isActive: (bool)$row['is_active'],
            metadata: is_array($meta) ? $meta : [],
            createdAt: (string)$row['created_at']
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateServer(array $row): Server
    {
        $capacity = new ServerCapacity(
            maxAccounts: (int)$row['max_accounts'],
            usedAccounts: (int)$row['used_accounts'],
            diskCapacityMb: (int)$row['disk_capacity_mb'],
            diskUsedMb: (int)$row['disk_used_mb'],
            bandwidthCapacityMb: (int)$row['bandwidth_capacity_mb'],
            bandwidthUsedMb: (int)$row['bandwidth_used_mb'],
            memoryCapacityMb: (int)$row['memory_capacity_mb'],
            memoryUsedMb: (int)$row['memory_used_mb']
        );

        $ipPool = !empty($row['assigned_ip_pool_json']) ? json_decode((string)$row['assigned_ip_pool_json'], true) : [];
        $meta = !empty($row['metadata_json']) ? json_decode((string)$row['metadata_json'], true) : [];

        return new Server(
            id: (int)$row['id'],
            name: (string)$row['name'],
            hostname: (string)$row['hostname'],
            ipAddress: (string)$row['ip_address'],
            providerSlug: (string)$row['provider_slug'],
            serverPoolId: $row['server_pool_id'] !== null ? (int)$row['server_pool_id'] : null,
            locationId: $row['location_id'] !== null ? (int)$row['location_id'] : null,
            status: (string)$row['status'],
            capacity: $capacity,
            port: $row['port'] !== null ? (int)$row['port'] : null,
            secure: (bool)$row['secure'],
            authType: (string)$row['auth_type'],
            authSecret: $row['auth_secret_encrypted'] !== null ? (string)$row['auth_secret_encrypted'] : null,
            assignedIpPool: is_array($ipPool) ? $ipPool : [],
            metadata: is_array($meta) ? $meta : [],
            createdAt: (string)$row['created_at']
        );
    }
}
