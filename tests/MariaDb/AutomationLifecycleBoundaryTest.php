<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class AutomationLifecycleBoundaryTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\AutomationLifecycleBoundaryCases;
    protected function setUp(): void { parent::setUp(); $this->root->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false); }
    public function testCommittedPlacementCannotBeHiddenByAnOldCallerSnapshot(): void
    {
        [$service, $record, $handler] = $this->lifecycleFixture('suspend', false);
        $pdo = new \PDO((string) getenv('COLEZA_TEST_MARIADB_DSN'), getenv('COLEZA_TEST_MARIADB_USER'), getenv('COLEZA_TEST_MARIADB_PASSWORD'),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false]);
        $pdo->exec('USE `' . $this->database . '`');
        $other = new \Coleza\Foundation\Database\Connection($pdo);
        $this->db->beginTransaction();
        try {
            $this->db->select('SELECT * FROM service_placements');
            (new \Coleza\Domain\Commerce\Services\ServiceService($other))->assignPlacement($record->getId(), ['server_id' => 7]);
            $result = $handler->execute(new \Coleza\Domain\Automation\Actions\ActionDefinition('a', 'service.suspend', ['service_id' => $record->getId()]),
                new \Coleza\Domain\Automation\Triggers\TriggerContext('event'));
            self::assertTrue($result->isFailed());
            self::assertSame('PROVIDER_LIFECYCLE_REQUIRED', $result->getOutput()['error_code']);
        } finally { $this->db->rollBack(); }
        self::assertSame('active', $service->findServiceById($record->getId())->getStatus());
    }
    public function testSameVersionLocalLifecycleHasOneWinnerAcrossFourWorkers(): void
    {
        [$service, $record] = $this->lifecycleFixture('suspend', false);
        $results = $this->runParallel('automationLocalLifecycle', $record->getId());
        self::assertSame(1, array_sum(array_column($results, 'changed')));
        self::assertSame('suspended', $service->findServiceById($record->getId())->getStatus());
        self::assertSame($record->getLockVersion() + 1, $service->findServiceById($record->getId())->getLockVersion());
    }
}
