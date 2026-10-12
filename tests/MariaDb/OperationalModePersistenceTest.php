<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class OperationalModePersistenceTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\OperationalModePersistenceCases;
    protected function setUp(): void { parent::setUp(); $this->root->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false); }
    public function testFourParallelTransitionsStoreOneCoherentModeTuple(): void
    {
        $this->manager(); $results = $this->runParallel('operationalModeTransition', 0);
        self::assertSame(['ok', 'ok', 'ok', 'ok'], array_column($results, 'status'));
        $status = $this->manager()->getModeStatus(); $modes = ['safe', 'read_only', 'recovery', 'full_maintenance'];
        $index = array_search($status['mode'], $modes, true); self::assertNotFalse($index);
        self::assertSame('Worker ' . $index, $status['reason']); self::assertSame(['192.0.2.' . ($index + 1)], $status['whitelist_ips']);
        self::assertCount(4, $this->rows());
    }
    public function testNontransactionalSettingsRefuseBeforeTransition(): void
    {
        $this->manager()->transitionTo(\Coleza\Domain\OperationalMode\OperationalMode::SAFE, 'Original');
        $this->db->statement('ALTER TABLE system_settings ENGINE=MyISAM'); $before = $this->rows();
        try { $this->manager()->transitionTo(\Coleza\Domain\OperationalMode\OperationalMode::NORMAL); self::fail('MyISAM mode accepted'); }
        catch (\LogicException $error) { self::assertStringContainsString('InnoDB', $error->getMessage()); }
        self::assertSame($before, $this->rows());
    }
}
