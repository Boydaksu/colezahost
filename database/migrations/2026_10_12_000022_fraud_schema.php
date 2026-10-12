<?php
declare(strict_types=1);
return new class implements \Coleza\Foundation\Database\MigrationInterface {
    public function up(\Coleza\Foundation\Database\Connection $db): void
    {
        $services = [
            \Coleza\Domain\Fraud\Risk\RiskScoreService::class,
            \Coleza\Domain\Fraud\Review\RiskReviewService::class,
            \Coleza\Domain\Fraud\Lists\FraudListService::class,
            \Coleza\Domain\Fraud\Velocity\VelocityTrackerService::class,
        ];
        $requirements = [];
        foreach ($services as $class) { $requirements += $class::schemaRequirements(); }
        // Check all six existing tables before any CREATE, including later services.
        \Coleza\Foundation\Database\PdoSchema::useInstalledSchema($db, $requirements, installSchema: true);
        foreach ($services as $class) { (new $class($db))->ensureTables(installSchema: true); }
    }
    public function down(\Coleza\Foundation\Database\Connection $db): void
    {
        throw new RuntimeException('Fraud evaluations, review decisions and risk evidence require an explicit maintenance plan with backup and retention review before rollback.');
    }
};
