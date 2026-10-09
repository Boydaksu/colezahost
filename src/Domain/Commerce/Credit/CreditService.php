<?php

declare(strict_types=1);

namespace Coleza\Domain\Commerce\Credit;

use Coleza\Domain\Commerce\Invoices\Invoice;
use Coleza\Domain\Commerce\Invoices\InvoiceService;
use Coleza\Domain\Commerce\Payments\Payment;
use Coleza\Domain\Commerce\Payments\PaymentService;
use Coleza\Domain\Commerce\Payments\Refund;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use RuntimeException;

final class CreditService
{
    private string $ledgerTable = 'credit_ledger';
    private string $sequencesTable = 'credit_sequences';

    public function __construct(
        private Connection $db,
        private ?InvoiceService $invoiceService = null,
        private ?PaymentService $paymentService = null
    ) {
    }

    public function ensureTables(): void
    {
        if ($this->db->getDriverName() === 'mysql' && $this->db->inTransaction()) {
            throw new RuntimeException('Initialize credit schema before starting an application transaction.');
        }
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        $sqlLedger = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                entry_number VARCHAR(50) NOT NULL UNIQUE,
                user_id INT NOT NULL,
                organization_id INT NULL,
                currency_code VARCHAR(3) NOT NULL,
                type VARCHAR(20) NOT NULL,
                amount_minor INT NOT NULL,
                balance_after_minor INT NOT NULL,
                reason VARCHAR(255) NOT NULL,
                reference_type VARCHAR(50) NULL,
                reference_id INT NULL,
                admin_user_id INT NULL,
                metadata_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->ledgerTable,
            $autoInc
        );
        $this->db->statement($sqlLedger);

