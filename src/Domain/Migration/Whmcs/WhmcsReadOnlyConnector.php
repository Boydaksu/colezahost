<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Whmcs;

use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;

/**
 * Strictly read-only connector for the external source WHMCS database.
 * Per Architecture and Data Constitution: source database mutation is strictly forbidden.
 */
final class WhmcsReadOnlyConnector
{
    public function __construct(
        private Connection $connection
    ) {
    }

    public static function fromPdo(PDO $pdo): self
    {
        return new self(new Connection($pdo));
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Executes a SELECT query after enforcing strict read-only query inspection.
     *
     * @param array<int|string, mixed> $bindings
     * @return array<int, array<string, mixed>>
     */
    public function select(string $query, array $bindings = []): array
    {
        $this->assertReadOnlyQuery($query);
        return $this->connection->select($query, $bindings);
    }

    /**
     * @param array<int|string, mixed> $bindings
     * @return array<string, mixed>|null
     */
    public function selectOne(string $query, array $bindings = []): ?array
    {
        $this->assertReadOnlyQuery($query);
        return $this->connection->selectOne($query, $bindings);
    }

    public function tableExists(string $tableName): bool
    {
        $clean = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
        $driver = $this->connection->getDriverName();

        try {
            if ($driver === 'sqlite') {
                $row = $this->connection->selectOne(
                    "SELECT name FROM sqlite_master WHERE type='table' AND name = :t",
                    ['t' => $clean]
                );
                return $row !== null;
            }

            // MySQL / MariaDB standard for WHMCS
            $row = $this->connection->selectOne(
                "SHOW TABLES LIKE :t",
                ['t' => $clean]
            );
            return $row !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    public function countTableRecords(string $tableName): int
    {
        $clean = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
        if (!$this->tableExists($clean)) {
            return 0;
        }

        try {
            $row = $this->selectOne(sprintf('SELECT COUNT(*) as cnt FROM %s', $clean));
            return (int) ($row['cnt'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Guardrail asserting that the query does not contain mutation or DDL keywords.
     */
    private function assertReadOnlyQuery(string $query): void
    {
        $normalized = strtoupper(trim($query));
        $forbiddenKeywords = [
            'INSERT INTO',
            'UPDATE ',
            'DELETE FROM',
            'DROP TABLE',
            'DROP DATABASE',
            'ALTER TABLE',
            'TRUNCATE TABLE',
            'REPLACE INTO',
            'CREATE TABLE',
            'RENAME TABLE',
        ];

        foreach ($forbiddenKeywords as $keyword) {
            if (str_contains($normalized, $keyword)) {
                throw new ValidationException(
                    ['query' => "Write operation [{$keyword}] is strictly forbidden on read-only WHMCS connector."],
                    "Write operation [{$keyword}] is strictly forbidden on read-only WHMCS connector."
                );
            }
        }
    }
}
