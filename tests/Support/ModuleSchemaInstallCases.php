<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;

trait ModuleSchemaInstallCases
{
    public static function requiredModuleTables(): array
    {
        return array_map(static fn (string $table): array => [$table], [
            'support_departments', 'support_department_agents', 'support_tickets', 'support_ticket_messages',
            'support_ticket_attachments', 'support_ticket_assignments_log', 'support_department_assignment_state',
            'support_sla_policies', 'support_ticket_sla', 'support_canned_responses', 'support_announcements', 'support_context_command_logs',
            'api_keys', 'api_request_logs', 'api_rate_limits', 'sessions', 'password_resets', 'email_verifications',
            'security_audit_logs', 'user_two_factor', 'user_recovery_codes', 'user_trusted_devices', 'contracts', 'document_snapshots',
        ]);
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('requiredModuleTables')]
    public function testFreshInstallerPreparesModuleTable(string $table): void
    {
        (new \Coleza\Domain\Installer\DatabaseSetupService())->initializeApplicationSchema($this->db);
        self::assertSame([], $this->db->select("SELECT * FROM {$table}"));
    }
    public function testContractCanBePersistedForDifferentOrganizations(): void
    {
        $service = new \Coleza\Domain\Documents\Contracts\ContractService($this->db->getPdo());
        $one = $service->createContract(7, 'Hizmet sözleşmesi', 'v1', 'Şartlar', organizationId: 7);
        $two = $service->createContract(8, 'Diğer sözleşme', 'v1', 'Diğer şartlar', organizationId: 8);
        self::assertNotSame($one->getContractNumber(), $two->getContractNumber());
        self::assertSame('Hizmet sözleşmesi', $service->find($one->getId())->getTitle());
    }
    public function testSupportTicketAndMessageSurviveRepeatedInstallation(): void
    {
        $setup = new \Coleza\Domain\Installer\DatabaseSetupService();
        $setup->initializeApplicationSchema($this->db);
        $departments = new \Coleza\Domain\Support\Departments\DepartmentService($this->db);
        $department = $departments->createDepartment(['name' => 'Teknik destek']);
        $tickets = new \Coleza\Domain\Support\Tickets\TicketService($this->db, $departments);
        $ticket = $tickets->createTicket(['user_id' => 7, 'department_id' => $department->getId(), 'subject' => 'Bağlantı sorunu', 'message' => 'Türkçe açıklama']);
        $before = $this->db->select('SELECT * FROM support_tickets');
        $messages = $this->db->select('SELECT * FROM support_ticket_messages');
        $setup->initializeApplicationSchema($this->db);
        self::assertSame($before, $this->db->select('SELECT * FROM support_tickets'));
        self::assertSame($messages, $this->db->select('SELECT * FROM support_ticket_messages'));
        self::assertSame('Bağlantı sorunu', $tickets->requireTicket($ticket->getId())->getSubject());
    }
    public function testLegacyTicketLinksAreAddedWithoutLosingMessages(): void
    {
        $departments = new \Coleza\Domain\Support\Departments\DepartmentService($this->db);
        $departments->ensureTables();
        $department = $departments->createDepartment(['name' => 'Legacy support']);
        $tickets = new \Coleza\Domain\Support\Tickets\TicketService($this->db, $departments);
        $tickets->ensureTables();
        $tickets->createTicket(['user_id' => 7, 'department_id' => $department->getId(), 'subject' => 'Old ticket', 'message' => 'Keep this message']);
        $this->db->statement('ALTER TABLE support_tickets DROP COLUMN invoice_id');
        $this->db->statement('ALTER TABLE support_tickets DROP COLUMN order_id');
        $before = $this->db->select('SELECT * FROM support_tickets');
        $messages = $this->db->select('SELECT * FROM support_ticket_messages');
        (new \Coleza\Domain\Installer\DatabaseSetupService())->initializeApplicationSchema($this->db);
        $after = $this->db->select('SELECT * FROM support_tickets');
        self::assertSame($before[0], array_intersect_key($after[0], $before[0]));
        self::assertNull($after[0]['invoice_id']);
        self::assertNull($after[0]['order_id']);
        self::assertSame($messages, $this->db->select('SELECT * FROM support_ticket_messages'));
    }
    public function testApiKeyScopeAndRateCounterUseInstalledSchemas(): void
    {
        $setup = new \Coleza\Domain\Installer\DatabaseSetupService();
        $setup->initializeApplicationSchema($this->db);
        $keys = new \Coleza\Api\Auth\ApiKeyService($this->db);
        $key = $keys->createKey(7, 'Migration key', ['tickets.view']);
        self::assertSame(7, $keys->authenticate($key['plain_key'], 'tickets.view')['user_id']);
        $caught = null;
        try { $keys->authenticate($key['plain_key'], 'payments.manage'); }
        catch (\Coleza\Foundation\Exceptions\ValidationException $error) { $caught = $error; }
        self::assertNotNull($caught);
        $before = $this->db->select('SELECT * FROM api_keys');
        $setup->initializeApplicationSchema($this->db);
        self::assertSame($before, $this->db->select('SELECT * FROM api_keys'));
        $rate = new \Coleza\Api\RateLimit\RateLimiter($this->db);
        self::assertSame(1, $rate->hit('fixture-key', 2)['remaining']);
        self::assertSame(0, $rate->hit('fixture-key', 2)['remaining']);
    }
    public function testImportedContractNumberUpperBoundIsPreserved(): void
    {
        $service = new \Coleza\Domain\Documents\Contracts\ContractService($this->db->getPdo());
        $old = $service->createContract(7, 'Old terms', 'v1', 'Preserved', organizationId: 7);
        $year = (int) date('Y');
        $this->db->statement('UPDATE contracts SET contract_number = ? WHERE id = ?', ["CTR-{$year}-001500", $old->getId()]);
        $before = $this->db->select('SELECT * FROM contracts');
        (new \Coleza\Domain\Installer\DatabaseSetupService())->initializeApplicationSchema($this->db);
        self::assertSame($before, $this->db->select('SELECT * FROM contracts'));
        self::assertSame("CTR-{$year}-001501", $service->createContract(8, 'New terms', 'v1', 'New terms', organizationId: 8)->getContractNumber());
    }
    public function testSnapshotPayloadAndChecksumPersistInActualDatabase(): void
    {
        (new \Coleza\Domain\Installer\DatabaseSetupService())->initializeApplicationSchema($this->db);
        $storage = $this->createMock(\Coleza\Domain\Documents\Storage\DocumentStorageInterface::class);
        $storage->method('resolveStoragePath')->willReturn('fixture/document.pdf');
        $storage->expects(self::once())->method('putDocument')->with('fixture/document.pdf', 'fixture-pdf-bytes')->willReturn(true);
        $snapshots = new \Coleza\Domain\Documents\Snapshots\DocumentSnapshotService($this->db->getPdo(), $storage);
        $snapshot = $snapshots->createSnapshot(\Coleza\Domain\Documents\DocumentType::CONTRACT, 'CTR-2026-000001', '7', ['title' => 'Türkçe içerik'], '<p>İçerik</p>', 'fixture-pdf-bytes');
        $loaded = $snapshots->getLatestSnapshot(\Coleza\Domain\Documents\DocumentType::CONTRACT, 'CTR-2026-000001');
        self::assertSame($snapshot->getId(), $loaded->getId());
        self::assertSame(['title' => 'Türkçe içerik'], $loaded->getSnapshotPayload());
        self::assertSame(hash('sha256', 'fixture-pdf-bytes'), $loaded->getContentSha256());
    }
}
