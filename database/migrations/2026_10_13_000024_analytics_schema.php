<?php
declare(strict_types=1);
return new class implements \Coleza\Foundation\Database\MigrationInterface {
    public function up(\Coleza\Foundation\Database\Connection $db): void
    {
        $classes = [\Coleza\Domain\Analytics\Subscription\SubscriptionSnapshotService::class, \Coleza\Domain\Analytics\ReadModels\ReadModelAggregationService::class];
        $requirements = []; $indexes = [];
        foreach ($classes as $class) { $requirements += $class::schemaRequirements(); $indexes += $class::indexRequirements(); }
        \Coleza\Domain\Analytics\AnalyticsSchema::assertCaller($db);
        \Coleza\Foundation\Database\PdoSchema::useInstalledSchema($db, $requirements, installSchema: true);
        \Coleza\Domain\Analytics\AnalyticsSchema::preflightExisting($db, $indexes);
        foreach ($classes as $class) { (new $class($db))->ensureTables(installSchema: true); }
    }
    public function down(\Coleza\Foundation\Database\Connection $db): void
    {
        throw new RuntimeException('Analytics history rollback requires an explicit maintenance plan with backup.');
    }
};
