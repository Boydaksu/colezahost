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
    /** @var array<int, array{query: string, bindings: array<mixed>, time_ms: float}> */
    private array $queryLog = [];
    private bool $loggingQueries = false;

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

    /**
     * @param array<int|string, mixed> $bindings
     */
    public function affectingStatement(string $query, array $bindings = []): int
    {
        return $this->executeStatement($query, $bindings)->rowCount();
    }

    /** Allocate a number atomically on the same connection, including concurrent callers. */
    public function nextSequence(string $table, string $keyColumn, string $key): int
    {
        foreach ([$table, $keyColumn] as $identifier) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $identifier)) {
                throw new \InvalidArgumentException('Invalid sequence identifier.');
            }
        }
        if ($this->getDriverName() === 'mysql') {
            $this->statement(sprintf(
                'INSERT INTO %s (%s, last_number) VALUES (?, LAST_INSERT_ID(1))
                 ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)', $table, $keyColumn), [$key]);
            return (int) $this->pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
        }
        if ($this->getDriverName() !== 'sqlite') {
            throw new \RuntimeException('Unsupported sequence database driver.');
        }
        return $this->transaction(function (self $db) use ($table, $keyColumn, $key): int {
            $db->statement(sprintf(
                'INSERT INTO %s (%s, last_number) VALUES (?, 1)
                 ON CONFLICT(%s) DO UPDATE SET last_number = last_number + 1', $table, $keyColumn, $keyColumn), [$key]);
            $row = $db->selectOne(sprintf('SELECT last_number FROM %s WHERE %s = ?', $table, $keyColumn), [$key]);
            return (int) $row['last_number'];
        });
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

    public function enableQueryLog(): void
    {
        $this->loggingQueries = true;
    }

    public function disableQueryLog(): void
    {
        $this->loggingQueries = false;
    }

    public function isQueryLogEnabled(): bool
    {
        return $this->loggingQueries;
    }

    /**
     * @return array<int, array{query: string, bindings: array<mixed>, time_ms: float}>
     */
    public function getQueryLog(): array
    {
        return $this->queryLog;
    }

    public function flushQueryLog(): void
    {
        $this->queryLog = [];
    }

    public function getQueryCount(): int
    {
        return count($this->queryLog);
    }

    /**
     * @param array<int|string, mixed> $bindings
     */
    private function executeStatement(string $query, array $bindings = []): PDOStatement
    {
        $start = $this->loggingQueries ? microtime(true) : 0.0;
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

        if ($this->loggingQueries) {
            $this->queryLog[] = [
                'query' => $query,
                'bindings' => $bindings,
                'time_ms' => (microtime(true) - $start) * 1000.0,
            ];
        }

        return $stmt;
    }
}
