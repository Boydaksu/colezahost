<?php

declare(strict_types=1);

return new class implements \Coleza\Foundation\Database\MigrationInterface {
    public function up(\Coleza\Foundation\Database\Connection $db): void
    {
        $exists = $db->selectOne("SHOW TABLES LIKE 'partial_ddl_probe'");
        $db->statement('CREATE TABLE IF NOT EXISTS partial_ddl_probe (id INT PRIMARY KEY)');
        if ($exists === null) {
            throw new RuntimeException('Simulated failure after committed DDL');
        }
    }

    public function down(\Coleza\Foundation\Database\Connection $db): void
    {
        $db->statement('DROP TABLE partial_ddl_probe');
    }
};
