<?php
declare(strict_types=1);
namespace Coleza\Domain\Support;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Database\PdoSchema;
use LogicException;
use Throwable;

/** Installed support schema is checked before DDL or transactional business writes. */
final class SupportPersistence
{
    public static function installed(Connection $db, array $requirements, bool $legacyTicketColumns = false): bool
    {
        self::assertCaller($db);
        $core = $requirements;
        if ($legacyTicketColumns) {
            $core['support_tickets'] = array_values(array_diff($core['support_tickets'], ['invoice_id', 'order_id']));
        }
        // ensureTables/installSchema are explicit setup APIs; preserve migration9 SQLite transactional installation.
        $installed = PdoSchema::useInstalledSchema($db, $core, installSchema: true);
        if ($db->getDriverName() === 'mysql') {
            foreach (array_keys($requirements) as $table) {
                $row = $db->selectOne('SELECT ENGINE FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]);
                if ($row !== null && strcasecmp((string) $row['ENGINE'], 'InnoDB') !== 0) { throw new LogicException('Support tables require InnoDB; explicit upgrade required.'); }
            }
        }
        if ($legacyTicketColumns) {
            $columns = $db->getDriverName() === 'sqlite'
                ? array_column($db->select('PRAGMA table_info(support_tickets)'), 'name')
                : ($db->selectOne('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', ['support_tickets']) === null ? [] : array_column($db->select('SHOW COLUMNS FROM support_tickets'), 'Field'));
            if ($columns !== []) {
                foreach (['invoice_id', 'order_id'] as $column) {
                    if (in_array($column, $columns, true)) { continue; }
                    PdoSchema::autoIncrement($db->getPdo());
                    $db->statement('ALTER TABLE support_tickets ADD COLUMN ' . $column . ' INT NULL');
                }
            }
        }
        if (!$installed) { PdoSchema::autoIncrement($db->getPdo()); }
        return $installed;
    }

    /** Business paths only accept a complete existing schema; no lazy installation. */
    public static function requireSchema(Connection $db, array $requirements): void
    {
        self::assertCaller($db);
        if (!PdoSchema::useInstalledSchema($db, $requirements)) { throw new LogicException('Install complete support schema before business operations.'); }
        if ($db->getDriverName() === 'mysql') {
            foreach (array_keys($requirements) as $table) {
                $engine = $db->selectOne('SELECT ENGINE FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]);
                if (strcasecmp((string) $engine['ENGINE'], 'InnoDB') !== 0) { throw new LogicException('Support tables require InnoDB; explicit upgrade required.'); }
            }
        }
    }

    public static function assertCaller(Connection $db): void
    {
        if ($db->inTransaction() && !$db->getPdo()->inTransaction()) { throw new LogicException('Support caller transaction ended unexpectedly.'); }
    }

    /** Savepoints preserve raw and managed callers, including their earlier writes. */
    public static function run(Connection $db, callable $callback): mixed
    {
        self::assertCaller($db);
        if (!$db->getPdo()->inTransaction()) { return $db->transaction($callback); }
        $name = 'support_' . bin2hex(random_bytes(8)); $db->statement('SAVEPOINT ' . $name);
        try { $value = $callback(); $db->statement('RELEASE SAVEPOINT ' . $name); return $value; }
        catch (Throwable $error) {
            if ($db->getPdo()->inTransaction()) { $db->statement('ROLLBACK TO SAVEPOINT ' . $name); $db->statement('RELEASE SAVEPOINT ' . $name); }
            throw $error;
        }
    }
}
