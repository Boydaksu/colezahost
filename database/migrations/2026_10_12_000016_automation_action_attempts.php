<?php
declare(strict_types=1);
return new class implements \Coleza\Foundation\Database\MigrationInterface {
    public function up(\Coleza\Foundation\Database\Connection $db): void
    {
        \Coleza\Domain\Automation\Execution\DatabaseActionAttemptJournal::installSchema($db);
    }
    public function down(\Coleza\Foundation\Database\Connection $db): void
    {
        throw new RuntimeException('Action evidence rollback requires an explicit recovery and audit retention plan.');
    }
};
