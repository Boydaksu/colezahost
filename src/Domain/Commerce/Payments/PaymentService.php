<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Payments;

use Coleza\Domain\Commerce\Invoices\Invoice;
use Coleza\Domain\Commerce\Invoices\InvoiceService;
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

    public function __construct(
        private Connection $db,
        private ?InvoiceService $invoiceService = null,
        private ?OrderService $orderService = null
    ) {
    }

    public function ensureTables(): void
    {
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
    }

    /**
     * Record a manual payment (bank transfer submission, admin manual entry, or direct payment).
     *
     * @param array<string, mixed> $data
     */
    public function recordPayment(array $data): Payment
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
        }

        $paymentNumber = $this->nextPaymentNumber();

        $sql = sprintf(
            'INSERT INTO %s (payment_number, user_id, organization_id, invoice_id, payment_method, amount_minor, fee_minor, net_amount_minor, currency_code, status, transaction_reference, proof_document_url, notes, paid_at, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
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
            json_encode($metadata),
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

        if ($invoice->getBalanceDueMinor() < $amountMinor) {
            throw new ValidationException(['amount_minor' => 'Allocation amount exceeds invoice balance due.'], 'Over-allocation not permitted');
        }

        return $this->executeAllocation($paymentId, $invoice, $amountMinor, date('Y-m-d H:i:s'), $invoiceItemId, $metadata);
    }

    public function findPaymentById(int $id): ?Payment
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE id = ?', $this->paymentsTable), [$id]);
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

        $this->db->statement(
            sprintf(
                'INSERT INTO %s (date_prefix, last_number) VALUES (?, 1)
                 ON CONFLICT(date_prefix) DO UPDATE SET last_number = last_number + 1',
                $this->paymentSequencesTable
            ),
            [$date]
        );

        $row = $this->db->selectOne(
            sprintf('SELECT last_number FROM %s WHERE date_prefix = ?', $this->paymentSequencesTable),
            [$date]
        );

        $num = $row ? (int)$row['last_number'] : 1;
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
            sprintf('SELECT * FROM %s WHERE payment_id = ?', $this->paymentAllocationsTable),
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
            createdAt: (string)$row['created_at']
        );
    }
}
