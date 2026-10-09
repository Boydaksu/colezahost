<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Payments;

use Coleza\Domain\Commerce\Credit\CreditService;
use Coleza\Domain\Commerce\Invoices\InvoiceService;
use Coleza\Domain\Commerce\Payments\PaymentService;
use Coleza\Domain\Commerce\Payments\Payment;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Queue\DatabaseQueue;
use Coleza\Foundation\Queue\JobInterface;
use Coleza\Foundation\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

final class AtomicityJob implements JobInterface
{
    public function handle(): void {}
    public function queue(): string { return 'default'; }
    public function maxAttempts(): int { return 1; }
    public function backoffSeconds(): int { return 0; }
}

final class FinancialAtomicityRegressionTest extends TestCase
{
    private Connection $db;
    private InvoiceService $invoices;
    private PaymentService $payments;
    private int $invoice;

    protected function setUp(): void
    {
        $this->db = new Connection(new \PDO('sqlite::memory:'));
        $this->invoices = new InvoiceService($this->db);
        $this->invoices->ensureTables();
        $this->payments = new PaymentService($this->db, $this->invoices);
        $this->payments->ensureTables();
        $this->invoice = (int) $this->invoices->createInvoice(['user_id' => 7, 'currency_code' => 'TRY'],
            [['description' => 'Hosting', 'unit_amount_minor' => 10000]])->getId();
    }

    public function testFailedAllocationRollsBackPaymentApproval(): void
    {
        $payment = $this->payments->recordPayment(['user_id' => 7, 'invoice_id' => $this->invoice,
            'amount_minor' => 10000, 'currency_code' => 'TRY', 'payment_method' => 'bank_transfer']);
        $this->db->statement("CREATE TRIGGER fail_invoice BEFORE UPDATE ON invoices BEGIN SELECT RAISE(ABORT, 'invoice failure'); END");
        try {
            $this->payments->approveManualPayment((int) $payment->getId());
            self::fail('Injected invoice failure must propagate.');
        } catch (\PDOException) {}
        self::assertSame(Payment::STATUS_PENDING, $this->payments->findPaymentById((int) $payment->getId())->getStatus());
        self::assertSame(0, (int) $this->db->selectOne('SELECT COUNT(*) AS n FROM payment_allocations')['n']);
    }

    public function testFailedCreditPaymentDoesNotConsumeCredit(): void
    {
        $credit = new CreditService($this->db, $this->invoices, $this->payments);
        $credit->ensureTables();
        $credit->addCredit(7, 10000, 'TRY', 'Deposit');
        $this->db->statement("CREATE TRIGGER fail_payment BEFORE INSERT ON payments BEGIN SELECT RAISE(ABORT, 'payment failure'); END");
        try {
            $credit->applyCreditToInvoice(7, $this->invoice, 10000);
            self::fail('Injected payment failure must propagate.');
        } catch (\PDOException) {}
        self::assertSame(10000, $credit->getBalance(7, 'TRY'));
        self::assertSame(10000, $this->invoices->findInvoiceById($this->invoice)->getBalanceDueMinor());
    }

    public function testInvoiceCannotBeOverpaid(): void
    {
        $this->expectException(ValidationException::class);
        $this->invoices->applyPayment($this->invoice, 10001);
    }

    public function testFailedRefundRollsBackRefundAndPaymentStatus(): void
    {
        $payment = $this->payments->recordPayment(['user_id' => 7, 'invoice_id' => $this->invoice,
            'amount_minor' => 10000, 'currency_code' => 'TRY', 'payment_method' => 'manual']);
        $this->db->statement("CREATE TRIGGER fail_refund BEFORE UPDATE ON invoices BEGIN SELECT RAISE(ABORT, 'refund failure'); END");
        try {
            $this->payments->recordRefund(['payment_id' => $payment->getId(), 'amount_minor' => 1000]);
            self::fail('Injected refund failure must propagate.');
        } catch (\PDOException) {}
        self::assertSame(Payment::STATUS_COMPLETED, $this->payments->findPaymentById((int) $payment->getId())->getStatus());
        self::assertCount(0, $this->payments->listRefundsForPayment((int) $payment->getId()));
    }

    public function testOldQueueWorkerCannotDeleteReassignedJob(): void
    {
        $queue = new DatabaseQueue($this->db);
        $queue->push(new AtomicityJob());
        $first = $queue->pop();
        $this->db->statement('UPDATE jobs SET reserved_at = 0');
        $second = $queue->pop();
        self::assertNotNull($second);
        $queue->delete($first);
        self::assertSame(1, $queue->size());
        $queue->delete($second);
        self::assertSame(0, $queue->size());
    }

