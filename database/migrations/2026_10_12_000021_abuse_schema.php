<?php
declare(strict_types=1);
return new class implements \Coleza\Foundation\Database\MigrationInterface {
    public function up(\Coleza\Foundation\Database\Connection $db): void
    {
        (new \Coleza\Domain\Abuse\AbuseCaseService($db))->ensureTables(installSchema: true);
    }
    public function down(\Coleza\Foundation\Database\Connection $db): void
    {
        throw new RuntimeException('Abuse evidence and message history require an explicit maintenance plan with backup and retention review before rollback.');
    }
};
