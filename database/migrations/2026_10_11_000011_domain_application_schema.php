<?php
declare(strict_types=1);
return new class implements \Coleza\Foundation\Database\MigrationInterface {
    public function up(\Coleza\Foundation\Database\Connection $db): void
    {
        (new \Coleza\Domain\Domains\DomainService($db))->ensureTables();
        \Coleza\Domain\Migration\Adoption\AdoptionSchema::ensure($db);
    }
    public function down(\Coleza\Foundation\Database\Connection $db): void
    {
        throw new RuntimeException('Domain history rollback requires a verified backup.');
    }
};
