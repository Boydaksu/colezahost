<?php
declare(strict_types=1);
namespace Coleza\Tests\Unit\Analytics;
final class AnalyticsSchemaTest extends \PHPUnit\Framework\TestCase
{
    use \Coleza\Tests\Support\AnalyticsSchemaCases;
    private \Coleza\Foundation\Database\Connection $db;
    protected function setUp(): void { $this->db = new \Coleza\Foundation\Database\Connection(new \PDO('sqlite::memory:')); }
}
