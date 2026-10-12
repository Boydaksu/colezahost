<?php

declare(strict_types=1);

namespace Coleza\Domain\Fraud\Velocity;

use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Database\PdoSchema;
use DateTimeImmutable;

final class VelocityTrackerService
{
    private string $table = 'fraud_velocity_events';

    public function __construct(
        private readonly Connection $db
    ) {
    }

    /** @return array<string, list<string>> */
    public static function schemaRequirements(): array
    {
        return [
            'fraud_velocity_events' => ['id', 'event_type', 'identifier_type', 'identifier_value', 'metadata_json', 'created_at'],
        ];
    }

    public function ensureTables(bool $installSchema = false): void
    {
        $installed = PdoSchema::useInstalledSchema($this->db, self::schemaRequirements(), $installSchema);
        if ($installed && !$installSchema) { return; }
        $driver = $this->db->getDriverName();
        $autoInc = PdoSchema::autoIncrement($this->db->getPdo());
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB' : '';
        if (!$installed) {
            $sql = sprintf(
                'CREATE TABLE IF NOT EXISTS %s (
                    id %s,
                    event_type VARCHAR(50) NOT NULL,
                    identifier_type VARCHAR(30) NOT NULL,
                    identifier_value VARCHAR(255) NOT NULL,
                    metadata_json TEXT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )',
                $this->table,
                $autoInc
            );
            $this->db->statement($sql . $engine);
        }
        foreach ([
            'idx_fraud_velocity_lookup' => [$this->table, 'event_type, identifier_type, identifier_value, created_at'],
        ] as $name => [$table, $columns]) {
            if ($driver === 'sqlite') {
                $this->db->statement("CREATE INDEX IF NOT EXISTS {$name} ON {$table} ({$columns})");
            } else {
                $exists = $this->db->selectOne('SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', [$table, $name]);
                if ($exists === null) { $this->db->statement("CREATE INDEX {$name} ON {$table} ({$columns})"); }
            }
        }
    }

    public function recordEvent(
        string $eventType,
        string $identifierType,
        string $identifierValue,
        array $metadata = [],
        ?DateTimeImmutable $createdAt = null
    ): int {
        $this->ensureTables();

        $timestamp = ($createdAt ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        $id = $this->db->insert($this->table, [
            'event_type' => $eventType,
            'identifier_type' => $identifierType,
            'identifier_value' => trim(strtolower($identifierValue)),
            'metadata_json' => !empty($metadata) ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
            'created_at' => $timestamp,
        ]);

        return (int) $id;
    }

    public function getEventCount(
        string $eventType,
        string $identifierType,
        string $identifierValue,
        int $windowSeconds,
        ?DateTimeImmutable $now = null
    ): int {
        $this->ensureTables();

        $current = $now ?? new DateTimeImmutable();
        $thresholdTime = $current->modify("-{$windowSeconds} seconds")->format('Y-m-d H:i:s');

        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS total_count FROM {$this->table}
             WHERE event_type = :event_type
               AND identifier_type = :identifier_type
               AND identifier_value = :identifier_value
               AND created_at >= :threshold",
            [
                'event_type' => $eventType,
                'identifier_type' => $identifierType,
                'identifier_value' => trim(strtolower($identifierValue)),
                'threshold' => $thresholdTime,
            ]
        );

        return (int) ($row['total_count'] ?? 0);
    }

    public function getIpOrderVelocity(string $ip, int $windowSeconds = 3600, ?DateTimeImmutable $now = null): int
    {
        return $this->getEventCount('order_attempt', 'ip', $ip, $windowSeconds, $now);
    }

    public function getUserOrderVelocity(int $userId, int $windowSeconds = 600, ?DateTimeImmutable $now = null): int
    {
        return $this->getEventCount('order_attempt', 'user_id', (string) $userId, $windowSeconds, $now);
    }

    public function getPaymentFailureVelocity(string $identifierType, string $identifierValue, int $windowSeconds = 900, ?DateTimeImmutable $now = null): int
    {
        return $this->getEventCount('payment_failure', $identifierType, $identifierValue, $windowSeconds, $now);
    }
}
