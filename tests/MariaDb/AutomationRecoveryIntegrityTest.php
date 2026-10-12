<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class AutomationRecoveryIntegrityTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\AutomationRecoveryIntegrityCases;
    protected function setUp(): void
    {
        parent::setUp();
        $this->root->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
    }
    public function testValidLinkedChildHasOneClaimAcrossFourWorkers(): void
    {
        [$engine, , $child] = $this->linkedDelay();
        $this->changePayload('automation_pending', $child, static function (&$d) {
            $d['execute_at'] = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d\TH:i:s.uP');
            $d['scheduled_at'] = (new \DateTimeImmutable($d['execute_at']))->modify('-600 seconds')->format('Y-m-d\TH:i:s.uP');
        });
        $results = $this->runParallel('automationClaim', 0, $child);
        self::assertSame(1, array_sum(array_column($results, 'claimed')));
        self::assertSame('DISPATCHING', $engine->getDelayManager()->findById($child)->getStatus()->value);
        self::assertCount(1, (new \Coleza\Domain\Automation\Execution\AutomationRecoveryInspector($this->db))->listUnresolved());
    }
    public function testReverseParentLookupUsesCommittedStateAfterOldSnapshot(): void
    {
        $engine = $this->recoveryEngine();
        $pending = $engine->getApprovalManager()->createRequest('r', 'Rule', new \Coleza\Domain\Automation\Triggers\TriggerContext('event'),
            [new \Coleza\Domain\Automation\Actions\ActionDefinition('a', 'send')], new \Coleza\Domain\Automation\Approval\ApprovalRequirement(), ['delay_seconds' => 600]);
        $pdo = new \PDO((string) getenv('COLEZA_TEST_MARIADB_DSN'), getenv('COLEZA_TEST_MARIADB_USER'), getenv('COLEZA_TEST_MARIADB_PASSWORD'),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false]);
        $pdo->exec('USE `' . $this->database . '`');
        $other = new \Coleza\Foundation\Database\Connection($pdo);
        $this->db->beginTransaction();
        try {
            $this->db->select('SELECT * FROM automation_pending'); // Snapshot predates the approved parent and child.
            $fresh = \Coleza\Domain\Automation\Engine\DatabaseAutomationFactory::create($other, new \Coleza\Domain\Automation\Actions\ActionRegistry());
            $child = $fresh->executeApprovedRequest($pending->getId(), 'actor')->getDelayId();
            $data = \Coleza\Domain\Automation\Persistence\AutomationCodec::decode($other->selectOne('SELECT payload FROM automation_pending WHERE id = ?', [$child])['payload']);
            unset($data['execution_snapshot']['approval_id']);
            $data['execute_at'] = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d\TH:i:s.uP');
            $data['scheduled_at'] = (new \DateTimeImmutable($data['execute_at']))->modify('-600 seconds')->format('Y-m-d\TH:i:s.uP');
            $other->update('automation_pending', ['payload' => \Coleza\Domain\Automation\Persistence\AutomationCodec::encode($data)], 'id = :id', ['id' => $child]);
            try { $engine->getDelayManager()->claimForDispatch($child); self::fail('Stale parent snapshot bypassed reverse-link validation'); }
            catch (\LogicException) { self::assertSame('SCHEDULED', $engine->getDelayManager()->findById($child)->getStatus()->value); }
        } finally { $this->db->rollBack(); }
        self::assertSame('APPROVED', $fresh->getApprovalManager()->findById($pending->getId())->getStatus()->value);
        self::assertSame('SCHEDULED', $fresh->getDelayManager()->findById($child)->getStatus()->value);
    }
}
