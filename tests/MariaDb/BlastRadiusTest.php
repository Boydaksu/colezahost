<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class BlastRadiusTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\BlastRadiusCases;
    protected function setUp(): void
    {
        parent::setUp();
        $this->root->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
    }
    public function testParallelEnginesCannotExceedRemainingGlobalPermit(): void
    {
        $this->parallelAdmission(false);
    }
    public function testParallelUncertainEffectsStillUseOneRemainingPermit(): void
    {
        $this->parallelAdmission(true);
    }
    private function parallelAdmission(bool $uncertain): void
    {
        $this->installBlastRadius();
        for ($i = 0; $i < 4; $i++) { self::assertTrue($this->limiter()->tryAcquire('server.destroy')); }
        $this->db->statement('CREATE TABLE destructive_effect_fixture (worker_id INT PRIMARY KEY)');
        $results = $this->runParallel('automationBlast', $uncertain ? 1 : 0);
        self::assertSame(1, array_sum(array_column($results, 'calls')));
        self::assertCount(1, $this->db->select('SELECT * FROM destructive_effect_fixture'));
        self::assertSame(5, $this->limiter()->getRecentDestructiveCount());
        self::assertSame($uncertain ? 0 : 1, array_sum(array_column($results, 'successful')));
        self::assertTrue($this->limiter()->getEmergencyPauseManager()->isPaused('service.terminate'));
    }
    public function testIndependentConnectionSeesPermitBeforeHandlerAndAfterRestart(): void
    {
        $this->installBlastRadius();
        $pdo = new \PDO((string) getenv('COLEZA_TEST_MARIADB_DSN'), getenv('COLEZA_TEST_MARIADB_USER'), getenv('COLEZA_TEST_MARIADB_PASSWORD'),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false]);
        $pdo->exec('USE `' . $this->database . '`');
        $other = new \Coleza\Foundation\Database\Connection($pdo);
        $count = null;
        $registry = new \Coleza\Domain\Automation\Actions\ActionRegistry();
        $registry->register(new \Coleza\Domain\Automation\Actions\Handlers\CallbackActionHandler('service.terminate', function () use ($other, &$count) {
            $count = (new \Coleza\Domain\Automation\Safety\DatabaseBlastRadiusLimiter($other))->getRecentDestructiveCount();
            return [];
        }));
        self::assertTrue(\Coleza\Domain\Automation\Engine\DatabaseAutomationFactory::create($this->db, $registry)->executeRule(
            $this->destructiveRule(), new \Coleza\Domain\Automation\Triggers\TriggerContext('event', ['ok' => true]))->isSuccessful());
        self::assertSame(1, $count);
        self::assertSame(1, (new \Coleza\Domain\Automation\Safety\DatabaseBlastRadiusLimiter($other))->getRecentDestructiveCount());
    }
}
