<?php

declare(strict_types=1);

namespace Coleza\Api\Logging;

use Coleza\Foundation\Database\Connection;

final class ApiRequestLogger
{
    private string $table = 'api_request_logs';

    public function __construct(private Connection $db)
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
                key_id INT NULL,
                method VARCHAR(10) NOT NULL,
                path VARCHAR(191) NOT NULL,
                status_code INT NOT NULL,
                duration_ms INT NOT NULL,
                ip_address VARCHAR(45) NULL,
                request_id VARCHAR(64) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->table,
            $autoInc
        );

        $this->db->statement($sql);
    }

    /**
     * Record an incoming API request telemetry event.
     */
    public function log(
        string $method,
        string $path,
        int $statusCode,
        int $durationMs,
        ?int $keyId = null,
        ?string $ip = null,
        ?string $requestId = null
    ): int {
        $this->ensureTable();

        return (int) $this->db->insert($this->table, [
            'key_id' => $keyId,
            'method' => strtoupper($method),
            'path' => $path,
            'status_code' => $statusCode,
            'duration_ms' => $durationMs,
            'ip_address' => $ip,
            'request_id' => $requestId,
        ]);
    }

    /**
     * Query API logs for a specific API Key.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getLogsForKey(int $keyId): array
    {
        $this->ensureTable();
        return $this->db->select(
            sprintf('SELECT * FROM %s WHERE key_id = :kid ORDER BY id DESC', $this->table),
            ['kid' => $keyId]
        );
    }
}
