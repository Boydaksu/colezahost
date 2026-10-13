<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class AnalyticsSchemaTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\AnalyticsSchemaCases;
    public function testMyisamSchemaRefusesBeforeWriting(): void
    {
        $this->installAnalytics(); $this->seedAnalyticsRows();
        $this->db->statement('ALTER TABLE analytics_daily_aggregations ENGINE=MyISAM');
        $before = $this->analyticsRows();
        try { (new \Coleza\Domain\Analytics\ReadModels\ReadModelAggregationService($this->db))->aggregateDay('2026-10-13'); self::fail('Nontransactional analytics write accepted'); }
        catch (\LogicException $error) { self::assertStringContainsString('InnoDB', $error->getMessage()); }
        self::assertSame($before, $this->analyticsRows());
    }
}
