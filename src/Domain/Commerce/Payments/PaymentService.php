<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Payments;

use Coleza\Domain\Commerce\Invoices\Invoice;
use Coleza\Domain\Commerce\Invoices\InvoiceService;
use Coleza\Domain\Commerce\Payments\Exceptions\PaymentRefundFailedException;
use Coleza\Domain\Commerce\Payments\Gateways\PaymentGatewayInterface;
use Coleza\Domain\Commerce\Payments\Gateways\PaymentRefundRequest;
use Coleza\Domain\Commerce\Payments\RefundAttempt;
use Coleza\Domain\Commerce\Orders\OrderService;
use Coleza\Domain\Commerce\Orders\OrderStateMachine;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use RuntimeException;

final class PaymentService
{
    private string $paymentsTable = 'payments';
    private string $paymentAllocationsTable = 'payment_allocations';
    private string $paymentSequencesTable = 'payment_sequences';
    private string $refundsTable = 'refunds';
    private string $refundSequencesTable = 'refund_sequences';
    private string $refundAttemptsTable = 'payment_refund_attempts';
    private string $webhookEventsTable = 'payment_webhook_events';

    public function __construct(
        private Connection $db,
        private ?InvoiceService $invoiceService = null,
        private ?OrderService $orderService = null
    ) {
    }

    public function ensureTables(): void
    {
        if ($this->db->getDriverName() === 'mysql' && $this->db->inTransaction()) {
            throw new RuntimeException('Initialize payment schema before starting an application transaction.');
        }
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        // Payments table
        $sqlPayments = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                payment_number VARCHAR(50) NOT NULL UNIQUE,
                user_id INT NOT NULL,
                organization_id INT NULL,
                invoice_id INT NULL,
                payment_method VARCHAR(50) NOT NULL,
                amount_minor INT NOT NULL,
                fee_minor INT NOT NULL DEFAULT 0,
                net_amount_minor INT NOT NULL,
                currency_code VARCHAR(3) NOT NULL,
                status VARCHAR(50) NOT NULL DEFAULT "pending",
                transaction_reference VARCHAR(255) NULL,
                proof_document_url VARCHAR(255) NULL,
                notes TEXT NULL,
                paid_at TIMESTAMP NULL,
                metadata_json TEXT NULL,
                checkout_token_hash VARCHAR(64) NULL UNIQUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->paymentsTable,
            $autoInc
        );
        $this->db->statement($sqlPayments);

