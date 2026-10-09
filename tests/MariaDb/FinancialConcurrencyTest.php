<?php

declare(strict_types=1);

namespace Coleza\Tests\MariaDb;

use Coleza\Domain\Commerce\Credit\CreditService;
use Coleza\Domain\Commerce\Invoices\InvoiceService;
use Coleza\Domain\Commerce\Payments\PaymentService;
use Coleza\Domain\Commerce\Payments\Payment;
use Coleza\Foundation\Database\Migrator;
use Coleza\Foundation\Queue\DatabaseQueue;
use Coleza\Tests\Support\MariaDbTestCase;
use Coleza\Tests\Support\ConcurrencyTestJob;

final class FinancialConcurrencyTest extends MariaDbTestCase
{
    private InvoiceService $invoices;
    private PaymentService $payments;
    private CreditService $credit;
    private int $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db))->migrate(dirname(__DIR__, 2) . '/database/migrations');
        $this->invoices = new InvoiceService($this->db);
        $this->invoices->ensureTables();
        $this->payments = new PaymentService($this->db, $this->invoices);
        $this->credit = new CreditService($this->db, $this->invoices, $this->payments);
        $this->credit->ensureTables();
        $this->invoice = (int) $this->invoices->createInvoice(['user_id' => 7, 'currency_code' => 'TRY'],
            [['description' => 'Hosting', 'unit_amount_minor' => 10000]])->getId();
    }

    private function payment(bool $completed = false): int
    {
        return (int) $this->payments->recordPayment(['user_id' => 7, 'invoice_id' => $this->invoice,
            'amount_minor' => 10000, 'currency_code' => 'TRY', 'payment_method' => 'iyzico',
            'transaction_reference' => 'provider-payment', 'status' => $completed ? Payment::STATUS_COMPLETED : Payment::STATUS_PENDING,
            'metadata' => ['checkout_token' => 'parallel-token']])->getId();
    }

    private function workers(string $action, int $id): array
    {
        $this->db->statement('CREATE TABLE worker_barrier (worker_id INT PRIMARY KEY, ready INT)');
        $this->db->statement('CREATE TABLE worker_control (id INT PRIMARY KEY, started INT)');
        $this->db->statement('INSERT INTO worker_control VALUES (1, 0)');
        $workers = [];
        try {
            for ($i = 0; $i < 4; $i++) {
                $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/financial-worker.php', $this->database, (string) $i, $action, (string) $id],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                fclose($pipes[0]);
                $workers[] = [$process, $pipes];
            }
            $deadline = microtime(true) + 15;
            do {
                $ready = (int) $this->db->selectOne('SELECT COUNT(*) AS n FROM worker_barrier')['n'];
                if ($ready === 4) { break; }
                usleep(10000);
            } while (microtime(true) < $deadline);
            self::assertSame(4, $ready);
            $this->db->statement('UPDATE worker_control SET started = 1');
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                stream_set_timeout($pipes[1], 20);
                $output = stream_get_contents($pipes[1]);
                self::assertSame('', stream_get_contents($pipes[2]));
                $results[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            }
            return $results;
        } finally {
            foreach ($workers as [$process, $pipes]) {
                fclose($pipes[1]); fclose($pipes[2]); proc_terminate($process); proc_close($process);
            }
        }
    }

    public function testConcurrentSettlementCreatesOnlyOneAllocation(): void
    {
        $payment = $this->payment();
        self::assertSame(['ok', 'ok', 'ok', 'ok'], array_column($this->workers('settle', $payment), 'status'));
        self::assertCount(1, $this->payments->findPaymentById($payment)->getAllocations());
        self::assertSame(10000, $this->invoices->findInvoiceById($this->invoice)->getPaidAmountMinor());
    }

    public function testConcurrentInvoicePaymentsCannotOverpay(): void
    {
        $results = $this->workers('invoice', $this->invoice);
        self::assertSame(1, count(array_filter($results, static fn ($r) => $r['status'] === 'ok')));
        self::assertSame(6000, $this->invoices->findInvoiceById($this->invoice)->getPaidAmountMinor());
    }

    public function testConcurrentCreditDebitCannotOverspend(): void
    {
        $this->credit->addCredit(7, 10000, 'TRY', 'Deposit');
        $results = $this->workers('credit', 7);
        self::assertSame(1, count(array_filter($results, static fn ($r) => $r['status'] === 'ok')));
        self::assertSame(4000, $this->credit->getBalance(7, 'TRY'));
    }

    public function testConcurrentCreditApplicationConservesInvoiceAndCredit(): void
    {
        $this->credit->addCredit(7, 10000, 'TRY', 'Deposit');
        $results = $this->workers('creditInvoice', $this->invoice);
        self::assertSame(1, count(array_filter($results, static fn ($r) => $r['status'] === 'ok')));
        self::assertSame(4000, $this->credit->getBalance(7, 'TRY'));
        self::assertSame(6000, $this->invoices->findInvoiceById($this->invoice)->getPaidAmountMinor());
    }

    public function testConcurrentRefundCannotExceedPayment(): void
    {
        $payment = $this->payment(true);
        $results = $this->workers('refund', $payment);
        self::assertSame(1, count(array_filter($results, static fn ($r) => $r['status'] === 'ok')));
        self::assertSame(6000, $this->payments->findPaymentById($payment)->getRefundedAmountMinor());
        self::assertSame(4000, $this->invoices->findInvoiceById($this->invoice)->getPaidAmountMinor());
    }

    public function testConcurrentGatewayRefundMakesOnlyOneProviderCall(): void
    {
        $payment = $this->payment(true);
        $results = $this->workers('gatewayRefund', $payment);
        self::assertSame(1, array_sum(array_column($results, 'provider_calls')));
        self::assertSame(1, count(array_filter($results, static fn ($r) => $r['status'] === 'ok')));
        self::assertSame(6000, $this->payments->findPaymentById($payment)->getRefundedAmountMinor());
    }

    public function testParallelCallbacksShareOneEventAndOneAllocation(): void
    {
        $payment = $this->payment();
        $statuses = array_column($this->workers('callback', $payment), 'callback');
        self::assertSame(1, count(array_filter($statuses, static fn ($s) => $s === 'processed')));
        self::assertSame(3, count(array_filter($statuses, static fn ($s) => $s === 'duplicate')));
        self::assertCount(1, $this->payments->findPaymentById($payment)->getAllocations());
        self::assertSame(1, (int) $this->db->selectOne('SELECT COUNT(*) AS n FROM payment_webhook_events')['n']);
    }

    public function testParallelQueueClaimsNeverReturnSameJob(): void
    {
        $queue = new DatabaseQueue($this->db);
        for ($i = 0; $i < 32; $i++) { $queue->push(new ConcurrencyTestJob()); }
        $jobs = array_merge(...array_column($this->workers('queue', 0), 'jobs'));
        self::assertCount(32, $jobs);
        self::assertCount(32, array_unique($jobs));
        self::assertSame(32, (int) $this->db->selectOne('SELECT COUNT(*) AS n FROM jobs WHERE attempts = 1 AND reservation_token IS NOT NULL')['n']);
    }

    public function testTokenLookupUsesAUniqueMariaDbIndex(): void
    {
        $id = $this->payment();
        $plan = $this->db->selectOne('EXPLAIN SELECT id FROM payments WHERE checkout_token_hash = ?', [hash('sha256', 'parallel-token')]);
        self::assertNotNull($plan['key']);
        self::assertSame('const', $plan['type']);
        self::assertSame($id, $this->payments->findPaymentByToken('parallel-token')->getId());
    }

    public function testSchemaSetupCannotImplicitlyCommitAnApplicationTransaction(): void
    {
        $this->db->beginTransaction();
        $this->db->statement('UPDATE invoices SET notes = "uncommitted" WHERE id = ?', [$this->invoice]);
        try {
            $this->payments->ensureTables();
            self::fail('Schema setup must not implicitly commit the application transaction.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Initialize payment schema', $error->getMessage());
        }
        self::assertTrue($this->db->inTransaction());
        $this->db->rollBack();
        self::assertNull($this->invoices->findInvoiceById($this->invoice)->getNotes());
    }
}