        $sqlSequences = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                date_prefix VARCHAR(8) PRIMARY KEY,
                last_number INT NOT NULL DEFAULT 0
            )',
            $this->sequencesTable
        );
        $this->db->statement($sqlSequences);
        (new \Coleza\Domain\Commerce\Payments\PaymentConcurrencySchema($this->db))->ensureSupportingTables();
    }

    /**
     * Get current real-time credit balance in minor units.
     */
    public function getBalance(int $userId, string $currencyCode, ?int $orgId = null): int
    {
        $currency = strtoupper(trim($currencyCode));
        $sql = sprintf(
            'SELECT balance_after_minor FROM %s WHERE user_id = ? AND currency_code = ?',
            $this->ledgerTable
        );
        $params = [$userId, $currency];

        if ($orgId !== null) {
            $sql .= ' AND organization_id = ?';
            $params[] = $orgId;
        } else {
            $sql .= ' AND organization_id IS NULL';
        }

        $sql .= ' ORDER BY id DESC LIMIT 1';

        $row = $this->db->selectOne($sql . $this->db->forUpdate(), $params);
        return $row ? (int)$row['balance_after_minor'] : 0;
    }

    /**
     * Deposit credit into user balance.
     *
     * @param array<string, mixed> $metadata
     */
    public function addCredit(
        int $userId,
        int $amountMinor,
        string $currencyCode,
        string $reason,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $adminUserId = null,
        ?int $orgId = null,
        array $metadata = []
    ): CreditEntry
    {
        return $this->db->transaction(function () use ($userId, $amountMinor, $currencyCode, $reason, $referenceType, $referenceId, $adminUserId, $orgId, $metadata) {
            $this->lockBalance($userId, $currencyCode, $orgId);
            return $this->addCreditLocked($userId, $amountMinor, $currencyCode, $reason, $referenceType, $referenceId, $adminUserId, $orgId, $metadata);
        });
    }

    private function addCreditLocked(
        int $userId,
        int $amountMinor,
        string $currencyCode,
        string $reason,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $adminUserId = null,
        ?int $orgId = null,
        array $metadata = []
    ): CreditEntry {
        if ($amountMinor <= 0) {
            throw new ValidationException(['amount_minor' => 'Credit amount must be greater than zero.'], 'Invalid credit amount');
        }

        $currency = strtoupper(trim($currencyCode));
        $currentBalance = $this->getBalance($userId, $currency, $orgId);
        $newBalance = $currentBalance + $amountMinor;
        $entryNumber = $this->nextEntryNumber();
        $createdAt = date('Y-m-d H:i:s');

        $sql = sprintf(
            'INSERT INTO %s (entry_number, user_id, organization_id, currency_code, type, amount_minor, balance_after_minor, reason, reference_type, reference_id, admin_user_id, metadata_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $this->ledgerTable
        );

        $this->db->statement($sql, [
            $entryNumber,
            $userId,
            $orgId,
            $currency,
            CreditEntry::TYPE_CREDIT,
            $amountMinor,
            $newBalance,
            $reason,
            $referenceType,
            $referenceId,
            $adminUserId,
            json_encode($metadata),
            $createdAt,
        ]);

        $id = (int)$this->db->getPdo()->lastInsertId();

        return new CreditEntry(
            id: $id,
            entryNumber: $entryNumber,
            userId: $userId,
            organizationId: $orgId,
            currencyCode: $currency,
            type: CreditEntry::TYPE_CREDIT,
            amountMinor: $amountMinor,
            balanceAfterMinor: $newBalance,
            reason: $reason,
            referenceType: $referenceType,
            referenceId: $referenceId,
            adminUserId: $adminUserId,
            metadata: $metadata,
            createdAt: $createdAt
        );
    }

    /**
     * Deduct credit from user balance.
     *
     * @param array<string, mixed> $metadata
     */
    public function deductCredit(
        int $userId,
        int $amountMinor,
        string $currencyCode,
        string $reason,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $adminUserId = null,
        ?int $orgId = null,
        array $metadata = []
    ): CreditEntry
    {
        return $this->db->transaction(function () use ($userId, $amountMinor, $currencyCode, $reason, $referenceType, $referenceId, $adminUserId, $orgId, $metadata) {
            $this->lockBalance($userId, $currencyCode, $orgId);
            return $this->deductCreditLocked($userId, $amountMinor, $currencyCode, $reason, $referenceType, $referenceId, $adminUserId, $orgId, $metadata);
        });
    }

    private function deductCreditLocked(
        int $userId,
        int $amountMinor,
        string $currencyCode,
        string $reason,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $adminUserId = null,
        ?int $orgId = null,
        array $metadata = []
    ): CreditEntry {
        if ($amountMinor <= 0) {
            throw new ValidationException(['amount_minor' => 'Deduction amount must be greater than zero.'], 'Invalid deduction amount');
        }

        $currency = strtoupper(trim($currencyCode));
        $currentBalance = $this->getBalance($userId, $currency, $orgId);
        if ($currentBalance < $amountMinor) {
            throw new ValidationException(['amount_minor' => 'Insufficient credit balance.'], 'Insufficient credit');
        }

        $newBalance = $currentBalance - $amountMinor;
        $entryNumber = $this->nextEntryNumber();
        $createdAt = date('Y-m-d H:i:s');

        $sql = sprintf(
            'INSERT INTO %s (entry_number, user_id, organization_id, currency_code, type, amount_minor, balance_after_minor, reason, reference_type, reference_id, admin_user_id, metadata_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $this->ledgerTable
        );

        $this->db->statement($sql, [
            $entryNumber,
            $userId,
            $orgId,
            $currency,
            CreditEntry::TYPE_DEBIT,
            $amountMinor,
            $newBalance,
            $reason,
            $referenceType,
            $referenceId,
            $adminUserId,
            json_encode($metadata),
            $createdAt,
        ]);

        $id = (int)$this->db->getPdo()->lastInsertId();

        return new CreditEntry(
            id: $id,
            entryNumber: $entryNumber,
            userId: $userId,
            organizationId: $orgId,
            currencyCode: $currency,
            type: CreditEntry::TYPE_DEBIT,
            amountMinor: $amountMinor,
            balanceAfterMinor: $newBalance,
            reason: $reason,
            referenceType: $referenceType,
            referenceId: $referenceId,
            adminUserId: $adminUserId,
            metadata: $metadata,
            createdAt: $createdAt
        );
    }

    /**
     * Apply available credit balance towards an invoice.
     */
    public function applyCreditToInvoice(
        int $userId,
        int $invoiceId,
        int $amountMinor,
        ?int $adminUserId = null
    ): CreditEntry
    {
        $scope = $this->invoiceService?->findInvoiceById($invoiceId);
        if ($scope === null) { throw new RuntimeException('InvoiceService and a valid invoice are required.'); }
        return $this->db->transaction(function () use ($userId, $invoiceId, $amountMinor, $adminUserId, $scope) {
            $this->lockBalance($userId, $scope->getCurrencyCode(), $scope->getOrganizationId());
            return $this->applyCreditToInvoiceLocked($userId, $invoiceId, $amountMinor, $adminUserId);
        });
    }

    private function applyCreditToInvoiceLocked(
        int $userId,
        int $invoiceId,
        int $amountMinor,
        ?int $adminUserId = null
    ): CreditEntry {
        if ($this->invoiceService === null || $this->paymentService === null) {
            throw new RuntimeException('InvoiceService and PaymentService required to apply credit.');
        }

        $invoice = $this->invoiceService->findInvoiceById($invoiceId);
        if ($invoice === null) {
            throw new RuntimeException("Invoice {$invoiceId} not found.");
        }

        if ($invoice->getUserId() !== $userId) {
            throw new ValidationException(['invoice_id' => 'Invoice does not belong to this user.'], 'Unauthorized invoice access');
        }

        if ($amountMinor <= 0) {
            throw new ValidationException(['amount_minor' => 'Amount must be greater than zero.'], 'Invalid amount');
        }

        if ($amountMinor > $invoice->getBalanceDueMinor()) {
            throw new ValidationException(['amount_minor' => 'Credit application exceeds invoice balance due.'], 'Over-application not permitted');
        }

        $currency = $invoice->getCurrencyCode();
        $currentBalance = $this->getBalance($userId, $currency, $invoice->getOrganizationId());
        if ($currentBalance < $amountMinor) {
            throw new ValidationException(['amount_minor' => 'Insufficient credit balance in invoice currency.'], 'Insufficient balance');
        }

        // Deduct from credit ledger
        $entry = $this->deductCredit(
            userId: $userId,
            amountMinor: $amountMinor,
            currencyCode: $currency,
            reason: "Applied credit to invoice #{$invoice->getInvoiceNumber()}",
            referenceType: CreditEntry::REF_INVOICE,
            referenceId: $invoiceId,
            adminUserId: $adminUserId,
            orgId: $invoice->getOrganizationId()
        );

        // Record payment in payments domain
        if ($this->paymentService !== null) {
            $this->paymentService->recordPayment([
                'user_id' => $userId,
                'organization_id' => $invoice->getOrganizationId(),
                'invoice_id' => $invoiceId,
                'payment_method' => Payment::METHOD_CREDIT,
                'amount_minor' => $amountMinor,
                'currency_code' => $currency,
                'status' => Payment::STATUS_COMPLETED,
                'notes' => "Automatic settlement from credit ledger {$entry->getEntryNumber()}",
            ]);
        }

        return $entry;
    }

    /**
     * Refund a payment back into the customer's credit balance.
     */
    public function refundToCredit(
        int $paymentId,
        int $amountMinor,
        string $reason,
        ?int $adminUserId = null
    ): CreditEntry
    {
        $scope = $this->paymentService?->findPaymentById($paymentId);
        if ($scope === null) { throw new RuntimeException('PaymentService and a valid payment are required.'); }
        return $this->db->transaction(function () use ($paymentId, $amountMinor, $reason, $adminUserId, $scope) {
            $this->lockBalance($scope->getUserId(), $scope->getCurrencyCode(), $scope->getOrganizationId());
            return $this->refundToCreditLocked($paymentId, $amountMinor, $reason, $adminUserId);
        });
    }

    private function refundToCreditLocked(
        int $paymentId,
        int $amountMinor,
        string $reason,
        ?int $adminUserId = null
    ): CreditEntry {
        if ($this->paymentService === null) {
            throw new RuntimeException('PaymentService required for refund to credit.');
        }

        $payment = $this->paymentService->findPaymentById($paymentId);
        if ($payment === null) {
            throw new RuntimeException("Payment {$paymentId} not found.");
        }

        // Record refund in PaymentService
        $refund = $this->paymentService->recordRefund([
            'payment_id' => $paymentId,
            'amount_minor' => $amountMinor,
            'reason' => $reason,
            'refund_method' => Refund::METHOD_CREDIT,
        ]);

        // Deposit into credit ledger
        return $this->addCredit(
            userId: $payment->getUserId(),
            amountMinor: $amountMinor,
            currencyCode: $payment->getCurrencyCode(),
            reason: "Refund from payment #{$payment->getPaymentNumber()}: {$reason}",
            referenceType: CreditEntry::REF_REFUND,
            referenceId: $refund->getId(),
            adminUserId: $adminUserId,
            orgId: $payment->getOrganizationId()
        );
    }

    /**
     * Admin manual balance adjustment.
     */
    public function adjustBalance(
        int $userId,
        int $targetBalanceMinor,
        string $currencyCode,
        string $reason,
        ?int $adminUserId = null,
        ?int $orgId = null
    ): CreditEntry
    {
        return $this->db->transaction(function () use ($userId, $targetBalanceMinor, $currencyCode, $reason, $adminUserId, $orgId) {
            $this->lockBalance($userId, $currencyCode, $orgId);
            return $this->adjustBalanceLocked($userId, $targetBalanceMinor, $currencyCode, $reason, $adminUserId, $orgId);
        });
    }

    private function adjustBalanceLocked(
        int $userId,
        int $targetBalanceMinor,
        string $currencyCode,
        string $reason,
        ?int $adminUserId = null,
        ?int $orgId = null
    ): CreditEntry {
        if ($targetBalanceMinor < 0) {
            throw new ValidationException(['target_balance_minor' => 'Credit balance cannot be negative.'], 'Negative balance not permitted');
        }

        $currency = strtoupper(trim($currencyCode));
        $currentBalance = $this->getBalance($userId, $currency, $orgId);
        $diff = $targetBalanceMinor - $currentBalance;

        if ($diff > 0) {
            return $this->addCredit(
                userId: $userId,
                amountMinor: $diff,
                currencyCode: $currency,
                reason: $reason,
                referenceType: CreditEntry::REF_MANUAL_ADJUSTMENT,
                adminUserId: $adminUserId,
                orgId: $orgId
            );
        }

        if ($diff < 0) {
            return $this->deductCredit(
                userId: $userId,
                amountMinor: abs($diff),
                currencyCode: $currency,
                reason: $reason,
                referenceType: CreditEntry::REF_MANUAL_ADJUSTMENT,
                adminUserId: $adminUserId,
                orgId: $orgId
            );
        }

        // No change, return latest entry
        $latest = $this->listEntriesForUser($userId, $currency, $orgId);
        if (!empty($latest)) {
            return $latest[0];
        }

        // Return a baseline 0-balance entry
        return $this->addCredit(
            userId: $userId,
            amountMinor: 0,
            currencyCode: $currency,
            reason: $reason,
            referenceType: CreditEntry::REF_MANUAL_ADJUSTMENT,
            adminUserId: $adminUserId,
            orgId: $orgId
        );
    }

    public function findEntryById(int $id): ?CreditEntry
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE id = ?', $this->ledgerTable), [$id]);
        return $row ? $this->hydrateEntry($row) : null;
    }

    public function findEntryByNumber(string $entryNumber): ?CreditEntry
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE entry_number = ?', $this->ledgerTable), [$entryNumber]);
        return $row ? $this->hydrateEntry($row) : null;
    }

    /**
     * @return array<CreditEntry>
     */
    public function listEntriesForUser(int $userId, ?string $currencyCode = null, ?int $orgId = null): array
    {
        $sql = sprintf('SELECT * FROM %s WHERE user_id = ?', $this->ledgerTable);
        $params = [$userId];

        if ($currencyCode !== null) {
            $sql .= ' AND currency_code = ?';
            $params[] = strtoupper(trim($currencyCode));
        }

        if ($orgId !== null) {
            $sql .= ' AND organization_id = ?';
            $params[] = $orgId;
        } else {
            $sql .= ' AND organization_id IS NULL';
        }

        $sql .= ' ORDER BY id DESC';

        $rows = $this->db->select($sql, $params);
        return array_map([$this, 'hydrateEntry'], $rows);
    }

    public function nextEntryNumber(): string
    {
        $date = date('Ymd');

        $num = $this->db->nextSequence($this->sequencesTable, 'date_prefix', $date);
        $formattedNum = str_pad((string)$num, 6, '0', STR_PAD_LEFT);

        return "CR-{$date}-{$formattedNum}";
    }

    /**
     * @param array<string, mixed> $row
     */
    private function lockBalance(int $userId, string $currency, ?int $orgId): void
    {
        $values = [$userId, $orgId ?? 0, strtoupper(trim($currency))];
        $sql = 'INSERT INTO credit_balance_locks (user_id, organization_id, currency_code) VALUES (?, ?, ?)';
        $sql .= $this->db->getDriverName() === 'sqlite'
            ? ' ON CONFLICT(user_id, organization_id, currency_code) DO NOTHING'
            : ' ON DUPLICATE KEY UPDATE user_id = user_id';
        $this->db->statement($sql, $values);
        $this->db->selectOne('SELECT user_id FROM credit_balance_locks WHERE user_id = ? AND organization_id = ? AND currency_code = ?' . $this->db->forUpdate(), $values);
    }

    private function hydrateEntry(array $row): CreditEntry
    {
        $meta = !empty($row['metadata_json']) ? json_decode((string)$row['metadata_json'], true) : [];

        return new CreditEntry(
            id: (int)$row['id'],
            entryNumber: (string)$row['entry_number'],
            userId: (int)$row['user_id'],
            organizationId: $row['organization_id'] !== null ? (int)$row['organization_id'] : null,
            currencyCode: (string)$row['currency_code'],
            type: (string)$row['type'],
            amountMinor: (int)$row['amount_minor'],
            balanceAfterMinor: (int)$row['balance_after_minor'],
            reason: (string)$row['reason'],
            referenceType: $row['reference_type'] !== null ? (string)$row['reference_type'] : null,
            referenceId: $row['reference_id'] !== null ? (int)$row['reference_id'] : null,
            adminUserId: $row['admin_user_id'] !== null ? (int)$row['admin_user_id'] : null,
            metadata: is_array($meta) ? $meta : [],
            createdAt: (string)$row['created_at']
        );
    }
}
