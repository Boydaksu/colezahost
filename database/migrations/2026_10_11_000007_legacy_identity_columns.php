<?php
declare(strict_types=1);

return new class implements \Coleza\Foundation\Database\MigrationInterface {
    public function up(\Coleza\Foundation\Database\Connection $db): void
    {
        (new \Coleza\Domain\Identity\LegacyIdentitySchema($db))->upgrade();
    }
    public function down(\Coleza\Foundation\Database\Connection $db): void
    {
        throw new RuntimeException('Identity column rollback requires a verified backup; access states must not be reset.');
    }
};
