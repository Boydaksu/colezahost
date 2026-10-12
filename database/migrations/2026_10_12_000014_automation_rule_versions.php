<?php
declare(strict_types=1);
return new class implements \Coleza\Foundation\Database\MigrationInterface {
    public function up(\Coleza\Foundation\Database\Connection $db): void
    {
        \Coleza\Domain\Automation\Versioning\DatabaseRuleVersionManager::installSchema($db);
    }
    public function down(\Coleza\Foundation\Database\Connection $db): void
    {
        throw new RuntimeException('Rule version rollback requires an explicit audit retention plan.');
    }
};
