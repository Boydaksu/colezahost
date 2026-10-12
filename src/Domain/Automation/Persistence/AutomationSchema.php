<?php
declare(strict_types=1);
namespace Coleza\Domain\Automation\Persistence;

use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Database\PdoSchema;

final class AutomationSchema
{
    public static function installSchema(Connection $db): void
    {
        PdoSchema::autoIncrement($db->getPdo()); // Reject MySQL DDL inside a transaction.
        \Coleza\Domain\Automation\Execution\DatabaseActionAttemptJournal::installSchema($db);
        $text = $db->getDriverName() === 'mysql' ? 'LONGTEXT' : 'TEXT';
        $suffix = $db->getDriverName() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin' : '';
        $db->statement("CREATE TABLE IF NOT EXISTS automation_state_lock (id INT PRIMARY KEY CHECK (id = 1)){$suffix}");
        $db->statement("CREATE TABLE IF NOT EXISTS automation_pending (
            id VARCHAR(128) PRIMARY KEY, kind VARCHAR(16) NOT NULL, status VARCHAR(32) NOT NULL,
            payload {$text} NOT NULL){$suffix}");
        $db->statement("CREATE TABLE IF NOT EXISTS automation_run_history (
            id VARCHAR(128) PRIMARY KEY, rule_id VARCHAR(128) NOT NULL,
            correlation_id VARCHAR(128) NOT NULL, executed_at VARCHAR(32) NOT NULL,
            payload {$text} NOT NULL){$suffix}");
        $db->statement("CREATE TABLE IF NOT EXISTS automation_emergency_pause (
            id INT PRIMARY KEY CHECK (id = 1), payload {$text} NOT NULL){$suffix}");
        $ignore = $db->getDriverName() === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
        $db->statement("{$ignore} INTO automation_state_lock (id) VALUES (1)");
        $db->statement("{$ignore} INTO automation_emergency_pause (id, payload) VALUES (1, ?)",
            [AutomationCodec::encode(\Coleza\Domain\Automation\Safety\EmergencyPauseState::active()->toArray())]);
        foreach ([
            ['automation_pending', 'automation_pending_status_idx', 'kind, status'],
            ['automation_run_history', 'automation_history_rule_idx', 'rule_id, executed_at, id'],
            ['automation_run_history', 'automation_history_correlation_idx', 'correlation_id, executed_at, id'],
            ['automation_run_history', 'automation_history_time_idx', 'executed_at, id'],
        ] as [$table, $name, $columns]) {
            if ($db->getDriverName() === 'sqlite') {
                $db->statement("CREATE INDEX IF NOT EXISTS {$name} ON {$table} ({$columns})");
            } elseif ($db->selectOne('SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', [$table, $name]) === null) {
                $db->statement("CREATE INDEX {$name} ON {$table} ({$columns})");
            }
        }
    }
}
