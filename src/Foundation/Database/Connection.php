<?php

declare(strict_types=1);

namespace Coleza\Foundation\Database;

use PDO;
use PDOException;
use PDOStatement;
use Throwable;

final class Connection
{
    private int $transactionDepth = 0;

    public function __construct(private PDO $pdo)
    {
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function getDriverName(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /**
     * @param array<int|string, mixed> $bindings
     * @return array<int, array<string, mixed>>
     */
    public function select(string $query, array $bindings = []): array
    {
        $statement = $this->executeStatement($query, $bindings);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<int|string, mixed> $bindings
     * @return array<string, mixed>|null
     */
    public function selectOne(string $query, array $bindings = []): ?array
    {
        $records = $this->select($query, $bindings);
        return count($records) > 0 ? $records[0] : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): string|int
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn(string $col): string => ':' . $col, $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $this->executeStatement($sql, $data);

        $lastId = $this->pdo->lastInsertId();
        return is_numeric($lastId) ? (int) $lastId : $lastId;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $whereBindings
     */
    public function update(string $table, array $data, string $where, array $whereBindings = []): int
    {
        $setClauses = [];
        $bindings = [];

        foreach ($data as $col => $val) {
            $param = 'u_' . $col;
            $setClauses[] = sprintf('%s = :%s', $col, $param);
            $bindings[$param] = $val;
        }

        foreach ($whereBindings as $k => $v) {
            $bindings[$k] = $v;
        }

        $sql = sprintf('UPDATE %s SET %s WHERE %s', $table, implode(', ', $setClauses), $where);
        $statement = $this->executeStatement($sql, $bindings);

        return $statement->rowCount();
    }

    /**
     * @param array<string, mixed> $whereBindings
     */
    public function delete(string $table, string $where, array $whereBindings = []): int
    {
        $sql = sprintf('DELETE FROM %s WHERE %s', $table, $where);
        $statement = $this->executeStatement($sql, $whereBindings);

        return $statement->rowCount();
    }

    /**
     * @param array<int|string, mixed> $bindings
     */
    public function statement(string $query, array $bindings = []): bool
    {
        return $this->executeStatement($query, $bindings)->rowCount() >= 0;
    }

    public function beginTransaction(): void
    {
        if ($this->transactionDepth === 0) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT trans_' . $this->transactionDepth);
        }

        $this->transactionDepth++;
    }

    public function commit(): void
    {
        if ($this->transactionDepth <= 0) {
            return;
        }

        $this->transactionDepth--;

        if ($this->transactionDepth === 0) {
            $this->pdo->commit();
        } else {
            $this->pdo->exec('RELEASE SAVEPOINT trans_' . $this->transactionDepth);
        }
    }

    public function rollBack(): void
    {
        if ($this->transactionDepth <= 0) {
            return;
        }

        $this->transactionDepth--;

        if ($this->transactionDepth === 0) {
            $this->pdo->rollBack();
        } else {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT trans_' . $this->transactionDepth);
        }
    }

    public function inTransaction(): bool
    {
        return $this->transactionDepth > 0 || $this->pdo->inTransaction();
    }

    /**
     * Execute a callback inside an atomic transaction with automatic rollback on error.
     *
     * @template T
     * @param callable(self): T $callback
     * @return T
     * @throws Throwable
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Throwable $e) {
            $this->rollBack();
            throw $e;
        }
    }

    /**
     * @param array<int|string, mixed> $bindings
     */
    private function executeStatement(string $query, array $bindings = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($query);

        foreach ($bindings as $key => $value) {
            $param = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
            $type = match (gettype($value)) {
                'integer' => PDO::PARAM_INT,
                'boolean' => PDO::PARAM_BOOL,
                'NULL' => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($param, $value, $type);
        }

        $stmt->execute();
        return $stmt;
    }
}
