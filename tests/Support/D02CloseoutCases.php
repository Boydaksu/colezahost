<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
use Coleza\Domain\Commerce\Invoices\InvoiceService;
use PHPUnit\Framework\Attributes\DataProvider;

trait D02CloseoutCases
{
    public static function callers(): array { return [['standalone'], ['raw'], ['managed']]; }
    private function invoiceStorage(): InvoiceService
    {
        $service = new InvoiceService($this->db); $service->ensureTables();
        $this->db->statement('CREATE TABLE closeout_marker (id INT PRIMARY KEY)'); return $service;
    }
    private function invoiceRows(): array
    {
        return [$this->db->select('SELECT * FROM invoices'), $this->db->select('SELECT * FROM invoice_items'), $this->db->select('SELECT * FROM invoice_sequences')];
    }
    private function startCaller(string $caller): void
    {
        if ($caller === 'raw') { $this->db->getPdo()->beginTransaction(); }
        if ($caller === 'managed') { $this->db->beginTransaction(); }
        if ($caller !== 'standalone') { $this->db->insert('closeout_marker', ['id' => 1]); }
    }
    private function endCaller(string $caller, bool $commit): void
    {
        if (!$this->db->getPdo()->inTransaction()) { return; }
        if ($caller === 'managed') { $commit ? $this->db->commit() : $this->db->rollBack(); }
        else { $commit ? $this->db->getPdo()->commit() : $this->db->getPdo()->rollBack(); }
    }
    #[DataProvider('callers')]
    public function testFailedSecondInvoiceItemRollsBackWholeOperation(string $caller): void
    {
        $service = $this->invoiceStorage(); $before = $this->invoiceRows();
        $this->db->statement($this->db->getDriverName() === 'mysql'
            ? "CREATE TRIGGER closeout_fail_item BEFORE INSERT ON invoice_items FOR EACH ROW BEGIN IF NEW.description = 'fail' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'second item failure'; END IF; END"
            : "CREATE TRIGGER closeout_fail_item BEFORE INSERT ON invoice_items WHEN NEW.description = 'fail' BEGIN SELECT RAISE(ABORT, 'second item failure'); END");
        $this->startCaller($caller);
        try {
            try { $service->createInvoice(['user_id' => 7, 'currency_code' => 'TRY'], [['description' => 'first', 'unit_amount_minor' => 1000], ['description' => 'fail', 'unit_amount_minor' => 2000]]); self::fail('Second item failure ignored'); }
            catch (\PDOException) {
                self::assertSame($before, $this->invoiceRows()); self::assertSame($caller !== 'standalone', $this->db->getPdo()->inTransaction());
                if ($caller !== 'standalone') { self::assertSame(1, (int) $this->db->selectOne('SELECT id FROM closeout_marker')['id']); }
            }
            $this->endCaller($caller, true);
        } finally { $this->endCaller($caller, false); }
        self::assertSame($before, $this->invoiceRows());
    }
    #[DataProvider('callers')]
    public function testInvalidItemJsonLeavesNoInvoiceOrConsumedNumber(string $caller): void
    {
        $service = $this->invoiceStorage(); $before = $this->invoiceRows(); $this->startCaller($caller);
        try {
            try { $service->createInvoice(['user_id' => 7], [['description' => 'first', 'unit_amount_minor' => 1000], ['description' => 'invalid', 'unit_amount_minor' => 2000, 'metadata' => ['bad' => "\xFF"]]]); self::fail('Invalid JSON persisted'); }
            catch (\JsonException) { self::assertSame($before, $this->invoiceRows()); self::assertSame($caller !== 'standalone', $this->db->getPdo()->inTransaction()); }
        } finally { $this->endCaller($caller, false); }
    }
    public static function transactionalCallers(): array { return [['raw'], ['managed']]; }
    #[DataProvider('transactionalCallers')]
    public function testInvoiceSuccessRemainsInCallerRollback(string $caller): void
    {
        $service = $this->invoiceStorage(); $this->startCaller($caller);
        try { $invoice = $service->createInvoice(['user_id' => 7], [['description' => 'good', 'unit_amount_minor' => 1000]]); self::assertSame(1000, $invoice->getTotalMinor()); self::assertTrue($this->db->getPdo()->inTransaction()); }
        finally { $this->endCaller($caller, false); }
        self::assertSame([[], [], []], $this->invoiceRows());
    }
    public function testGenericTransactionUsesSavepointInRawCaller(): void
    {
        $this->invoiceStorage(); $this->db->getPdo()->beginTransaction();
        try {
            $this->db->insert('closeout_marker', ['id' => 1]);
            try { $this->db->transaction(function (): void { $this->db->insert('closeout_marker', ['id' => 2]); throw new \RuntimeException('fixture abort'); }); self::fail('Callback failure ignored'); }
            catch (\RuntimeException $error) { self::assertSame('fixture abort', $error->getMessage()); }
            self::assertTrue($this->db->getPdo()->inTransaction()); self::assertSame([['id' => 1]], $this->db->select('SELECT id FROM closeout_marker ORDER BY id'));
            $this->db->transaction(fn() => $this->db->transaction(fn() => $this->db->insert('closeout_marker', ['id' => 3])));
        } finally { $this->db->getPdo()->rollBack(); }
        self::assertSame([], $this->db->select('SELECT * FROM closeout_marker'));
    }
    public function testLostManagedTransactionCannotWriteAutocommittedData(): void
    {
        $this->invoiceStorage(); $this->db->beginTransaction(); $this->db->getPdo()->rollBack();
        try { $this->db->transaction(fn() => $this->db->insert('closeout_marker', ['id' => 1])); self::fail('Lost caller accepted'); }
        catch (\RuntimeException) { self::assertSame([], $this->db->getPdo()->query('SELECT * FROM closeout_marker')->fetchAll(\PDO::FETCH_ASSOC)); }
    }
    public function testFreshInstallLoginPermissionInvoicePaymentAndReplay(): void
    {
        $installer = new \Coleza\Domain\Installer\DatabaseSetupService();
        $installed = $installer->initializeApplicationSchema($this->db);
        self::assertTrue($installed['success']);
        self::assertCount(26, $installed['migrations_applied']);
        $admin = (new \Coleza\Domain\Installer\AdminBootstrapService())->bootstrapAdmin($this->db,
            new \Coleza\Domain\Installer\AdminSetupDto('closeout-admin@example.test', 'CloseoutPassword123!', 'Test', 'Admin'));
        $auth = new \Coleza\Domain\Identity\Auth\AuthService($this->db,
            new \Coleza\Domain\Identity\Security\PasswordHasher(PASSWORD_BCRYPT, ['cost' => 12]),
            new \Coleza\Domain\Identity\Session\DatabaseSessionHandler($this->db));
        self::assertSame($admin['id'], (int) $auth->authenticate($admin['email'], 'CloseoutPassword123!')['id']);
        $rbac = new \Coleza\Domain\Identity\Rbac\RbacService($this->db);
        self::assertTrue($rbac->hasPermission($admin['id'], 'payments.manage'));
        $customer = $auth->register('closeout-customer@example.test', 'CustomerPassword123!', 'Customer');
        self::assertFalse($rbac->hasPermission($customer, 'payments.manage'));
        $invoices = new \Coleza\Domain\Commerce\Invoices\InvoiceService($this->db);
        $invoice = $invoices->createInvoice(['user_id' => $customer, 'currency_code' => 'TRY'], [['description' => 'Hosting', 'unit_amount_minor' => 10000]]);
        $payments = new \Coleza\Domain\Commerce\Payments\PaymentService($this->db, $invoices);
        $payment = $payments->recordPayment(['user_id' => $customer, 'invoice_id' => $invoice->getId(), 'currency_code' => 'TRY', 'amount_minor' => 10000, 'payment_method' => 'iyzico', 'status' => 'pending']);
        $settled = $payments->completePaymentFromGateway($payment->getId(), 'fixture-settlement');
        self::assertTrue($settled->isCompleted());
        $payments->completePaymentFromGateway($payment->getId(), 'fixture-settlement');
        self::assertSame(10000, $invoices->findInvoiceById($invoice->getId())->getPaidAmountMinor());
        self::assertSame('paid', $invoices->findInvoiceById($invoice->getId())->getStatus());
        self::assertSame(1, (int) $this->db->selectOne('SELECT COUNT(*) AS n FROM payment_allocations')['n']);
        $before = [$this->invoiceRows(), $this->db->select('SELECT * FROM payments ORDER BY id'), $this->db->select('SELECT * FROM payment_allocations ORDER BY id'), $this->db->select('SELECT * FROM users ORDER BY id'), $this->db->select('SELECT * FROM user_roles ORDER BY user_id, role_id, organization_id')];
        self::assertSame([], $installer->initializeApplicationSchema($this->db)['migrations_applied']);
        self::assertSame($before, [$this->invoiceRows(), $this->db->select('SELECT * FROM payments ORDER BY id'), $this->db->select('SELECT * FROM payment_allocations ORDER BY id'), $this->db->select('SELECT * FROM users ORDER BY id'), $this->db->select('SELECT * FROM user_roles ORDER BY user_id, role_id, organization_id')]);
    }
    public function testLostManagedTransactionRejectsDirectWrites(): void
    {
        $this->invoiceStorage(); $this->db->beginTransaction(); $this->db->getPdo()->rollBack();
        try { $this->db->insert('closeout_marker', ['id' => 1]); self::fail('Autocommitted write accepted'); }
        catch (\RuntimeException) { self::assertSame([], $this->db->getPdo()->query('SELECT * FROM closeout_marker')->fetchAll(\PDO::FETCH_ASSOC)); }
        $this->db->rollBack();
        $this->db->transaction(fn() => $this->db->insert('closeout_marker', ['id' => 2]));
        self::assertSame(2, (int) $this->db->selectOne('SELECT id FROM closeout_marker')['id']);
    }

}
