<?php
declare(strict_types=1);
return new class implements \Coleza\Foundation\Database\MigrationInterface {
    public function up(\Coleza\Foundation\Database\Connection $db): void
    {
        \Coleza\Foundation\Database\ConfigurationWriteLock::ensureSchema($db, installSchema: true);
    }
    public function down(\Coleza\Foundation\Database\Connection $db): void
    {
        throw new RuntimeException('Configuration write locks require stopped writers and an explicit maintenance plan before rollback.');
    }
};
