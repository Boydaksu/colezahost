<?php
declare(strict_types=1);
return new class implements \Coleza\Foundation\Database\MigrationInterface {
    public function up(\Coleza\Foundation\Database\Connection $db): void
    {
        // Check every existing table before creating any missing sibling. No business defaults are seeded.
        \Coleza\Foundation\Database\PdoSchema::useInstalledSchema($db,
            \Coleza\Domain\Brand\BrandService::schemaRequirements() + \Coleza\Domain\Finance\Currency\CurrencyService::schemaRequirements(),
            installSchema: true);
        (new \Coleza\Domain\Brand\BrandService($db))->ensureTable(installSchema: true);
        (new \Coleza\Domain\Finance\Currency\CurrencyService($db))->ensureTables(installSchema: true);
    }
    public function down(\Coleza\Foundation\Database\Connection $db): void
    {
        throw new RuntimeException('Brand configuration and financial currency/FX history require an explicit backup and retention plan before rollback.');
    }
};
