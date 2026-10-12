<?php
declare(strict_types=1);
return new class implements \Coleza\Foundation\Database\MigrationInterface {
    public function up(\Coleza\Foundation\Database\Connection $db): void
    {
        (new \Coleza\Foundation\Cache\DatabaseCache($db))->ensureCacheTable(installSchema: true);
        (new \Coleza\Foundation\Lock\DatabaseLock($db))->ensureLocksTable(installSchema: true);
        (new \Coleza\Foundation\Idempotency\IdempotencyManager($db))->ensureTable(installSchema: true);
        (new \Coleza\Foundation\Scheduler\Scheduler($db))->ensureHeartbeatTable(installSchema: true);
    }
    public function down(\Coleza\Foundation\Database\Connection $db): void
    {
        throw new RuntimeException('Runtime lock, replay and heartbeat rollback requires an explicit maintenance and retention plan.');
    }
};
