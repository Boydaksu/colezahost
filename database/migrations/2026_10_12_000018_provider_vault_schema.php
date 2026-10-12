<?php
declare(strict_types=1);
return new class implements \Coleza\Foundation\Database\MigrationInterface {
    public function up(\Coleza\Foundation\Database\Connection $db): void
    {
        \Coleza\Domain\Providers\Settings\ProviderSettingService::ensureSchema($db, installSchema: true);
    }
    public function down(\Coleza\Foundation\Database\Connection $db): void
    {
        throw new RuntimeException('Provider configuration and encrypted secrets require an explicit backup and retention plan before rollback.');
    }
};
