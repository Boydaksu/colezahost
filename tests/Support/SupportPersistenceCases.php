<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
use Coleza\Domain\Support\Departments\DepartmentService;
use Coleza\Domain\Support\Tickets\TicketService;
use Coleza\Domain\Support\Assignments\TicketAssignmentService;
use PHPUnit\Framework\Attributes\DataProvider;
use PDOException;

trait SupportPersistenceCases
{
    private function supportFixture(): array
    {
        (new \Coleza\Foundation\Database\Migrator($this->db))->migrate(dirname(__DIR__, 2) . '/database/migrations');
        $departments = new DepartmentService($this->db); $tickets = new TicketService($this->db, $departments);
        return [$departments, $tickets, new TicketAssignmentService($this->db, $departments, $tickets)];
    }
    public static function supportServices(): array
    {
        return array_map(static fn($name) => [$name], ['department', 'ticket', 'sla', 'assignment', 'canned', 'announcement', 'attachment', 'context']);
    }
    private function supportService(string $name): object
    {
        $departments = new DepartmentService($this->db); $tickets = new TicketService($this->db, $departments);
        return match ($name) {
            'department' => $departments, 'ticket' => $tickets,
            'sla' => new \Coleza\Domain\Support\Sla\SlaService($this->db, $tickets),
            'assignment' => new TicketAssignmentService($this->db, $departments, $tickets),
            'canned' => new \Coleza\Domain\Support\CannedResponses\CannedResponseService($this->db, $departments),
            'announcement' => new \Coleza\Domain\Support\Announcements\AnnouncementService($this->db),
            'attachment' => new \Coleza\Domain\Support\Attachments\AttachmentService($this->db, new \Coleza\Domain\Support\Attachments\InMemoryAttachmentStorage()),
            'context' => new \Coleza\Domain\Support\Context\TicketContextService($this->db, $tickets, new \Coleza\Domain\Support\Context\InMemoryTicketContextProvider()),
        };
    }
    #[DataProvider('supportServices')]
    public function testInstalledSupportSchemaDoesNotExecuteDdlOrCommitCaller(string $name): void
    {
        $this->supportFixture(); $this->db->statement('CREATE TABLE support_caller_marker (id INT PRIMARY KEY)');
        $this->db->getPdo()->beginTransaction();
        try {
            $this->db->insert('support_caller_marker', ['id' => 1]);
            $this->db->enableQueryLog(); $this->db->flushQueryLog();
            $this->supportService($name)->ensureTables();
            self::assertTrue($this->db->getPdo()->inTransaction());
            foreach ($this->db->getQueryLog() as $query) { self::assertDoesNotMatchRegularExpression('/^\s*(CREATE|ALTER|DROP|TRUNCATE)\b/i', $query['query']); }
        } finally { if ($this->db->getPdo()->inTransaction()) { $this->db->getPdo()->rollBack(); } }
        self::assertSame([], $this->db->select('SELECT * FROM support_caller_marker'));
    }
    public static function assignmentStrategies(): array { return [['manual'], ['unassign'], ['round_robin'], ['least_loaded']]; }
    private function assignmentFixture(): array
    {
        [$departments, $tickets, $assignments] = $this->supportFixture();
        $department = $departments->createDepartment(['name' => 'Support']);
        $departments->assignAgent($department->getId(), 10); $departments->assignAgent($department->getId(), 20);
        $ticket = $tickets->createTicket(['user_id' => 1, 'department_id' => $department->getId(), 'subject' => 'Fixture', 'message' => 'Fixture']);
        return [$department, $ticket, $tickets, $assignments];
    }
    private function assignmentOperation(TicketAssignmentService $service, string $strategy, int $ticketId): mixed
    {
        return match ($strategy) {
            'manual' => $service->assignManual($ticketId, 10, 99), 'unassign' => $service->unassign($ticketId, 99),
            'round_robin' => $service->assignRoundRobin($ticketId, 99), 'least_loaded' => $service->assignLeastLoaded($ticketId, 99),
        };
    }
    #[DataProvider('assignmentStrategies')]
    public function testAssignmentLogFailureRestoresTicketAndRotationState(string $strategy): void
    {
        [, $ticket, $tickets, $assignments] = $this->assignmentFixture();
        if ($strategy === 'unassign') { $assignments->assignManual($ticket->getId(), 10); }
        $before = [$this->db->select('SELECT * FROM support_tickets'), $this->db->select('SELECT * FROM support_department_assignment_state'), $this->db->select('SELECT * FROM support_ticket_assignments_log')];
        $this->db->statement($this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER support_fail_log BEFORE INSERT ON support_ticket_assignments_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture log failure'"
            : "CREATE TRIGGER support_fail_log BEFORE INSERT ON support_ticket_assignments_log BEGIN SELECT RAISE(ABORT, 'fixture log failure'); END");
        try { $this->assignmentOperation($assignments, $strategy, $ticket->getId()); self::fail('Log failure ignored'); }
        catch (PDOException) {
            self::assertSame($before, [$this->db->select('SELECT * FROM support_tickets'), $this->db->select('SELECT * FROM support_department_assignment_state'), $this->db->select('SELECT * FROM support_ticket_assignments_log')]);
        }
    }
    public function testTicketCreationJoinsRawCallerAndRollsBack(): void
    {
        [$departments, $tickets] = $this->supportFixture(); $department = $departments->createDepartment(['name' => 'Support']);
        $this->db->getPdo()->beginTransaction();
        try {
            $tickets->createTicket(['user_id' => 1, 'department_id' => $department->getId(), 'subject' => 'Fixture', 'message' => 'Fixture']);
            self::assertTrue($this->db->getPdo()->inTransaction());
        } finally { if ($this->db->getPdo()->inTransaction()) { $this->db->getPdo()->rollBack(); } }
        self::assertSame([], $this->db->select('SELECT * FROM support_tickets')); self::assertSame([], $this->db->select('SELECT * FROM support_ticket_messages'));
    }

