<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class AutomationPersistenceTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\AutomationPersistenceCases;
    protected function setUp(): void
    {
        parent::setUp();
        $this->root->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
    }
    public function testParallelWorkersClaimDelayedExecutionOnlyOnce(): void
    {
        $this->install();
        $entry = $this->dueDelay();
        $results = $this->runParallel('automationClaim', 0, $entry->getId());
        self::assertSame(1, array_sum(array_column($results, 'claimed')));
        self::assertSame('DISPATCHING', $this->engine()->getDelayManager()->findById($entry->getId())->getStatus()->value);
    }
    public function testParallelApprovalDecisionsHaveOneWinner(): void
    {
        $this->install();
        $entry = $this->engine()->getApprovalManager()->createRequest('r', 'Ask', new \Coleza\Domain\Automation\Triggers\TriggerContext('event'), [], new \Coleza\Domain\Automation\Approval\ApprovalRequirement());
        $results = $this->runParallel('automationApprove', 0, $entry->getId());
        self::assertCount(1, array_filter($results, static fn(array $r) => $r['status'] === 'ok'));
        self::assertSame('APPROVED', $this->engine()->getApprovalManager()->findById($entry->getId())->getStatus()->value);
    }
    public function testNewDatabaseConnectionReadsCommittedState(): void
    {
        $this->install();
        $entry = $this->dueDelay();
        $this->engine()->getEmergencyPauseManager()->pause('actor', 'restart');
        $pdo = new \PDO((string) getenv('COLEZA_TEST_MARIADB_DSN'), getenv('COLEZA_TEST_MARIADB_USER'), getenv('COLEZA_TEST_MARIADB_PASSWORD'),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false]);
        $pdo->exec('USE `' . $this->database . '`');
        $fresh = \Coleza\Domain\Automation\Engine\DatabaseAutomationFactory::create(new \Coleza\Foundation\Database\Connection($pdo), new \Coleza\Domain\Automation\Actions\ActionRegistry());
        self::assertSame($entry->toArray(), $fresh->getDelayManager()->findById($entry->getId())->toArray());
        self::assertTrue($fresh->getEmergencyPauseManager()->isPaused());
    }
    public function testExistingSnapshotCannotOverwriteAnotherWorkersDecisionOrClaim(): void
    {
        $this->install();
        $delay = $this->dueDelay();
        $approval = $this->engine()->getApprovalManager()->createRequest('r', 'Ask', new \Coleza\Domain\Automation\Triggers\TriggerContext('event'), [], new \Coleza\Domain\Automation\Approval\ApprovalRequirement());
        $pdo = new \PDO((string) getenv('COLEZA_TEST_MARIADB_DSN'), getenv('COLEZA_TEST_MARIADB_USER'), getenv('COLEZA_TEST_MARIADB_PASSWORD'),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false]);
        $pdo->exec('USE `' . $this->database . '`');
        $other = new \Coleza\Foundation\Database\Connection($pdo);
        $this->db->beginTransaction();
        try {
            $this->db->select('SELECT * FROM automation_pending'); // Establish repeatable-read snapshot before the other worker commits.
            self::assertTrue((new \Coleza\Domain\Automation\Delay\DatabaseDelayManager($other))->claimForDispatch($delay->getId()));
            (new \Coleza\Domain\Automation\Approval\DatabaseApprovalManager($other))->approve($approval->getId(), 'winner');
            self::assertFalse((new \Coleza\Domain\Automation\Delay\DatabaseDelayManager($this->db))->claimForDispatch($delay->getId()));
            try {
                (new \Coleza\Domain\Automation\Approval\DatabaseApprovalManager($this->db))->approve($approval->getId(), 'stale');
                self::fail('Stale snapshot replaced the committed decision');
            } catch (\InvalidArgumentException) {
                self::assertSame('winner', (new \Coleza\Domain\Automation\Approval\DatabaseApprovalManager($this->db))->findById($approval->getId())->getDecidedBy());
            }
        } finally { $this->db->rollBack(); }
    }
}
