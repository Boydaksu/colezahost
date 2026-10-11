<?php
declare(strict_types=1);
return new class implements \Coleza\Foundation\Database\MigrationInterface {
    public function up(\Coleza\Foundation\Database\Connection $db): void
    {
        (new \Coleza\Domain\Installer\ProviderIdentitySchema($db))->ensure();
    }
    public function down(\Coleza\Foundation\Database\Connection $db): void
    {
        throw new RuntimeException('Provider identity rollback requires a verified backup.');
    }
};
