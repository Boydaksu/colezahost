<?php
declare(strict_types=1);
namespace Coleza\Foundation\Database;

/** Transaction-scoped serialization for cooperating configuration writers. */
final class ConfigurationWriteLock
{
    private string $table;

    public function __construct(private Connection $db, private string $prefix = '')
    {
        self::validatePrefix($prefix);
        $this->table = $prefix . 'configuration_write_locks';
    }

    public static function schemaRequirements(string $prefix = ''): array
    {
        self::validatePrefix($prefix);
        return [$prefix . 'configuration_write_locks' => ['id', 'scope']];
    }

    public static function ensureSchema(Connection $db, bool $installSchema = false, string $prefix = ''): void
    {
        if (PdoSchema::useInstalledSchema($db, self::schemaRequirements($prefix), $installSchema)) { return; }
        PdoSchema::autoIncrement($db->getPdo());
        $table = $prefix . 'configuration_write_locks';
        $db->statement("CREATE TABLE IF NOT EXISTS {$table} (
            id INT NOT NULL PRIMARY KEY CHECK (id IN (1, 2)), scope VARCHAR(16) NOT NULL UNIQUE,
            CHECK ((id = 1 AND scope = 'brand') OR (id = 2 AND scope = 'currency'))
        )" . ($db->getDriverName() === 'mysql' ? ' ENGINE=InnoDB' : ''));
    }

    public function run(string $scope, callable $callback, array $additionalTables = []): mixed
    {
        $id = match ($scope) { 'brand' => 1, 'currency' => 2, default => throw new \InvalidArgumentException('Unknown configuration lock scope.') };
        if (!PdoSchema::useInstalledSchema($this->db, [$this->table => ['id', 'scope']])) {
            throw new \LogicException('Configuration lock schema is not initialized; apply migrations first.');
        }
        if (!$this->db->getPdo()->inTransaction() && $this->db->inTransaction()) {
            throw new \LogicException('Caller transaction ended unexpectedly; configuration write refused.');
        }
        $this->validateTransactionStorage($scope, $additionalTables);
        if (!$this->db->getPdo()->inTransaction()) {
            return $this->db->transaction(fn() => $this->executeLocked($id, $scope, $callback));
        }
        // Join either Connection-managed or raw PDO caller transactions without committing them.
        $savepoint = 'configuration_write_' . bin2hex(random_bytes(8));
        $this->db->statement("SAVEPOINT {$savepoint}");
        try {
            $result = $this->executeLocked($id, $scope, $callback);
            $this->db->statement("RELEASE SAVEPOINT {$savepoint}");
            return $result;
        } catch (\Throwable $error) {
            if ($this->db->getPdo()->inTransaction()) {
                $this->db->statement("ROLLBACK TO SAVEPOINT {$savepoint}");
                $this->db->statement("RELEASE SAVEPOINT {$savepoint}");
            }
            throw $error;
        }
    }

    private function executeLocked(int $id, string $scope, callable $callback): mixed
    {
        $conflict = $this->db->getDriverName() === 'mysql' ? ' ON DUPLICATE KEY UPDATE id = id' : ' ON CONFLICT(id) DO NOTHING';
        $this->db->statement("INSERT INTO {$this->table} (id, scope) VALUES (?, ?)" . $conflict, [$id, $scope]);
        $this->db->lockRow($this->table, $id);
        $row = $this->db->selectOne("SELECT scope FROM {$this->table} WHERE id = ?" . $this->db->forUpdate(), [$id]);
        if ($row === null || $row['scope'] !== $scope) { throw new \LogicException('Configuration lock identity is inconsistent.'); }
        return $callback();
    }

    private static function validatePrefix(string $prefix): void
    {
        if ($prefix !== '' && !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $prefix)) { throw new \InvalidArgumentException('Invalid table prefix.'); }
    }

    private function validateTransactionStorage(string $scope, array $additionalTables): void
    {
        if ($this->db->getDriverName() === 'mysql') {
            $tables = array_unique(array_merge([$this->table, $this->prefix . ($scope === 'brand' ? 'brands' : 'currencies')], $additionalTables));
            foreach ($tables as $table) {
                if (!is_string($table) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $table)) { throw new \InvalidArgumentException('Invalid configuration table name.'); }
                $row = $this->db->selectOne('SELECT ENGINE FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]);
                if ($row === null || strcasecmp((string) $row['ENGINE'], 'InnoDB') !== 0) { throw new \LogicException('Configuration writes require InnoDB tables; explicit storage upgrade required.'); }
            }
            $primary = array_values(array_filter($this->db->select("SHOW INDEX FROM {$this->table}"), static fn($index) => $index['Key_name'] === 'PRIMARY'));
            $valid = count($primary) === 1 && $primary[0]['Column_name'] === 'id' && (int) $primary[0]['Non_unique'] === 0;
        } else {
            $primary = array_values(array_filter($this->db->select("PRAGMA table_info({$this->table})"), static fn($column) => (int) $column['pk'] > 0));
            $valid = count($primary) === 1 && $primary[0]['name'] === 'id';
        }
        if (!$valid) { throw new \LogicException('Configuration lock requires a single id primary key; explicit schema upgrade required.'); }
    }
}
