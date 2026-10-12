<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class AutomationActionJournalTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\AutomationActionJournalCases;
    protected function setUp(): void
    {
        parent::setUp();
        $this->root->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
    }
    public function testAttemptStartHasOneWinnerAcrossFourWorkers(): void
    {
        $engine = $this->journalEngine();
        $ids = $engine->getActionJournal()->prepare('r', [new \Coleza\Domain\Automation\Actions\ActionDefinition('a0', 'send', ['token' => '{{ token }}'])],
            new \Coleza\Domain\Automation\Triggers\TriggerContext('event', ['ok' => true, 'token' => 'SECRET_NOT_FOR_JOURNAL'], correlationId: 'parallel'));
        $results = $this->runParallel('automationAttemptStart', 0, $ids[0]);
        self::assertSame(1, array_sum(array_column($results, 'claimed')));
        self::assertCount(1, $this->journalRows());
        self::assertSame('DISPATCHING', $this->journalRows()[0]['status']);
    }
    public function testOtherNativeConnectionSeesCommittedStartDuringHandler(): void
    {
        $pdo = new \PDO((string) getenv('COLEZA_TEST_MARIADB_DSN'), getenv('COLEZA_TEST_MARIADB_USER'), getenv('COLEZA_TEST_MARIADB_PASSWORD'),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false]);
        $pdo->exec('USE `' . $this->database . '`');
        $observed = [];
        $engine = $this->journalEngine(function () use ($pdo, &$observed): array {
            $observed = $pdo->query('SELECT status FROM automation_action_attempts ORDER BY action_position')->fetchAll(\PDO::FETCH_COLUMN);
            self::assertFalse($this->db->getPdo()->inTransaction());
            return [];
        });
        $result = $engine->executeRule($this->journalRule(), $this->journalContext());
        self::assertTrue($result->isSuccessful());
        self::assertSame(['DISPATCHING'], $observed);
        self::assertSame('REPORTED_SUCCESS', $this->journalRows()[0]['status']);
    }
}