    public function testRawCallerAssignmentFailureKeepsEarlierWork(): void { $this->callerAssignmentFailure(false); }
    public function testManagedCallerAssignmentFailureKeepsEarlierWork(): void { $this->callerAssignmentFailure(true); }
    private function callerAssignmentFailure(bool $managed): void
    {
        [, $ticket, , $assignments] = $this->assignmentFixture();
        $this->db->statement('CREATE TABLE support_caller_marker (id INT PRIMARY KEY)');
        $this->db->statement($this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER support_fail_log BEFORE INSERT ON support_ticket_assignments_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture log failure'"
            : "CREATE TRIGGER support_fail_log BEFORE INSERT ON support_ticket_assignments_log BEGIN SELECT RAISE(ABORT, 'fixture log failure'); END");
        $managed ? $this->db->beginTransaction() : $this->db->getPdo()->beginTransaction();
        try {
            $this->db->insert('support_caller_marker', ['id' => 1]);
            try { $assignments->assignRoundRobin($ticket->getId()); self::fail('Log failure ignored'); }
            catch (PDOException) {
                self::assertTrue($this->db->getPdo()->inTransaction()); self::assertCount(1, $this->db->select('SELECT * FROM support_caller_marker'));
                self::assertNull($this->db->selectOne('SELECT assigned_to FROM support_tickets WHERE id = ?', [$ticket->getId()])['assigned_to']);
                self::assertSame([], $this->db->select('SELECT * FROM support_department_assignment_state'));
            }
        } finally { $managed ? $this->db->rollBack() : $this->db->getPdo()->rollBack(); }
        self::assertSame([], $this->db->select('SELECT * FROM support_caller_marker'));
    }
    public function testRepliesAndNotesJoinRawCallerRollback(): void
    {
        [, $ticket, $tickets] = $this->assignmentFixture();
        $before = [$this->db->select('SELECT * FROM support_tickets'), $this->db->select('SELECT * FROM support_ticket_messages')];
        $this->db->getPdo()->beginTransaction();
        try {
            $tickets->addReply($ticket->getId(), 10, 'Public reply', isStaff: true);
            $tickets->addInternalNote($ticket->getId(), 10, 'Private note');
            self::assertTrue($this->db->getPdo()->inTransaction()); self::assertCount(3, $this->db->select('SELECT * FROM support_ticket_messages'));
        } finally { $this->db->getPdo()->rollBack(); }
        self::assertSame($before, [$this->db->select('SELECT * FROM support_tickets'), $this->db->select('SELECT * FROM support_ticket_messages')]);
    }
    public function testPartialSupportSchemaRefusesBeforeCreatingOrAlteringAnything(): void
    {
        [$departments] = $this->supportFixture();
        $this->db->statement($this->db->getDriverName() === 'mysql'
            ? 'ALTER TABLE support_department_agents CHANGE metadata_json fixture_saved_metadata TEXT NULL'
            : 'ALTER TABLE support_department_agents RENAME COLUMN metadata_json TO fixture_saved_metadata');
        $before = $this->db->select('SELECT * FROM support_departments');
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        try { $departments->ensureTables(); self::fail('Partial schema accepted'); }
        catch (\LogicException) {
            foreach ($this->db->getQueryLog() as $query) { self::assertDoesNotMatchRegularExpression('/^\s*(CREATE|ALTER|DROP)\b/i', $query['query']); }
            self::assertSame($before, $this->db->select('SELECT * FROM support_departments'));
        }
    }
    public function testLegacyNullableTicketLinksAreAddedWithoutChangingConversation(): void
    {
        [, $ticket, $tickets] = $this->assignmentFixture();
        $before = $this->db->select('SELECT * FROM support_ticket_messages');
        $this->db->statement('ALTER TABLE support_tickets DROP COLUMN invoice_id'); $this->db->statement('ALTER TABLE support_tickets DROP COLUMN order_id');
        $tickets->ensureTables();
        self::assertNull($tickets->requireTicket($ticket->getId())->getInvoiceId());
        self::assertNull($tickets->requireTicket($ticket->getId())->getOrderId());
        self::assertSame($before, $this->db->select('SELECT * FROM support_ticket_messages'));
        $this->db->enableQueryLog(); $this->db->flushQueryLog(); $tickets->ensureTables();
        foreach ($this->db->getQueryLog() as $query) { self::assertDoesNotMatchRegularExpression('/^\s*(CREATE|ALTER|DROP)\b/i', $query['query']); }
    }
    public function testUnknownStrategyRefusesAndInactiveDepartmentCannotBeAssigned(): void
    {
        [$department, $ticket, , $assignments] = $this->assignmentFixture();
        try { $assignments->autoAssign($ticket->getId(), 'unknown'); self::fail('Unknown strategy guessed'); }
        catch (\Coleza\Foundation\Exceptions\ValidationException) { self::assertSame([], $assignments->getAssignmentHistory($ticket->getId())); }
        $this->db->statement('UPDATE support_departments SET is_active = 0 WHERE id = ?', [$department->getId()]);
        try { $assignments->assignRoundRobin($ticket->getId()); self::fail('Inactive department assigned'); }
        catch (\Coleza\Foundation\Exceptions\ValidationException) { self::assertSame([], $assignments->getAssignmentHistory($ticket->getId())); }
    }
    public function testMissingCommandLogSchemaStopsBeforeHandlerEffect(): void
    {
        [, $ticket, $tickets] = $this->assignmentFixture(); $tickets->linkResource($ticket->getId(), 'service', 123);
        $this->db->statement('ALTER TABLE support_context_command_logs RENAME TO fixture_saved_command_logs');
        $handler = new class implements \Coleza\Domain\Support\Context\TicketContextCommandHandlerInterface {
            public int $calls = 0;
            public function supports(string $action): bool { return true; }
            public function handle(string $action, int $ticketId, array $parameters = []): \Coleza\Domain\Support\Context\CommandExecutionResult {
                $this->calls++; return \Coleza\Domain\Support\Context\CommandExecutionResult::successful($action, 'Fixture');
            }
        };
        $service = new \Coleza\Domain\Support\Context\TicketContextService($this->db, $tickets, new \Coleza\Domain\Support\Context\InMemoryTicketContextProvider());
        $service->registerCommandHandler($handler);
        try { $service->executeCommand($ticket->getId(), 99, 'service_suspend'); self::fail('Missing log schema ignored'); }
        catch (\LogicException|PDOException) { self::assertSame(0, $handler->calls); }
    }
    public function testMissingAttachmentSchemaStopsBeforeStorageEffect(): void
    {
        $this->supportFixture(); $this->db->statement('ALTER TABLE support_ticket_attachments RENAME TO fixture_saved_attachments');
        $storage = new class implements \Coleza\Domain\Support\Attachments\AttachmentStorageInterface {
            public int $puts = 0;
            public function put(string $key, string $content): bool { $this->puts++; return true; }
            public function get(string $key): ?string { return null; }
            public function exists(string $key): bool { return false; }
            public function delete(string $key): bool { return true; }
        };
        $service = new \Coleza\Domain\Support\Attachments\AttachmentService($this->db, $storage);
        try { $service->attachFile(1, 1, 'fixture.txt', 'Fixture'); self::fail('Missing attachment schema ignored'); }
        catch (\LogicException|PDOException) { self::assertSame(0, $storage->puts); }
    }

