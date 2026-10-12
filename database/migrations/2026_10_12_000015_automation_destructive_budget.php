<?php
declare(strict_types=1);
return new class implements \Coleza\Foundation\Database\MigrationInterface {
    public function up(\Coleza\Foundation\Database\Connection $db): void
    {
        \Coleza\Domain\Automation\Safety\DatabaseBlastRadiusLimiter::installSchema($db);
    }
    public function down(\Coleza\Foundation\Database\Connection $db): void
    {
        throw new RuntimeException('Destructive permit rollback requires an explicit safety and audit retention plan.');
    }
};
