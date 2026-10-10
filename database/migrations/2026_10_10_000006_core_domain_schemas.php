<?php
declare(strict_types=1);

return new class implements \Coleza\Foundation\Database\MigrationInterface {
    public function up(\Coleza\Foundation\Database\Connection $db): void
    {
        (new \Coleza\Domain\Installer\DatabaseSetupService())->initializeCoreSchema($db);
        $columns = $db->getDriverName() === 'sqlite'
            ? array_column($db->select('PRAGMA table_info(cron_runs)'), 'name')
            : array_column($db->select('SHOW COLUMNS FROM cron_runs'), 'Field');
        if (!in_array('run_at', $columns, true)) {
            $db->statement('ALTER TABLE cron_runs ADD COLUMN run_at TIMESTAMP NULL');
            if (in_array('ran_at', $columns, true)) { $db->statement('UPDATE cron_runs SET run_at = ran_at WHERE run_at IS NULL'); }
        }
        if (!in_array('status', $columns, true)) {
            // Old Doctor rows do not prove scheduler success. Retain the history without fabricating a success.
            $db->statement("ALTER TABLE cron_runs ADD COLUMN status VARCHAR(32) NOT NULL DEFAULT 'unknown'");
        }
        // Reuse the schema owned by each domain instead of installer-specific copies.
        foreach ([
            \Coleza\Domain\Catalog\Services\CatalogService::class,
            \Coleza\Domain\Pricing\Services\PricingService::class,
            \Coleza\Domain\Tax\Services\TaxService::class,
            \Coleza\Domain\Commerce\Orders\OrderService::class,
            \Coleza\Domain\Commerce\Invoices\InvoiceService::class,
            \Coleza\Domain\Commerce\Credit\CreditService::class,
            \Coleza\Domain\Commerce\Services\ServiceService::class,
            \Coleza\Domain\Finance\Accounts\FinancialAccountService::class,
            \Coleza\Domain\Finance\Expenses\ExpenseService::class,
            \Coleza\Domain\Finance\Profitability\ProfitabilityService::class,
            \Coleza\Domain\Identity\Organization\OrganizationService::class,
        ] as $service) { (new $service($db))->ensureTables(); }
        (new \Coleza\Domain\Module\ModuleLifecycleService($db))->ensureTable();
    }
    public function down(\Coleza\Foundation\Database\Connection $db): void
    {
        throw new RuntimeException('Domain schema rollback requires a verified backup.');
    }
};