    public function testReplyUpdateFailureRestoresMessageAndRawCallerWork(): void
    {
        [, $ticket, $tickets] = $this->assignmentFixture();
        $before = [$this->db->select('SELECT * FROM support_tickets'), $this->db->select('SELECT * FROM support_ticket_messages')];
        $this->db->statement('CREATE TABLE support_caller_marker (id INT PRIMARY KEY)');
        $this->db->statement($this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER support_fail_ticket BEFORE UPDATE ON support_tickets FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture ticket update failure'"
            : "CREATE TRIGGER support_fail_ticket BEFORE UPDATE ON support_tickets BEGIN SELECT RAISE(ABORT, 'fixture ticket update failure'); END");
        $this->db->getPdo()->beginTransaction();
        try {
            $this->db->insert('support_caller_marker', ['id' => 1]);
            try { $tickets->addReply($ticket->getId(), 10, 'Reply', isStaff: true); self::fail('Ticket update failure ignored'); }
            catch (PDOException) {
                self::assertTrue($this->db->getPdo()->inTransaction()); self::assertCount(1, $this->db->select('SELECT * FROM support_caller_marker'));
                self::assertSame($before, [$this->db->select('SELECT * FROM support_tickets'), $this->db->select('SELECT * FROM support_ticket_messages')]);
            }
        } finally { $this->db->getPdo()->rollBack(); }
    }
    public function testInitialMessageFailureRestoresNewTicketAndManagedCallerWork(): void
    {
        [$departments, $tickets] = $this->supportFixture(); $department = $departments->createDepartment(['name' => 'Support']);
        $this->db->statement('CREATE TABLE support_caller_marker (id INT PRIMARY KEY)');
        $this->db->statement($this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER support_fail_message BEFORE INSERT ON support_ticket_messages FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture message failure'"
            : "CREATE TRIGGER support_fail_message BEFORE INSERT ON support_ticket_messages BEGIN SELECT RAISE(ABORT, 'fixture message failure'); END");
        $this->db->beginTransaction();
        try {
            $this->db->insert('support_caller_marker', ['id' => 1]);
            try { $tickets->createTicket(['user_id' => 1, 'department_id' => $department->getId(), 'subject' => 'Fixture', 'message' => 'Fixture']); self::fail('Initial message failure ignored'); }
            catch (PDOException) {
                self::assertTrue($this->db->getPdo()->inTransaction()); self::assertCount(1, $this->db->select('SELECT * FROM support_caller_marker'));
                self::assertSame([], $this->db->select('SELECT * FROM support_tickets')); self::assertSame([], $this->db->select('SELECT * FROM support_ticket_messages'));
            }
        } finally { $this->db->rollBack(); }
    }
}
