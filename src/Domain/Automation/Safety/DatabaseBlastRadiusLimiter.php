<?php
declare(strict_types=1);
namespace Coleza\Domain\Automation\Safety;

use Coleza\Foundation\Database\{Connection, PdoSchema};

/** One global rolling budget per installation, committed before external effects. No runtime DDL. */
final class DatabaseBlastRadiusLimiter implements BlastRadiusLimiterInterface
{
    private readonly DatabaseEmergencyPauseManager $pause;
    private readonly DestructiveActionRegistry $registry;

    public function __construct(
        private readonly Connection $db,
        private readonly int $maxDestructiveActions = 5,
        private readonly int $windowSeconds = 3600
    ) {
        if ($maxDestructiveActions < 1 || $windowSeconds < 1) { throw new \InvalidArgumentException('Positive blast radius policy required.'); }
        $this->registry = new DestructiveActionRegistry();
        $this->pause = new DatabaseEmergencyPauseManager($db, $this->registry);
        $this->validatePolicy();
        $db->select('SELECT id, action_type, target_id, admitted_at FROM automation_destructive_permits LIMIT 0');
    }

    public static function installSchema(Connection $db): void
    {
        PdoSchema::autoIncrement($db->getPdo());
        $suffix = $db->getDriverName() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin' : '';
        $db->statement("CREATE TABLE IF NOT EXISTS automation_blast_policy (
            id INT PRIMARY KEY CHECK (id = 1), max_actions INT NOT NULL CHECK (max_actions > 0),
            window_seconds INT NOT NULL CHECK (window_seconds > 0)){$suffix}");
        $db->statement("CREATE TABLE IF NOT EXISTS automation_destructive_permits (
            id VARCHAR(64) PRIMARY KEY, action_type VARCHAR(128) NOT NULL, target_id VARCHAR(128),
            admitted_at BIGINT NOT NULL CHECK (admitted_at >= 0)){$suffix}");
        $ignore = $db->getDriverName() === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
        $db->statement("{$ignore} INTO automation_blast_policy (id, max_actions, window_seconds) VALUES (1, 5, 3600)");
        if ($db->getDriverName() === 'sqlite') {
            $db->statement('CREATE INDEX IF NOT EXISTS automation_permits_time_idx ON automation_destructive_permits (admitted_at, id)');
        } elseif ($db->selectOne('SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            ['automation_destructive_permits', 'automation_permits_time_idx']) === null) {
            $db->statement('CREATE INDEX automation_permits_time_idx ON automation_destructive_permits (admitted_at, id)');
        }
    }

    public function tryAcquire(string $actionType, ?string $targetId = null): bool
    {
        if (!$this->registry->isDestructive($actionType)) { return true; }
        // A caller rollback must never erase a permit after an irreversible provider effect.
        if ($this->db->inTransaction()) { throw new \LogicException('Destructive admission requires a committed boundary outside the caller transaction.'); }
        return $this->db->transaction(function () use ($actionType, $targetId): bool {
            $this->db->lockRow('automation_state_lock', 1);
            if ($this->db->selectOne('SELECT id FROM automation_state_lock WHERE id = 1' . $this->db->forUpdate()) === null) {
                throw new \RuntimeException('Automation state lock missing; destructive admission refused.');
            }
            $this->validatePolicy();
            if ($this->pause->isPaused(actionType: $actionType)) { return false; }
            $now = $this->databaseTime();
            $recent = $this->db->select('SELECT id FROM automation_destructive_permits WHERE admitted_at >= ?' . $this->db->forUpdate(), [$now - $this->windowSeconds]);
            if (count($recent) >= $this->maxDestructiveActions) {
                // Existing ALL/DESTRUCTIVE pause was checked above and must never be downgraded.
                $this->pause->pause('BlastRadiusLimiter', "Blast radius admission limit reached: {$this->maxDestructiveActions} attempts in {$this->windowSeconds}s", 'DESTRUCTIVE');
                return false;
            }
            $this->db->insert('automation_destructive_permits', ['id' => bin2hex(random_bytes(16)),
                'action_type' => trim($actionType), 'target_id' => $targetId, 'admitted_at' => $now]);
            return true;
        });
    }

    public function canExecute(string $actionType): bool
    {
        if (!$this->registry->isDestructive($actionType)) { return true; }
        $this->validatePolicy();
        return !$this->pause->isPaused(actionType: $actionType) && $this->getRecentDestructiveCount() < $this->maxDestructiveActions;
    }
    public function getRecentDestructiveCount(): int
    {
        return count($this->db->select('SELECT id FROM automation_destructive_permits WHERE admitted_at >= ?' . $this->db->forUpdate(), [$this->databaseTime() - $this->windowSeconds]));
    }
    public function getMaxDestructiveActions(): int { return $this->maxDestructiveActions; }
    public function getWindowSeconds(): int { return $this->windowSeconds; }
    public function getDestructiveRegistry(): DestructiveActionRegistry { return new DestructiveActionRegistry(); }
    public function getEmergencyPauseManager(): EmergencyPauseManagerInterface { return $this->pause; }

    private function validatePolicy(): void
    {
        $row = $this->db->selectOne('SELECT max_actions, window_seconds FROM automation_blast_policy WHERE id = 1' . $this->db->forUpdate());
        if ($row === null || (int) $row['max_actions'] !== $this->maxDestructiveActions || (int) $row['window_seconds'] !== $this->windowSeconds) {
            throw new \RuntimeException('Missing or inconsistent global blast radius policy; automation must stop.');
        }
    }
    private function databaseTime(): int
    {
        $sql = $this->db->getDriverName() === 'mysql' ? 'SELECT UNIX_TIMESTAMP() AS now_seconds' : "SELECT CAST(strftime('%s', 'now') AS INTEGER) AS now_seconds";
        $now = (int) $this->db->selectOne($sql)['now_seconds'];
        if ($now < 1) { throw new \RuntimeException('Database clock unavailable; admission refused.'); }
        return $now;
    }
}
