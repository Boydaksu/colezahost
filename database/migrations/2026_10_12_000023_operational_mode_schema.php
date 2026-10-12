<?php
declare(strict_types=1);
return new class implements \Coleza\Foundation\Database\MigrationInterface {
    public function up(\Coleza\Foundation\Database\Connection $db): void
    {
        \Coleza\Domain\OperationalMode\OperationalModeManager::ensureSchema($db, installSchema: true);
    }
    public function down(\Coleza\Foundation\Database\Connection $db): void
    {
        throw new RuntimeException('Shared system settings and operational state require an explicit maintenance plan with backup before rollback.');
    }
};