    public function testOldQueueWorkerCannotFailReassignedJob(): void
    {
        $queue = new DatabaseQueue($this->db);
        $queue->push(new AtomicityJob());
        $first = $queue->pop();
        $this->db->statement('UPDATE jobs SET reserved_at = 0');
        $queue->pop();
        $queue->releaseOrFail($first, new \RuntimeException('Old worker failed'));
        self::assertSame(1, $queue->size());
        self::assertSame(0, $queue->failedSize());
    }

    public function testCompletedRecordInsertionIsRolledBackWhenInvoiceFails(): void
    {
        $this->db->statement("CREATE TRIGGER fail_insert_invoice BEFORE UPDATE ON invoices BEGIN SELECT RAISE(ABORT, 'invoice failure'); END");
        try {
            $this->payments->recordPayment(['user_id' => 7, 'invoice_id' => $this->invoice,
                'amount_minor' => 10000, 'currency_code' => 'TRY', 'payment_method' => 'manual']);
            self::fail('Injected invoice failure must propagate.');
        } catch (\PDOException) {}
        self::assertCount(0, $this->payments->listPaymentsForUser(7));
        self::assertSame(10000, $this->invoices->findInvoiceById($this->invoice)->getBalanceDueMinor());
    }

    private function gatewayPayment(): int
    {
        return (int) $this->payments->recordPayment(['user_id' => 7, 'invoice_id' => $this->invoice,
            'amount_minor' => 10000, 'currency_code' => 'TRY', 'payment_method' => 'iyzico',
            'status' => Payment::STATUS_COMPLETED, 'transaction_reference' => 'provider-payment'])->getId();
    }

    public function testAmbiguousProviderRefundBlocksAllRetriesAndManualRefunds(): void
    {
        $payment = $this->gatewayPayment();
        $calls = 0;
        $gateway = new \Coleza\Domain\Commerce\Payments\Gateways\Iyzico\IyzicoPaymentGateway(
            new \Coleza\Domain\Commerce\Payments\Gateways\Iyzico\IyzicoConfiguration('key', 'secret'),
            static function () use (&$calls): never { $calls++; throw new \RuntimeException('Timeout after remote effect'); });
        try {
            $this->payments->refundViaGateway($payment, 1000, 'Refund', $gateway);
            self::fail('Ambiguous provider result must fail.');
        } catch (\Coleza\Domain\Commerce\Payments\Exceptions\PaymentRefundFailedException) {}
        try {
            $this->payments->refundViaGateway($payment, 1000, 'Retry', $gateway);
            self::fail('Retry must not send another provider request.');
        } catch (ValidationException) {}
        try {
            $this->payments->recordRefund(['payment_id' => $payment, 'amount_minor' => 100]);
            self::fail('A manual refund must not bypass an unknown reservation.');
        } catch (ValidationException) {}
        self::assertSame(1, $calls);
        self::assertSame('unknown', $this->db->selectOne('SELECT status FROM gateway_refund_reservations')['status']);
        self::assertSame(0, $this->payments->findPaymentById($payment)->getRefundedAmountMinor());
    }

    public function testProviderSuccessProofSurvivesLocalFailureAndCanResumeWithoutRemoteCall(): void
    {
        $payment = $this->gatewayPayment();
        $calls = 0;
        $gateway = new \Coleza\Domain\Commerce\Payments\Gateways\Iyzico\IyzicoPaymentGateway(
            new \Coleza\Domain\Commerce\Payments\Gateways\Iyzico\IyzicoConfiguration('key', 'secret'),
            static function () use (&$calls): array { $calls++; return ['code' => 200, 'body' => '{"status":"success","paymentId":"refund-proof"}']; });
        $this->db->statement("CREATE TRIGGER fail_apply_refund BEFORE UPDATE ON invoices BEGIN SELECT RAISE(ABORT, 'refund failure'); END");
        try {
            $this->payments->refundViaGateway($payment, 1000, 'Refund', $gateway);
            self::fail('Local failure must propagate.');
        } catch (\PDOException) {}
        $reservation = $this->db->selectOne('SELECT * FROM gateway_refund_reservations');
        self::assertSame('verified', $reservation['status']);
        self::assertSame('refund-proof', $reservation['provider_reference']);
        self::assertCount(0, $this->payments->listRefundsForPayment($payment));
        $this->db->statement('DROP TRIGGER fail_apply_refund');
        $refund = $this->payments->applyReservedGatewayRefund($reservation['request_id'], 'Resume');
        self::assertSame($refund->getId(), $this->payments->applyReservedGatewayRefund($reservation['request_id'], 'Duplicate resume')->getId());
        self::assertSame(1, $calls);
        self::assertSame(1000, $this->payments->findPaymentById($payment)->getRefundedAmountMinor());
    }

