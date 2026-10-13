<?php
declare(strict_types=1);
namespace Coleza\Domain\Analytics;
use Coleza\Foundation\Database\Connection;
use Throwable;

/** Joins callers with a savepoint; never commits or rolls back their earlier work. */
final class AnalyticsTransaction
{
    public static function run(Connection $db, callable $callback): mixed
    {
        AnalyticsSchema::assertCaller($db);
        if (!$db->getPdo()->inTransaction()) { return $db->transaction($callback); }
        $name = 'analytics_' . bin2hex(random_bytes(8)); $db->statement('SAVEPOINT ' . $name);
        try { $value = $callback(); $db->statement('RELEASE SAVEPOINT ' . $name); return $value; }
        catch (Throwable $error) {
            if ($db->getPdo()->inTransaction()) { $db->statement('ROLLBACK TO SAVEPOINT ' . $name); $db->statement('RELEASE SAVEPOINT ' . $name); }
            throw $error;
        }
    }
}