        // Payment Allocations table
        $sqlAllocations = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                payment_id INT NOT NULL,
                invoice_id INT NOT NULL,
                invoice_item_id INT NULL,
                amount_minor INT NOT NULL,
                allocated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                metadata_json TEXT NULL
            )',
            $this->paymentAllocationsTable,
            $autoInc
        );
        $this->db->statement($sqlAllocations);

        // Payment Sequences table
        $sqlSequences = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                date_prefix VARCHAR(8) PRIMARY KEY,
                last_number INT NOT NULL DEFAULT 0
            )',
            $this->paymentSequencesTable
        );
        $this->db->statement($sqlSequences);

        // Refunds table
        $sqlRefunds = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                refund_number VARCHAR(50) NOT NULL UNIQUE,
                payment_id INT NOT NULL,
                invoice_id INT NULL,
                user_id INT NOT NULL,
                amount_minor INT NOT NULL,
                currency_code VARCHAR(3) NOT NULL,
                reason VARCHAR(255) NOT NULL,
                refund_method VARCHAR(50) NOT NULL DEFAULT "manual",
                transaction_reference VARCHAR(255) NULL,
                refunded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                metadata_json TEXT NULL
            )',
            $this->refundsTable,
            $autoInc
        );
        $this->db->statement($sqlRefunds);

        // Refund Sequences table
        $sqlRefundSeq = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                date_prefix VARCHAR(8) PRIMARY KEY,
                last_number INT NOT NULL DEFAULT 0
            )',
            $this->refundSequencesTable
        );
        $this->db->statement($sqlRefundSeq);

        // Refund Attempts table
        $sqlRefundAttempts = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                payment_id INT NOT NULL,
                amount_minor INT NOT NULL,
                reason VARCHAR(255) NOT NULL,
                gateway VARCHAR(50) NOT NULL,
                status VARCHAR(50) NOT NULL,
                error_code VARCHAR(100) NULL,
                error_message TEXT NULL,
                transaction_reference VARCHAR(255) NULL,
                metadata_json TEXT NULL,
                attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->refundAttemptsTable,
            $autoInc
        );
        $this->db->statement($sqlRefundAttempts);

        // Payment Webhook Events table
        $sqlWebhookEvents = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                gateway VARCHAR(50) NOT NULL,
                event_id VARCHAR(150) NOT NULL,
                event_type VARCHAR(100) NOT NULL,
                payload_hash VARCHAR(64) NOT NULL,
                status VARCHAR(50) NOT NULL,
                payment_id INT NULL,
                error_message TEXT NULL,
                payload_json TEXT NULL,
                processed_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (gateway, event_id)
            )',
            $this->webhookEventsTable,
            $autoInc
        );
        $this->db->statement($sqlWebhookEvents);
        (new PaymentConcurrencySchema($this->db))->ensureSupportingTables();
    }

    /**
     * Record a manual payment (bank transfer submission, admin manual entry, or direct payment).
     *
     * @param array<string, mixed> $data
     */
    public function recordPayment(array $data): Payment
    {
        return $this->paymentTransaction(function () use ($data) {
            return $this->recordPaymentLocked($data);
        });
    }

    private function recordPaymentLocked(array $data): Payment
    {
        $userId = (int)($data['user_id'] ?? 0);
        if ($userId <= 0) {
            throw new ValidationException(['user_id' => 'Valid user_id is required.'], 'Invalid payment data');
        }

        $amountMinor = (int)($data['amount_minor'] ?? 0);
        if ($amountMinor <= 0) {
            throw new ValidationException(['amount_minor' => 'Amount must be greater than zero.'], 'Invalid payment amount');
        }

        $currencyCode = strtoupper(trim((string)($data['currency_code'] ?? 'USD')));
        $paymentMethod = (string)($data['payment_method'] ?? Payment::METHOD_BANK_TRANSFER);
        $orgId = isset($data['organization_id']) && $data['organization_id'] !== null ? (int)$data['organization_id'] : null;
        $invoiceId = isset($data['invoice_id']) && $data['invoice_id'] !== null ? (int)$data['invoice_id'] : null;
        $feeMinor = max(0, (int)($data['fee_minor'] ?? 0));
        $netAmountMinor = max(0, $amountMinor - $feeMinor);
        $txRef = isset($data['transaction_reference']) ? (string)$data['transaction_reference'] : null;
        $proofUrl = isset($data['proof_document_url']) ? (string)$data['proof_document_url'] : null;
        $notes = isset($data['notes']) ? (string)$data['notes'] : null;
        $metadata = (array)($data['metadata'] ?? []);

        // Determine initial status
        $status = (string)($data['status'] ?? ($paymentMethod === Payment::METHOD_BANK_TRANSFER ? Payment::STATUS_PENDING : Payment::STATUS_COMPLETED));
        $paidAt = $status === Payment::STATUS_COMPLETED ? date('Y-m-d H:i:s') : null;

        $targetInvoice = null;
        if ($invoiceId !== null && $this->invoiceService !== null) {
            $targetInvoice = $this->invoiceService->findInvoiceById($invoiceId);
            if ($targetInvoice === null) {
                throw new ValidationException(['invoice_id' => "Invoice {$invoiceId} does not exist."], 'Invoice not found');
            }
            if ($targetInvoice->getCurrencyCode() !== $currencyCode) {
                throw new ValidationException(['currency_code' => 'Payment currency does not match invoice currency.'], 'Currency mismatch');
            }
            if ($targetInvoice->getUserId() !== $userId || ($orgId !== null && $orgId !== $targetInvoice->getOrganizationId())) {
                throw new ValidationException(['invoice_id' => 'Payment must belong to the invoice customer and organization.'], 'Payment owner mismatch');
            }
            $orgId = $targetInvoice->getOrganizationId();
        }

        $paymentNumber = $this->nextPaymentNumber();

        $sql = sprintf(
            'INSERT INTO %s (payment_number, user_id, organization_id, invoice_id, payment_method, amount_minor, fee_minor, net_amount_minor, currency_code, status, transaction_reference, proof_document_url, notes, paid_at, metadata_json, checkout_token_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $this->paymentsTable
        );

        $this->db->statement($sql, [
            $paymentNumber,
            $userId,
            $orgId,
            $invoiceId,
            $paymentMethod,
            $amountMinor,
            $feeMinor,
            $netAmountMinor,
            $currencyCode,
            $status,
            $txRef,
            $proofUrl,
            $notes,
            $paidAt,
            json_encode($metadata, JSON_THROW_ON_ERROR),
            isset($metadata['checkout_token']) ? $this->tokenHash($metadata['checkout_token']) : null,
        ]);

        $paymentId = (int)$this->db->getPdo()->lastInsertId();

        $allocations = [];
        // If completed and linked to invoice, allocate
        if ($status === Payment::STATUS_COMPLETED && $targetInvoice !== null) {
            $allocationAmount = min($amountMinor, $targetInvoice->getBalanceDueMinor());
            if ($allocationAmount > 0) {
                $allocations[] = $this->executeAllocation($paymentId, $targetInvoice, $allocationAmount, $paidAt);
            }
        }

        return new Payment(
            id: $paymentId,
            paymentNumber: $paymentNumber,
            userId: $userId,
            organizationId: $orgId,
            invoiceId: $invoiceId,
            paymentMethod: $paymentMethod,
            amountMinor: $amountMinor,
            feeMinor: $feeMinor,
            netAmountMinor: $netAmountMinor,
            currencyCode: $currencyCode,
            status: $status,
            transactionReference: $txRef,
            proofDocumentUrl: $proofUrl,
            notes: $notes,
            paidAt: $paidAt,
            metadata: $metadata,
            allocations: $allocations,
            createdAt: date('Y-m-d H:i:s')
        );
    }

    /**
     * Admin approves a pending bank transfer / manual payment proof.
     */
    public function approveManualPayment(int $paymentId, ?string $notes = null): Payment
    {
        return $this->db->transaction(function () use ($paymentId, $notes) {
            $this->db->lockRow($this->paymentsTable, $paymentId);
            return $this->approveManualPaymentLocked($paymentId, $notes);
        });
    }

    private function approveManualPaymentLocked(int $paymentId, ?string $notes = null): Payment
    {
        $payment = $this->findPaymentById($paymentId);
        if ($payment === null) {
            throw new RuntimeException("Payment {$paymentId} not found.");
        }

        if ($payment->getStatus() !== Payment::STATUS_PENDING) {
            throw new ValidationException(['status' => 'Only pending payments can be approved.'], 'Invalid payment transition');
        }

        $paidAt = date('Y-m-d H:i:s');
        $updatedNotes = $notes !== null ? trim($payment->getNotes() . "\n" . $notes) : $payment->getNotes();

        $sql = sprintf('UPDATE %s SET status = ?, paid_at = ?, notes = ? WHERE id = ?', $this->paymentsTable);
        $this->db->statement($sql, [Payment::STATUS_COMPLETED, $paidAt, $updatedNotes, $paymentId]);

        // Allocate to linked invoice if present
        if ($payment->getInvoiceId() !== null && $this->invoiceService !== null) {
            $invoice = $this->invoiceService->findInvoiceById($payment->getInvoiceId());
            if ($invoice !== null) {
                $allocationAmount = min($payment->getAmountMinor(), $invoice->getBalanceDueMinor());
                if ($allocationAmount > 0) {
                    $this->executeAllocation($paymentId, $invoice, $allocationAmount, $paidAt);
                }
            }
        }

        return $this->findPaymentById($paymentId);
    }

    /**
     * Admin rejects a pending bank transfer payment proof.
     */
    public function rejectManualPayment(int $paymentId, string $reason): Payment
    {
        return $this->db->transaction(function () use ($paymentId, $reason) {
            $this->db->lockRow($this->paymentsTable, $paymentId);
            return $this->rejectManualPaymentLocked($paymentId, $reason);
        });
    }

    private function rejectManualPaymentLocked(int $paymentId, string $reason): Payment
    {
        $payment = $this->findPaymentById($paymentId);
        if ($payment === null) {
            throw new RuntimeException("Payment {$paymentId} not found.");
        }

        if ($payment->getStatus() !== Payment::STATUS_PENDING) {
            throw new ValidationException(['status' => 'Only pending payments can be rejected.'], 'Invalid payment transition');
        }

        $updatedNotes = trim($payment->getNotes() . "\nRejected: " . $reason);
        $sql = sprintf('UPDATE %s SET status = ?, notes = ? WHERE id = ?', $this->paymentsTable);
        $this->db->statement($sql, [Payment::STATUS_FAILED, $updatedNotes, $paymentId]);

        return $this->findPaymentById($paymentId);
    }

    /**
     * Allocate payment amount to an invoice.
     */
    public function allocatePayment(
        int $paymentId,
        int $invoiceId,
        int $amountMinor,
        ?int $invoiceItemId = null,
        array $metadata = []
    ): PaymentAllocation
    {
        return $this->db->transaction(function () use ($paymentId, $invoiceId, $amountMinor, $invoiceItemId, $metadata) {
            $this->db->lockRow($this->paymentsTable, $paymentId);
            return $this->allocatePaymentLocked($paymentId, $invoiceId, $amountMinor, $invoiceItemId, $metadata);
        });
    }

    private function allocatePaymentLocked(
        int $paymentId,
        int $invoiceId,
        int $amountMinor,
        ?int $invoiceItemId = null,
        array $metadata = []
    ): PaymentAllocation {
        if ($amountMinor <= 0) {
            throw new ValidationException(['amount_minor' => 'Allocation amount must be greater than zero.'], 'Invalid allocation amount');
        }

        $payment = $this->findPaymentById($paymentId);
        if ($payment === null) {
            throw new RuntimeException("Payment {$paymentId} not found.");
        }

        if (!$payment->isCompleted()) {
            throw new ValidationException(['payment' => 'Cannot allocate an uncompleted payment.'], 'Payment not completed');
        }

        if ($payment->getUnallocatedAmountMinor() < $amountMinor) {
            throw new ValidationException(['amount_minor' => 'Allocation amount exceeds available unallocated payment balance.'], 'Insufficient payment balance');
        }

        if ($this->invoiceService === null) {
            throw new RuntimeException('InvoiceService required to allocate payment.');
        }

        $invoice = $this->invoiceService->findInvoiceById($invoiceId);
        if ($invoice === null) {
            throw new RuntimeException("Invoice {$invoiceId} not found.");
        }

        if ($invoice->getCurrencyCode() !== $payment->getCurrencyCode()) {
            throw new ValidationException(['currency' => 'Payment currency does not match invoice currency.'], 'Currency mismatch');
        }
        if ($invoice->getUserId() !== $payment->getUserId() || $invoice->getOrganizationId() !== $payment->getOrganizationId()) {
            throw new ValidationException(['invoice_id' => 'Cannot allocate across customers or organizations.'], 'Allocation owner mismatch');
        }

        if ($invoice->getBalanceDueMinor() < $amountMinor) {
            throw new ValidationException(['amount_minor' => 'Allocation amount exceeds invoice balance due.'], 'Over-allocation not permitted');
        }

        return $this->executeAllocation($paymentId, $invoice, $amountMinor, date('Y-m-d H:i:s'), $invoiceItemId, $metadata);
    }

    public function findPaymentById(int $id): ?Payment
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE id = ?', $this->paymentsTable) . $this->db->forUpdate(), [$id]);
        return $row ? $this->hydratePayment($row) : null;
    }

    public function findPaymentByNumber(string $paymentNumber): ?Payment
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE payment_number = ?', $this->paymentsTable), [$paymentNumber]);
        return $row ? $this->hydratePayment($row) : null;
    }

    /**
     * @return array<Payment>
     */
    public function listPaymentsForInvoice(int $invoiceId): array
    {
        $allocRows = $this->db->select(
            sprintf('SELECT DISTINCT payment_id FROM %s WHERE invoice_id = ?', $this->paymentAllocationsTable),
            [$invoiceId]
        );

        $paymentIds = array_column($allocRows, 'payment_id');
        if (empty($paymentIds)) {
            // Also check payments directly referencing invoice_id
            $directRows = $this->db->select(
                sprintf('SELECT id FROM %s WHERE invoice_id = ?', $this->paymentsTable),
                [$invoiceId]
            );
            $paymentIds = array_column($directRows, 'id');
        }

        if (empty($paymentIds)) {
            return [];
        }

        $payments = [];
        foreach (array_unique($paymentIds) as $pid) {
            $p = $this->findPaymentById((int)$pid);
            if ($p !== null) {
                $payments[] = $p;
            }
        }

        return $payments;
    }

    /**
     * @return array<Payment>
     */
    public function listPaymentsForUser(int $userId, ?int $orgId = null): array
    {
        $sql = sprintf('SELECT * FROM %s WHERE user_id = ?', $this->paymentsTable);
        $params = [$userId];

        if ($orgId !== null) {
            $sql .= ' AND organization_id = ?';
            $params[] = $orgId;
        }
        $sql .= ' ORDER BY id DESC';

        $rows = $this->db->select($sql, $params);
        return array_map([$this, 'hydratePayment'], $rows);
    }

    /**
     * Generate sequential payment reference (PAY-YYYYMMDD-XXXXXX).
     */
    public function nextPaymentNumber(): string
    {
        $date = date('Ymd');

        $num = $this->db->nextSequence($this->paymentSequencesTable, 'date_prefix', $date);
        $formattedNum = str_pad((string)$num, 6, '0', STR_PAD_LEFT);

        return "PAY-{$date}-{$formattedNum}";
    }

    /**
     * Internal allocation execution helper.
     *
     * @param array<string, mixed> $metadata
     */
    private function executeAllocation(
        int $paymentId,
        Invoice $invoice,
        int $amountMinor,
        ?string $allocatedAt,
        ?int $invoiceItemId = null,
        array $metadata = []
    ): PaymentAllocation {
        $timestamp = $allocatedAt ?? date('Y-m-d H:i:s');

        $sql = sprintf(
            'INSERT INTO %s (payment_id, invoice_id, invoice_item_id, amount_minor, allocated_at, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?)',
            $this->paymentAllocationsTable
        );

        $this->db->statement($sql, [
            $paymentId,
            $invoice->getId(),
            $invoiceItemId,
            $amountMinor,
            $timestamp,
            json_encode($metadata),
        ]);

        $allocId = (int)$this->db->getPdo()->lastInsertId();

        // Update invoice balance
        if ($this->invoiceService !== null && $invoice->getId() !== null) {
            $updatedInvoice = $this->invoiceService->applyPayment($invoice->getId(), $amountMinor, $timestamp);

            // If invoice is fully paid and linked to an order, transition order to active
            if ($updatedInvoice->isPaid() && $updatedInvoice->getOrderId() !== null && $this->orderService !== null) {
                $order = $this->orderService->findOrderById($updatedInvoice->getOrderId());
                if ($order !== null && $order->getStatus() === OrderStateMachine::STATUS_PENDING_PAYMENT) {
                    $this->orderService->transitionOrderStatus($order->getId(), OrderStateMachine::STATUS_ACTIVE);
                }
            }
        }

        return new PaymentAllocation(
            id: $allocId,
            paymentId: $paymentId,
            invoiceId: $invoice->getId(),
            amountMinor: $amountMinor,
            invoiceItemId: $invoiceItemId,
            allocatedAt: $timestamp,
            metadata: $metadata
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydratePayment(array $row): Payment
    {
        $paymentId = (int)$row['id'];
        $allocRows = $this->db->select(
            sprintf('SELECT * FROM %s WHERE payment_id = ? ORDER BY id ASC', $this->paymentAllocationsTable) . $this->db->forUpdate(),
            [$paymentId]
        );

        $allocations = [];
        foreach ($allocRows as $aRow) {
            $meta = !empty($aRow['metadata_json']) ? json_decode((string)$aRow['metadata_json'], true) : [];
            $allocations[] = new PaymentAllocation(
                id: (int)$aRow['id'],
                paymentId: (int)$aRow['payment_id'],
                invoiceId: (int)$aRow['invoice_id'],
                amountMinor: (int)$aRow['amount_minor'],
                invoiceItemId: $aRow['invoice_item_id'] !== null ? (int)$aRow['invoice_item_id'] : null,
                allocatedAt: (string)$aRow['allocated_at'],
                metadata: is_array($meta) ? $meta : []
            );
        }

        $meta = !empty($row['metadata_json']) ? json_decode((string)$row['metadata_json'], true) : [];

        $refundRow = $this->db->selectOne(
            sprintf('SELECT COALESCE(SUM(amount_minor), 0) AS total_refunded FROM %s WHERE payment_id = ?', $this->refundsTable) . $this->db->forUpdate(),
            [$paymentId]
        );
        $refundedAmountMinor = $refundRow ? (int)$refundRow['total_refunded'] : 0;

        return new Payment(
            id: $paymentId,
            paymentNumber: (string)$row['payment_number'],
            userId: (int)$row['user_id'],
            organizationId: $row['organization_id'] !== null ? (int)$row['organization_id'] : null,
            invoiceId: $row['invoice_id'] !== null ? (int)$row['invoice_id'] : null,
            paymentMethod: (string)$row['payment_method'],
            amountMinor: (int)$row['amount_minor'],
            feeMinor: (int)$row['fee_minor'],
            netAmountMinor: (int)$row['net_amount_minor'],
            currencyCode: (string)$row['currency_code'],
            status: (string)$row['status'],
            transactionReference: $row['transaction_reference'] !== null ? (string)$row['transaction_reference'] : null,
            proofDocumentUrl: $row['proof_document_url'] !== null ? (string)$row['proof_document_url'] : null,
            notes: $row['notes'] !== null ? (string)$row['notes'] : null,
            paidAt: $row['paid_at'] !== null ? (string)$row['paid_at'] : null,
            metadata: is_array($meta) ? $meta : [],
            allocations: $allocations,
            refundedAmountMinor: $refundedAmountMinor,
            createdAt: (string)$row['created_at']
        );
    }

    /**
     * Record a refund against a completed payment.
     *
     * @param array<string, mixed> $data
     */
    public function recordRefund(array $data): Refund
    {
        return $this->db->transaction(function () use ($data) {
            $this->db->lockRow($this->paymentsTable, (int) ($data['payment_id'] ?? 0));
            return $this->recordRefundLocked($data);
        });
    }

    private function recordRefundLocked(array $data, ?string $reservationId = null): Refund
    {
        $paymentId = (int)($data['payment_id'] ?? 0);
        $payment = $this->findPaymentById($paymentId);
        if ($payment === null) {
            throw new RuntimeException("Payment {$paymentId} not found.");
        }

        if (!$payment->isCompleted() && !$payment->isPartiallyRefunded()) {
            throw new ValidationException(['payment' => 'Can only refund completed or partially refunded payments.'], 'Invalid payment status for refund');
        }

        $amountMinor = (int)($data['amount_minor'] ?? 0);
        if ($amountMinor <= 0) {
            throw new ValidationException(['amount_minor' => 'Refund amount must be greater than zero.'], 'Invalid refund amount');
        }

        $reserved = $this->reservedRefundAmount($paymentId, $reservationId);
        if ($reserved > 0) {
            throw new ValidationException(['refund' => 'An outstanding provider refund must be reconciled first.'], 'Refund reservation unresolved');
        }
        if ($amountMinor > $payment->getRefundableAmountMinor()) {
            throw new ValidationException(['amount_minor' => 'Refund amount exceeds refundable balance of payment.'], 'Excessive refund amount');
        }

        $reason = trim((string)($data['reason'] ?? 'Customer refund request'));
        if ($reason === '') {
            throw new ValidationException(['reason' => 'Refund reason is required.'], 'Reason missing');
        }

        $refundMethod = (string)($data['refund_method'] ?? Refund::METHOD_MANUAL);
        $txRef = isset($data['transaction_reference']) ? (string)$data['transaction_reference'] : null;
        $refundNumber = $this->nextRefundNumber();
        $invoiceId = $payment->getInvoiceId();
        $userId = $payment->getUserId();
        $currencyCode = $payment->getCurrencyCode();
        $refundedAt = date('Y-m-d H:i:s');
        $metadata = (array)($data['metadata'] ?? []);

        $sql = sprintf(
            'INSERT INTO %s (refund_number, payment_id, invoice_id, user_id, amount_minor, currency_code, reason, refund_method, transaction_reference, refunded_at, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $this->refundsTable
        );

        $this->db->statement($sql, [
            $refundNumber,
            $paymentId,
            $invoiceId,
            $userId,
            $amountMinor,
            $currencyCode,
            $reason,
            $refundMethod,
            $txRef,
            $refundedAt,
            json_encode($metadata),
        ]);

        $refundId = (int)$this->db->getPdo()->lastInsertId();

        // Update payment status (partially_refunded or refunded)
        $newTotalRefunded = $payment->getRefundedAmountMinor() + $amountMinor;
        $isFullPaymentRefund = $newTotalRefunded >= $payment->getAmountMinor();
        $newPaymentStatus = $isFullPaymentRefund ? Payment::STATUS_REFUNDED : Payment::STATUS_PARTIALLY_REFUNDED;

        $this->db->statement(
            sprintf('UPDATE %s SET status = ? WHERE id = ?', $this->paymentsTable),
            [$newPaymentStatus, $paymentId]
        );

        // Adjust invoice balance if payment was allocated to an invoice
        $this->refundInvoiceAllocations($payment, $amountMinor);

        return new Refund(
            id: $refundId,
            refundNumber: $refundNumber,
            paymentId: $paymentId,
            invoiceId: $invoiceId,
            userId: $userId,
            amountMinor: $amountMinor,
            currencyCode: $currencyCode,
            reason: $reason,
            refundMethod: $refundMethod,
            transactionReference: $txRef,
            refundedAt: $refundedAt,
            metadata: $metadata
        );
    }

    public function nextRefundNumber(): string
    {
        $date = date('Ymd');

        $num = $this->db->nextSequence($this->refundSequencesTable, 'date_prefix', $date);
        $formattedNum = str_pad((string)$num, 6, '0', STR_PAD_LEFT);

        return "REF-{$date}-{$formattedNum}";
    }

    public function findRefundById(int $id): ?Refund
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE id = ?', $this->refundsTable), [$id]);
        return $row ? $this->hydrateRefund($row) : null;
    }

    public function findRefundByNumber(string $refundNumber): ?Refund
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE refund_number = ?', $this->refundsTable), [$refundNumber]);
        return $row ? $this->hydrateRefund($row) : null;
    }

    /**
     * @return array<Refund>
     */
    public function listRefundsForPayment(int $paymentId): array
    {
        $rows = $this->db->select(sprintf('SELECT * FROM %s WHERE payment_id = ? ORDER BY id DESC', $this->refundsTable), [$paymentId]);
        return array_map([$this, 'hydrateRefund'], $rows);
    }

    /**
     * @return array<Refund>
     */
    public function listRefundsForInvoice(int $invoiceId): array
    {
        $rows = $this->db->select(sprintf('SELECT * FROM %s WHERE invoice_id = ? ORDER BY id DESC', $this->refundsTable), [$invoiceId]);
        return array_map([$this, 'hydrateRefund'], $rows);
    }

    /**
     * @return array<Refund>
     */
    public function listRefundsForUser(int $userId): array
    {
        $rows = $this->db->select(sprintf('SELECT * FROM %s WHERE user_id = ? ORDER BY id DESC', $this->refundsTable), [$userId]);
        return array_map([$this, 'hydrateRefund'], $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateRefund(array $row): Refund
    {
        $meta = !empty($row['metadata_json']) ? json_decode((string)$row['metadata_json'], true) : [];

        return new Refund(
            id: (int)$row['id'],
            refundNumber: (string)$row['refund_number'],
            paymentId: (int)$row['payment_id'],
            invoiceId: $row['invoice_id'] !== null ? (int)$row['invoice_id'] : null,
            userId: (int)$row['user_id'],
            amountMinor: (int)$row['amount_minor'],
            currencyCode: (string)$row['currency_code'],
            reason: (string)$row['reason'],
            refundMethod: (string)$row['refund_method'],
            transactionReference: $row['transaction_reference'] !== null ? (string)$row['transaction_reference'] : null,
            refundedAt: (string)$row['refunded_at'],
            metadata: is_array($meta) ? $meta : []
        );
    }

    /**
     * Execute a refund via a payment gateway with failure protection and audit recording.
     *
     * @param array<string, mixed> $metadata
     */
    public function refundViaGateway(
        int $paymentId,
        int $amountMinor,
        string $reason,
        PaymentGatewayInterface $gateway,
        array $metadata = []
    ): Refund {
        return (new GatewayRefundCoordinator($this->db, $this))->refund($paymentId, $amountMinor, $reason, $gateway, $metadata);
    }

    /**
     * Record an audit attempt for a refund operation.
     *
     * @param array<string, mixed> $metadata
     */
    public function recordRefundAttempt(
        int $paymentId,
        int $amountMinor,
        string $reason,
        string $gateway,
        string $status,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        ?string $transactionReference = null,
        array $metadata = []
    ): RefundAttempt {
        $now = date('Y-m-d H:i:s');
        $sql = sprintf(
            'INSERT INTO %s (payment_id, amount_minor, reason, gateway, status, error_code, error_message, transaction_reference, metadata_json, attempted_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $this->refundAttemptsTable
        );

        $this->db->statement($sql, [
            $paymentId,
            $amountMinor,
            $reason,
            $gateway,
            $status,
            $errorCode,
            $errorMessage,
            $transactionReference,
            json_encode($metadata),
            $now,
        ]);

        $attemptId = (int)$this->db->getPdo()->lastInsertId();

        return new RefundAttempt(
            id: $attemptId,
            paymentId: $paymentId,
            amountMinor: $amountMinor,
            reason: $reason,
            gateway: $gateway,
            status: $status,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            transactionReference: $transactionReference,
            metadata: $metadata,
            attemptedAt: $now
        );
    }

    /**
     * @return array<RefundAttempt>
     */
    public function listRefundAttemptsForPayment(int $paymentId): array
    {
        $rows = $this->db->select(
            sprintf('SELECT * FROM %s WHERE payment_id = ? ORDER BY id DESC', $this->refundAttemptsTable),
            [$paymentId]
        );

        $attempts = [];
        foreach ($rows as $row) {
            $meta = !empty($row['metadata_json']) ? json_decode((string)$row['metadata_json'], true) : [];
            $attempts[] = new RefundAttempt(
                id: (int)$row['id'],
                paymentId: (int)$row['payment_id'],
                amountMinor: (int)$row['amount_minor'],
                reason: (string)$row['reason'],
                gateway: (string)$row['gateway'],
                status: (string)$row['status'],
                errorCode: $row['error_code'] !== null ? (string)$row['error_code'] : null,
                errorMessage: $row['error_message'] !== null ? (string)$row['error_message'] : null,
                transactionReference: $row['transaction_reference'] !== null ? (string)$row['transaction_reference'] : null,
                metadata: is_array($meta) ? $meta : [],
                attemptedAt: (string)$row['attempted_at']
            );
        }

        return $attempts;
    }

    /**
     * Mark a pending payment as completed following successful gateway webhook/callback settlement.
     */
    public function completePaymentFromGateway(
        int $paymentId,
        string $transactionRef,
        int $feeMinor = 0,
        ?string $paidAt = null
    ): Payment
    {
        return $this->db->transaction(function () use ($paymentId, $transactionRef, $feeMinor, $paidAt) {
            $this->db->lockRow($this->paymentsTable, $paymentId);
            return $this->completePaymentFromGatewayLocked($paymentId, $transactionRef, $feeMinor, $paidAt);
        });
    }

    private function completePaymentFromGatewayLocked(
        int $paymentId,
        string $transactionRef,
        int $feeMinor = 0,
        ?string $paidAt = null
    ): Payment {
        $payment = $this->findPaymentById($paymentId);
        if ($payment === null) {
            throw new RuntimeException("Payment {$paymentId} not found.");
        }

        if ($payment->isCompleted()) {
            return $payment;
        }

        if (!$payment->isPending()) {
            throw new ValidationException(['status' => 'Only pending payments can be completed.'], 'Invalid transition');
        }
        $paidTimestamp = $paidAt ?? date('Y-m-d H:i:s');
        $feeMinor = max(0, $feeMinor);
        $netAmountMinor = max(0, $payment->getAmountMinor() - $feeMinor);

        $sql = sprintf(
            'UPDATE %s SET status = ?, transaction_reference = ?, fee_minor = ?, net_amount_minor = ?, paid_at = ? WHERE id = ?',
            $this->paymentsTable
        );
        $this->db->statement($sql, [
            Payment::STATUS_COMPLETED,
            $transactionRef,
            $feeMinor,
            $netAmountMinor,
            $paidTimestamp,
            $paymentId,
        ]);

        // Allocate to invoice if linked
        if ($payment->getInvoiceId() !== null && $this->invoiceService !== null) {
            $invoice = $this->invoiceService->findInvoiceById($payment->getInvoiceId());
            if ($invoice !== null) {
                $allocationAmount = min($payment->getAmountMinor(), $invoice->getBalanceDueMinor());
                if ($allocationAmount > 0) {
                    $this->executeAllocation($paymentId, $invoice, $allocationAmount, $paidTimestamp);
                }
            }
        }

        return $this->findPaymentById($paymentId);
    }

    /**
     * Mark a pending payment as failed following gateway callback failure.
     */
    public function failPaymentFromGateway(int $paymentId, string $reason): Payment
    {
        return $this->db->transaction(function () use ($paymentId, $reason) {
            $this->db->lockRow($this->paymentsTable, $paymentId);
            return $this->failPaymentFromGatewayLocked($paymentId, $reason);
        });
    }

    private function failPaymentFromGatewayLocked(int $paymentId, string $reason): Payment
    {
        $payment = $this->findPaymentById($paymentId);
        if ($payment === null) {
            throw new RuntimeException("Payment {$paymentId} not found.");
        }

        if ($payment->getStatus() === Payment::STATUS_FAILED) { return $payment; }
        if (!$payment->isPending()) {
            throw new ValidationException(['status' => 'Cannot mark a completed or refunded payment as failed.'], 'Invalid transition');
        }

        $updatedNotes = trim($payment->getNotes() . "\nGateway failure: " . $reason);
        $sql = sprintf('UPDATE %s SET status = ?, notes = ? WHERE id = ?', $this->paymentsTable);
        $this->db->statement($sql, [Payment::STATUS_FAILED, $updatedNotes, $paymentId]);

        return $this->findPaymentById($paymentId);
    }

    /**
     * Find the unique checkout token, never a substring or an unrelated reference.
     * The token digest has a UNIQUE index; metadata is checked for exact correspondence.
     */
    public function findPaymentByToken(string $token): ?Payment
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $row = $this->db->selectOne('SELECT * FROM payments WHERE checkout_token_hash = ?', [$this->tokenHash($token)]);
        if ($row === null) { return null; }
        $metadata = json_decode((string) $row['metadata_json'], true);
        return is_array($metadata) && ($metadata['checkout_token'] ?? null) === $token ? $this->hydratePayment($row) : null;
    }

    public function attachCheckoutToken(int $paymentId, string $token): void
    {
        $this->paymentTransaction(function () use ($paymentId, $token): void {
            $this->db->lockRow($this->paymentsTable, $paymentId);
            $this->attachCheckoutTokenLocked($paymentId, $token);
        });
    }

    private function attachCheckoutTokenLocked(int $paymentId, string $token): void
    {
        $payment = $this->findPaymentById($paymentId);
        if ($payment === null || !$payment->isPending() || trim($token) === '') {
            throw new ValidationException(['checkout_token' => 'A pending payment and nonempty token are required.'], 'Invalid checkout token');
        }
        $metadata = $payment->getMetadata();
        if (isset($metadata['checkout_token']) && $metadata['checkout_token'] !== $token) {
            throw new ValidationException(['checkout_token' => 'An existing checkout token cannot be replaced.'], 'Invalid checkout token');
        }
        $metadata['checkout_token'] = $token;
        $this->db->statement(sprintf('UPDATE %s SET metadata_json = ?, checkout_token_hash = ? WHERE id = ?', $this->paymentsTable),
            [json_encode($metadata, JSON_THROW_ON_ERROR), $this->tokenHash($token), $paymentId]);
    }

    private function tokenHash(mixed $token): string
    {
        if (!is_string($token) || trim($token) === '' || trim($token) !== $token || strlen($token) > 2048) {
            throw new ValidationException(['checkout_token' => 'A nonempty token of at most 2048 bytes is required.'], 'Invalid checkout token');
        }
        return hash('sha256', $token);
    }

    private function paymentTransaction(callable $operation): mixed
    {
        try {
            return $this->db->transaction($operation);
        } catch (\PDOException $error) {
            if (str_contains($error->getMessage(), 'checkout_token')) {
                throw new ValidationException(['checkout_token' => 'Checkout token is already assigned.'], 'Duplicate checkout token');
            }
            throw $error;
        }
    }

    public function reservedRefundAmount(int $paymentId, ?string $exclude = null): int
    {
        $rows = $this->db->select('SELECT amount_minor FROM gateway_refund_reservations
            WHERE payment_id = ? AND status IN ("processing", "unknown", "verified") AND request_id <> ?' . $this->db->forUpdate(),
            [$paymentId, $exclude ?? '']);
        return array_sum(array_map(static fn (array $row): int => (int) $row['amount_minor'], $rows));
    }

    public function applyReservedGatewayRefund(string $requestId, string $reason, array $metadata = []): Refund
    {
        $scope = $this->db->selectOne('SELECT payment_id FROM gateway_refund_reservations WHERE request_id = ?', [$requestId]);
        if ($scope === null) { throw new RuntimeException('Refund reservation not found.'); }
        return $this->db->transaction(function () use ($scope, $requestId, $reason, $metadata): Refund {
            $this->db->lockRow($this->paymentsTable, (int) $scope['payment_id']);
            $reservation = $this->db->selectOne('SELECT * FROM gateway_refund_reservations WHERE request_id = ?' . $this->db->forUpdate(), [$requestId]);
            if ($reservation['status'] === 'applied') {
                return $this->findRefundById((int) $reservation['refund_id']) ?? throw new RuntimeException('Applied refund record is missing.');
            }
            if ($reservation['status'] !== 'verified') {
                throw new ValidationException(['refund' => 'Provider result must be reconciled before applying this refund.'], 'Unverified refund');
            }
            $refund = $this->recordRefundLocked(['payment_id' => (int) $reservation['payment_id'],
                'amount_minor' => (int) $reservation['amount_minor'], 'reason' => $reason,
                'refund_method' => $reservation['gateway'], 'transaction_reference' => $reservation['provider_reference'],
                'metadata' => array_merge($metadata, ['reservation_id' => $requestId])], $requestId);
            $this->db->statement('UPDATE gateway_refund_reservations SET status = "applied", refund_id = ? WHERE request_id = ?', [$refund->getId(), $requestId]);
            $this->recordRefundAttempt((int) $reservation['payment_id'], (int) $reservation['amount_minor'], $reason,
                $reservation['gateway'], RefundAttempt::STATUS_SUCCESS, transactionReference: $reservation['provider_reference']);
            return $refund;
        });
    }

    private function refundInvoiceAllocations(Payment $payment, int $amount): void
    {
        if ($this->invoiceService === null) { return; }
        $unallocated = $payment->getAmountMinor() - $payment->getAllocatedAmountMinor();
        $previous = max(0, $payment->getRefundedAmountMinor() - $unallocated);
        $remaining = max(0, $payment->getRefundedAmountMinor() + $amount - $unallocated) - $previous;
        foreach ($payment->getAllocations() as $allocation) {
            $skip = min($previous, $allocation->getAmountMinor());
            $previous -= $skip;
            $portion = min($remaining, $allocation->getAmountMinor() - $skip);
            if ($portion > 0) { $this->invoiceService->applyRefund((int) $allocation->getInvoiceId(), $portion); }
            $remaining -= $portion;
        }
    }
}
