<?php
declare(strict_types=1);
namespace Coleza\Domain\Fraud;
use Coleza\Foundation\Database\{Connection, PdoSchema};

/** Atomic local writes; joins raw or managed callers without committing their work. */
final class FraudWriteTransaction
{
    public static function assertCaller(Connection $db): void
    {
        if (!$db->getPdo()->inTransaction() && $db->inTransaction()) {
            throw new \LogicException('Caller transaction ended unexpectedly; fraud write refused.');
        }
    }
    public static function run(Connection $db, array $requirements, callable $callback): mixed
    {
        self::assertCaller($db);
        if (!PdoSchema::useInstalledSchema($db, $requirements)) { throw new \LogicException('Initialize fraud schema before writing.'); }
        foreach ($requirements as $table => $columns) {
            if ($db->getDriverName() === 'mysql') {
                $engine = $db->selectOne('SELECT ENGINE FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]);
                if ($engine === null || strcasecmp((string) $engine['ENGINE'], 'InnoDB') !== 0) { throw new \LogicException('Fraud writes require InnoDB; explicit storage upgrade required.'); }
                $pk = array_values(array_filter($db->select("SHOW INDEX FROM {$table}"), fn($row) => $row['Key_name'] === 'PRIMARY'));
                $valid = count($pk) === 1 && $pk[0]['Column_name'] === 'id' && (int) $pk[0]['Non_unique'] === 0;
            } else {
                $pk = array_values(array_filter($db->select("PRAGMA table_info({$table})"), fn($row) => (int) $row['pk'] > 0));
                $valid = count($pk) === 1 && $pk[0]['name'] === 'id';
            }
            if (!$valid) { throw new \LogicException('Fraud writes require a single id primary key; explicit schema upgrade required.'); }
        }
        if (!$db->getPdo()->inTransaction()) { return $db->transaction($callback); }
        $savepoint = 'fraud_write_' . bin2hex(random_bytes(8));
        $db->statement("SAVEPOINT {$savepoint}");
        try {
            $result = $callback(); $db->statement("RELEASE SAVEPOINT {$savepoint}"); return $result;
        } catch (\Throwable $error) {
            if ($db->getPdo()->inTransaction()) {
                $db->statement("ROLLBACK TO SAVEPOINT {$savepoint}"); $db->statement("RELEASE SAVEPOINT {$savepoint}");
            }
            throw $error;
        }
    }
}
