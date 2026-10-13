<?php
declare(strict_types=1);
return new class implements \Coleza\Foundation\Database\MigrationInterface {
    public function up(\Coleza\Foundation\Database\Connection $db): void
    {
        \Coleza\Domain\Analytics\ReadModels\DailyAggregationEvidence::install($db);
    }
    public function down(\Coleza\Foundation\Database\Connection $db): void
    {
        throw new RuntimeException('Daily evidence rollback requires an explicit maintenance plan with backup.');
    }
};
