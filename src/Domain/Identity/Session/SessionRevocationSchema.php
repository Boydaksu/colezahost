<?php
declare(strict_types=1);
namespace Coleza\Domain\Identity\Session;
use Coleza\Domain\Identity\IdentityPersistence;
use Coleza\Foundation\Database\Connection;

/** Durable revoked IDs are never recycled by session garbage collection. */
final class SessionRevocationSchema
{
    private const TABLES = ['session_lifecycle_lock' => ['id'], 'session_revocations' => ['session_id', 'revoked_at']];
    public static function schemaRequirements(): array { return self::TABLES; }
    public static function install(Connection $db, bool $installation = true): void
    {
        if (!IdentityPersistence::installed($db, self::TABLES, $installation, primary: ['session_revocations' => 'session_id'], onlyPrimary: array_keys(self::TABLES))) {
            $engine = $db->getDriverName() === 'mysql' ? ' ENGINE=InnoDB' : '';
            $db->statement('CREATE TABLE IF NOT EXISTS session_lifecycle_lock (id INT NOT NULL PRIMARY KEY CHECK (id = 1))' . $engine);
            $db->statement('CREATE TABLE IF NOT EXISTS session_revocations (session_id VARCHAR(128) NOT NULL PRIMARY KEY, revoked_at INT NOT NULL)' . $engine);
        }
        // Seeding is explicit installation only; business paths never repair a missing mutex.
        if ($installation) {
            $sql = 'INSERT INTO session_lifecycle_lock (id) VALUES (1)' . ($db->getDriverName() === 'mysql' ? ' ON DUPLICATE KEY UPDATE id = id' : ' ON CONFLICT(id) DO NOTHING');
            $db->statement($sql);
        }
        if ($db->selectOne('SELECT id FROM session_lifecycle_lock WHERE id = 1') === null) { throw new \LogicException('Session lifecycle mutex is missing; explicit upgrade required.'); }
    }
    public static function prepare(Connection $db): void
    {
        // Keep standalone compatibility, while requiring complete schema before entering caller transactions.
        if (IdentityPersistence::installed($db, self::TABLES, installation: false, primary: ['session_revocations' => 'session_id'], onlyPrimary: array_keys(self::TABLES))) { self::install($db, false); return; }
        self::install($db);
    }
    public static function run(Connection $db, callable $callback): mixed
    {
        self::prepare($db);
        return IdentityPersistence::run($db, function () use ($db, $callback): mixed {
            $row = $db->selectOne('SELECT id FROM session_lifecycle_lock WHERE id = 1' . IdentityPersistence::lock($db));
            if ($row === null) { throw new \LogicException('Session lifecycle mutex is missing.'); }
            return $callback();
        });
    }
    public static function revoke(Connection $db, string $id): void
    {
        $sql = 'INSERT INTO session_revocations (session_id, revoked_at) VALUES (?, ?)' . ($db->getDriverName() === 'mysql' ? ' ON DUPLICATE KEY UPDATE session_id = session_id' : ' ON CONFLICT(session_id) DO NOTHING');
        $db->statement($sql, [$id, time()]);
    }
}
