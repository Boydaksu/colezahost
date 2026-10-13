<?php
declare(strict_types=1);
namespace Coleza\Tests\MariaDb;
final class SupportPersistenceTest extends \Coleza\Tests\Support\MariaDbTestCase
{
    use \Coleza\Tests\Support\SupportPersistenceCases;
    public function testFourConcurrentRoundRobinWritersUseWholeRotation(): void { $this->concurrentAssignment('supportRoundRobin'); }
    public function testFourConcurrentLeastLoadedWritersBalanceCurrentLoad(): void { $this->concurrentAssignment('supportLeastLoaded'); }
    private function concurrentAssignment(string $action): void
    {
        [$department, $ticket, $tickets] = $this->assignmentFixture();
        for ($i = 1; $i < 4; $i++) { $tickets->createTicket(['user_id' => 1, 'department_id' => $department->getId(), 'subject' => 'Parallel ' . $i, 'message' => 'Fixture']); }
        $results = $this->runParallel($action, $ticket->getId());
        foreach ($results as $result) { self::assertSame('ok', $result['status']); }
        $distribution = array_count_values(array_column($results, 'assigned_to')); ksort($distribution);
        self::assertSame([10 => 2, 20 => 2], $distribution);
        self::assertCount(4, array_unique(array_column($results, 'log_id')));
        self::assertCount(4, $this->db->select('SELECT * FROM support_ticket_assignments_log'));
    }
    public function testConcurrentManualReplayCreatesOneLog(): void
    {
        [, $ticket] = $this->assignmentFixture();
        $results = $this->runParallel('supportManualSame', $ticket->getId());
        foreach ($results as $result) { self::assertSame('ok', $result['status']); }
        self::assertCount(1, array_unique(array_column($results, 'log_id')));
        self::assertCount(1, $this->db->select('SELECT * FROM support_ticket_assignments_log'));
    }
    public function testNonTransactionalSupportEngineRefusesBeforeAssignment(): void
    {
        [, $ticket, , $assignments] = $this->assignmentFixture();
        $this->db->statement('ALTER TABLE support_ticket_assignments_log ENGINE=MyISAM');
        $before = $this->db->select('SELECT * FROM support_tickets');
        try { $assignments->assignRoundRobin($ticket->getId()); self::fail('MyISAM assignment accepted'); }
        catch (\LogicException $error) { self::assertStringContainsString('InnoDB', $error->getMessage()); }
        self::assertSame($before, $this->db->select('SELECT * FROM support_tickets'));
        self::assertSame([], $this->db->select('SELECT * FROM support_department_assignment_state'));
    }
    public function testMissingSchemaInsideRawCallerRefusesBeforeDdl(): void
    {
        $this->db->getPdo()->beginTransaction();
        try {
            $this->db->enableQueryLog(); $this->db->flushQueryLog();
            try { $this->supportService('department')->ensureTables(); self::fail('Missing schema installed in raw caller'); }
            catch (\LogicException) { self::assertTrue($this->db->getPdo()->inTransaction()); }
            foreach ($this->db->getQueryLog() as $query) { self::assertDoesNotMatchRegularExpression('/^\s*(CREATE|ALTER|DROP)\b/i', $query['query']); }
        } finally { $this->db->getPdo()->rollBack(); }
    }

    private function otherSupportConnection(): \Coleza\Foundation\Database\Connection
    {
        $pdo = new \PDO((string) getenv('COLEZA_TEST_MARIADB_DSN') . ';dbname=' . $this->database, getenv('COLEZA_TEST_MARIADB_USER'), getenv('COLEZA_TEST_MARIADB_PASSWORD'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        return new \Coleza\Foundation\Database\Connection($pdo);
    }
    public function testStaleCallerUsesCurrentRotationAndPreviousAssignee(): void
    {
        [, $ticket, , $assignments] = $this->assignmentFixture(); $assignments->assignRoundRobin($ticket->getId());
        $other = $this->otherSupportConnection();
        $departments = new \Coleza\Domain\Support\Departments\DepartmentService($other);
        $tickets = new \Coleza\Domain\Support\Tickets\TicketService($other, $departments);
        $otherAssignments = new \Coleza\Domain\Support\Assignments\TicketAssignmentService($other, $departments, $tickets);
        $this->db->getPdo()->beginTransaction();
        try {
            self::assertSame(10, (int) $this->db->selectOne('SELECT last_assigned_user_id FROM support_department_assignment_state')['last_assigned_user_id']);
            self::assertSame(20, $otherAssignments->assignRoundRobin($ticket->getId())->getNewAssignedTo());
            $log = $assignments->assignRoundRobin($ticket->getId());
            self::assertSame(10, $log->getNewAssignedTo()); self::assertSame(20, $log->getPreviousAssignedTo());
        } finally { $this->db->getPdo()->rollBack(); }
        self::assertSame(20, (int) $this->db->selectOne('SELECT assigned_to FROM support_tickets WHERE id = ?', [$ticket->getId()])['assigned_to']);
        self::assertCount(2, $assignments->getAssignmentHistory($ticket->getId()));
    }
    public function testStaleCallerLeastLoadedSelectionUsesCurrentLoads(): void
    {
        [$department, $first, $tickets, $assignments] = $this->assignmentFixture(); $ids = [$first->getId()];
        for ($i = 0; $i < 3; $i++) { $ids[] = $tickets->createTicket(['user_id' => 1, 'department_id' => $department->getId(), 'subject' => 'Load ' . $i, 'message' => 'Fixture'])->getId(); }
        $assignments->assignManual($ids[0], 10); $assignments->assignManual($ids[1], 20);
        $other = $this->otherSupportConnection(); $departments = new \Coleza\Domain\Support\Departments\DepartmentService($other);
        $otherTickets = new \Coleza\Domain\Support\Tickets\TicketService($other, $departments);
        $otherAssignments = new \Coleza\Domain\Support\Assignments\TicketAssignmentService($other, $departments, $otherTickets);
        $this->db->getPdo()->beginTransaction();
        try {
            self::assertCount(2, $this->db->select('SELECT assigned_to FROM support_tickets WHERE assigned_to IS NOT NULL'));
            $otherAssignments->assignManual($ids[2], 10);
            self::assertSame(20, $assignments->assignLeastLoaded($ids[3])->getNewAssignedTo());
        } finally { $this->db->getPdo()->rollBack(); }
        self::assertNull($tickets->requireTicket($ids[3])->getAssignedTo()); self::assertSame(10, $tickets->requireTicket($ids[2])->getAssignedTo());
    }
}