    public function testUnallocatedRefundDoesNotRemoveAnotherPaymentsInvoiceCredit(): void
    {
        $this->payments->recordPayment(['user_id' => 7, 'invoice_id' => $this->invoice,
            'amount_minor' => 10000, 'currency_code' => 'TRY', 'payment_method' => 'manual']);
        $unallocated = $this->payments->recordPayment(['user_id' => 7, 'invoice_id' => $this->invoice,
            'amount_minor' => 1000, 'currency_code' => 'TRY', 'payment_method' => 'manual']);
        $this->payments->recordRefund(['payment_id' => $unallocated->getId(), 'amount_minor' => 1000]);
        self::assertSame(10000, $this->invoices->findInvoiceById($this->invoice)->getPaidAmountMinor());
    }

    public function testCallbackEventFailureRollsBackSettlementAndAllocation(): void
    {
        $payment = $this->payments->recordPayment(['user_id' => 7, 'invoice_id' => $this->invoice,
            'amount_minor' => 10000, 'currency_code' => 'TRY', 'payment_method' => 'iyzico',
            'status' => Payment::STATUS_PENDING, 'metadata' => ['checkout_token' => 'atomic-callback']]);
        $gateway = new \Coleza\Domain\Commerce\Payments\Gateways\Iyzico\IyzicoPaymentGateway(
            new \Coleza\Domain\Commerce\Payments\Gateways\Iyzico\IyzicoConfiguration('key', 'secret'),
            static fn (): array => ['code' => 200, 'body' => json_encode(['status' => 'success', 'paymentStatus' => 'SUCCESS',
                'paymentId' => 'provider-payment', 'conversationId' => $payment->getPaymentNumber(), 'paidPrice' => '100.00', 'currency' => 'TRY'])]);
        $this->db->statement("CREATE TRIGGER fail_event BEFORE INSERT ON payment_webhook_events WHEN NEW.status = 'processed' BEGIN SELECT RAISE(ABORT, 'event failure'); END");
        try {
            (new \Coleza\Domain\Commerce\Payments\Gateways\PaymentWebhookHandler($this->db, $this->payments))->handleIyzicoCallback($gateway, ['token' => 'atomic-callback']);
            self::fail('Injected event failure must propagate.');
        } catch (\PDOException) {}
        self::assertSame(Payment::STATUS_PENDING, $this->payments->findPaymentById((int) $payment->getId())->getStatus());
        self::assertCount(0, $this->payments->findPaymentById((int) $payment->getId())->getAllocations());
        self::assertSame(0, (int) $this->db->selectOne('SELECT COUNT(*) AS n FROM payment_webhook_events')['n']);
    }

    public function testConnectionPreservesOriginalErrorAfterDatabaseRollback(): void
    {
        try {
            $this->db->transaction(function (): never {
                $this->db->getPdo()->rollBack();
                throw new \RuntimeException('Original failure');
            });
            self::fail('Original error must propagate.');
        } catch (\RuntimeException $error) {
            self::assertSame('Original failure', $error->getMessage());
        }
        self::assertFalse($this->db->inTransaction());
        self::assertSame(42, $this->db->transaction(static fn (): int => 42));
    }

    public function testPartialRefundsFollowStableAllocationOrderAcrossInvoices(): void
    {
        $secondInvoice = (int) $this->invoices->createInvoice(['user_id' => 7, 'currency_code' => 'TRY'],
            [['description' => 'Second service', 'unit_amount_minor' => 10000]])->getId();
        $payment = $this->payments->recordPayment(['user_id' => 7, 'amount_minor' => 12000,
            'currency_code' => 'TRY', 'payment_method' => 'manual']);
        $this->payments->allocatePayment((int) $payment->getId(), $this->invoice, 6000);
        $this->payments->allocatePayment((int) $payment->getId(), $secondInvoice, 6000);
        $this->payments->recordRefund(['payment_id' => $payment->getId(), 'amount_minor' => 5000]);
        self::assertSame(1000, $this->invoices->findInvoiceById($this->invoice)->getPaidAmountMinor());
        self::assertSame(6000, $this->invoices->findInvoiceById($secondInvoice)->getPaidAmountMinor());
        $this->payments->recordRefund(['payment_id' => $payment->getId(), 'amount_minor' => 3000]);
        self::assertSame(0, $this->invoices->findInvoiceById($this->invoice)->getPaidAmountMinor());
        self::assertSame(4000, $this->invoices->findInvoiceById($secondInvoice)->getPaidAmountMinor());
    }
}
