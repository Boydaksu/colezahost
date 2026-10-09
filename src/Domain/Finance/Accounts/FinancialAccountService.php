<?php

declare(strict_types=1);

namespace Coleza\Domain\Finance\Accounts;

use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use RuntimeException;

final class FinancialAccountService
{
    private string $accountsTable = 'financial_accounts';
    private string $transactionsTable = 'financial_account_transactions';
    private string $sequencesTable = 'financial_account_sequences';

    public function __construct(
        private Connection $db
    ) {
    }

    public function ensureTables(): void
    {
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        // Financial accounts table
        $sqlAccounts = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                code VARCHAR(50) NOT NULL UNIQUE,
                name VARCHAR(100) NOT NULL,
                account_type VARCHAR(30) NOT NULL,
                currency_code VARCHAR(3) NOT NULL,
                account_number VARCHAR(100) NULL,
                bank_name VARCHAR(100) NULL,
                branch_name VARCHAR(100) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                metadata_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->accountsTable,
            $autoInc
        );
        $this->db->statement($sqlAccounts);

        // Financial account transactions (immutable ledger)
        $sqlTransactions = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                entry_number VARCHAR(50) NOT NULL UNIQUE,
                account_id INT NOT NULL,
                type VARCHAR(20) NOT NULL,
                amount_minor INT NOT NULL,
                balance_after_minor INT NOT NULL,
                currency_code VARCHAR(3) NOT NULL,
                source VARCHAR(50) NOT NULL,
                description VARCHAR(255) NOT NULL,
                reference_id INT NULL,
                transaction_date VARCHAR(30) NOT NULL,
                metadata_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->transactionsTable,
            $autoInc
        );
        $this->db->statement($sqlTransactions);

        // Sequence generator
        $sqlSeq = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                date_prefix VARCHAR(8) PRIMARY KEY,
                last_number INT NOT NULL DEFAULT 0
            )',
            $this->sequencesTable
        );
        $this->db->statement($sqlSeq);
    }

    /**
     * Create a new financial account.
     *
     * @param array<string, mixed> $data
     */
    public function createAccount(array $data): FinancialAccount
    {
        $code = strtoupper(trim((string)($data['code'] ?? '')));
        if ($code === '') {
            throw new ValidationException(['code' => 'Account code is required.'], 'Code missing');
        }

        $existing = $this->findAccountByCode($code);
        if ($existing !== null) {
            throw new ValidationException(['code' => "Account code {$code} already exists."], 'Duplicate account code');
        }

        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException(['name' => 'Account name is required.'], 'Name missing');
        }

        $type = (string)($data['account_type'] ?? FinancialAccount::TYPE_BANK);
        $currency = strtoupper(trim((string)($data['currency_code'] ?? 'USD')));
        $accountNum = isset($data['account_number']) ? (string)$data['account_number'] : null;
        $bankName = isset($data['bank_name']) ? (string)$data['bank_name'] : null;
        $branchName = isset($data['branch_name']) ? (string)$data['branch_name'] : null;
        $isActive = (bool)($data['is_active'] ?? true);
        $isDefault = (bool)($data['is_default'] ?? false);
        $metadata = (array)($data['metadata'] ?? []);

        // If setting as default, unset other defaults for this currency and type
        if ($isDefault) {
            $this->db->statement(
                sprintf('UPDATE %s SET is_default = 0 WHERE currency_code = ? AND account_type = ?', $this->accountsTable),
                [$currency, $type]
            );
        }

        $sql = sprintf(
            'INSERT INTO %s (code, name, account_type, currency_code, account_number, bank_name, branch_name, is_active, is_default, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $this->accountsTable
        );

        $this->db->statement($sql, [
            $code,
            $name,
            $type,
            $currency,
            $accountNum,
            $bankName,
            $branchName,
            $isActive ? 1 : 0,
            $isDefault ? 1 : 0,
            json_encode($metadata),
        ]);

        $id = (int)$this->db->getPdo()->lastInsertId();

        return new FinancialAccount(
            id: $id,
            code: $code,
            name: $name,
            accountType: $type,
            currencyCode: $currency,
            accountNumber: $accountNum,
            bankName: $bankName,
            branchName: $branchName,
            isActive: $isActive,
            isDefault: $isDefault,
            metadata: $metadata,
            createdAt: date('Y-m-d H:i:s')
        );
    }

    /**
     * Get real-time derived balance from the immutable ledger.
     */
    public function getBalance(int $accountId): int
    {
        $sql = sprintf(
            'SELECT balance_after_minor FROM %s WHERE account_id = ? ORDER BY id DESC LIMIT 1',
            $this->transactionsTable
        );
        $row = $this->db->selectOne($sql, [$accountId]);
        return $row ? (int)$row['balance_after_minor'] : 0;
    }

    /**
     * Record an immutable transaction entry on an account.
     *
     * @param array<string, mixed> $data
     */
    public function recordTransaction(array $data): AccountTransaction
    {
        $accountId = (int)($data['account_id'] ?? 0);
        $account = $this->findAccountById($accountId);
        if ($account === null) {
            throw new RuntimeException("Financial account {$accountId} not found.");
        }

        if (!$account->isActive()) {
            throw new ValidationException(['account' => 'Cannot record transaction on an inactive account.'], 'Account inactive');
        }

        $amountMinor = (int)($data['amount_minor'] ?? 0);
        if ($amountMinor <= 0) {
            throw new ValidationException(['amount_minor' => 'Amount must be greater than zero.'], 'Invalid amount');
        }

        $type = (string)($data['type'] ?? AccountTransaction::TYPE_CREDIT);
        if ($type !== AccountTransaction::TYPE_CREDIT && $type !== AccountTransaction::TYPE_DEBIT) {
            throw new ValidationException(['type' => 'Transaction type must be credit or debit.'], 'Invalid type');
        }

        $currency = strtoupper(trim((string)($data['currency_code'] ?? $account->getCurrencyCode())));
        if ($currency !== $account->getCurrencyCode()) {
            throw new ValidationException(['currency_code' => 'Transaction currency does not match account currency.'], 'Currency mismatch');
        }

        $currentBalance = $this->getBalance($accountId);
        $allowOverdraft = (bool)($data['allow_overdraft'] ?? false);

        if ($type === AccountTransaction::TYPE_DEBIT) {
            if (!$allowOverdraft && $currentBalance < $amountMinor) {
                throw new ValidationException(['amount_minor' => 'Transaction would cause negative balance (overdraft forbidden).'], 'Insufficient account balance');
            }
            $newBalance = $currentBalance - $amountMinor;
        } else {
            $newBalance = $currentBalance + $amountMinor;
        }

        $source = (string)($data['source'] ?? AccountTransaction::SOURCE_ADJUSTMENT);
        $description = trim((string)($data['description'] ?? 'Transaction'));
        $refId = isset($data['reference_id']) && $data['reference_id'] !== null ? (int)$data['reference_id'] : null;
        $txnDate = (string)($data['transaction_date'] ?? date('Y-m-d H:i:s'));
        $metadata = (array)($data['metadata'] ?? []);
        $entryNumber = $this->nextTransactionNumber();

        $sql = sprintf(
            'INSERT INTO %s (entry_number, account_id, type, amount_minor, balance_after_minor, currency_code, source, description, reference_id, transaction_date, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $this->transactionsTable
        );

        $this->db->statement($sql, [
            $entryNumber,
            $accountId,
            $type,
            $amountMinor,
            $newBalance,
            $currency,
            $source,
            $description,
            $refId,
            $txnDate,
            json_encode($metadata),
        ]);

        $id = (int)$this->db->getPdo()->lastInsertId();

        return new AccountTransaction(
            id: $id,
            entryNumber: $entryNumber,
            accountId: $accountId,
            type: $type,
            amountMinor: $amountMinor,
            balanceAfterMinor: $newBalance,
            currencyCode: $currency,
            source: $source,
            description: $description,
            referenceId: $refId,
            transactionDate: $txnDate,
            metadata: $metadata,
            createdAt: date('Y-m-d H:i:s')
        );
    }

    /**
     * Inter-account transfer between two accounts.
     *
     * @return array{outflow: AccountTransaction, inflow: AccountTransaction}
     */
    public function transferBetweenAccounts(
        int $fromAccountId,
        int $toAccountId,
        int $amountMinor,
        ?int $targetAmountMinor = null,
        string $description = 'Inter-account transfer'
    ): array {
        if ($fromAccountId === $toAccountId) {
            throw new ValidationException(['accounts' => 'Source and destination accounts must be distinct.'], 'Identical transfer accounts');
        }

        $fromAcc = $this->findAccountById($fromAccountId);
        $toAcc = $this->findAccountById($toAccountId);
        if ($fromAcc === null || $toAcc === null) {
            throw new RuntimeException('Both source and destination accounts must exist.');
        }

        $inflowAmount = $targetAmountMinor ?? $amountMinor;

        // 1. Debit outflow from source
        $outflow = $this->recordTransaction([
            'account_id' => $fromAccountId,
            'type' => AccountTransaction::TYPE_DEBIT,
            'amount_minor' => $amountMinor,
            'currency_code' => $fromAcc->getCurrencyCode(),
            'source' => AccountTransaction::SOURCE_TRANSFER,
            'description' => "Transfer to {$toAcc->getName()} ({$toAcc->getCode()}): {$description}",
            'reference_id' => $toAccountId,
        ]);

        // 2. Credit inflow to destination
        $inflow = $this->recordTransaction([
            'account_id' => $toAccountId,
            'type' => AccountTransaction::TYPE_CREDIT,
            'amount_minor' => $inflowAmount,
            'currency_code' => $toAcc->getCurrencyCode(),
            'source' => AccountTransaction::SOURCE_TRANSFER,
            'description' => "Transfer from {$fromAcc->getName()} ({$fromAcc->getCode()}): {$description}",
            'reference_id' => $fromAccountId,
        ]);

        return [
            'outflow' => $outflow,
            'inflow' => $inflow,
        ];
    }

    /**
     * Generate an audited ledger view for an account with opening, flows, and closing balances.
     *
     * @return array{account: FinancialAccount, opening_balance_minor: int, total_credit_minor: int, total_debit_minor: int, closing_balance_minor: int, transactions: array<AccountTransaction>}
     */
    public function getLedgerView(int $accountId, ?string $startDate = null, ?string $endDate = null): array
    {
        $account = $this->findAccountById($accountId);
        if ($account === null) {
            throw new RuntimeException("Financial account {$accountId} not found.");
        }

        $sql = sprintf('SELECT * FROM %s WHERE account_id = ?', $this->transactionsTable);
        $params = [$accountId];

        if ($startDate !== null) {
            $sql .= ' AND transaction_date >= ?';
            $params[] = $startDate;
        }

        if ($endDate !== null) {
            $sql .= ' AND transaction_date <= ?';
            $params[] = $endDate;
        }

        $sql .= ' ORDER BY id ASC';
        $rows = $this->db->select($sql, $params);

        $transactions = array_map([$this, 'hydrateTransaction'], $rows);

        $totalCredit = 0;
        $totalDebit = 0;
        foreach ($transactions as $txn) {
            if ($txn->isCredit()) {
                $totalCredit += $txn->getAmountMinor();
            } else {
                $totalDebit += $txn->getAmountMinor();
            }
        }

        $closingBalance = $this->getBalance($accountId);
        // Calculate opening balance before first transaction in this view
        $openingBalance = $closingBalance - $totalCredit + $totalDebit;

        return [
            'account' => $account,
            'opening_balance_minor' => $openingBalance,
            'total_credit_minor' => $totalCredit,
            'total_debit_minor' => $totalDebit,
            'closing_balance_minor' => $closingBalance,
            'transactions' => $transactions,
        ];
    }

    public function findAccountById(int $id): ?FinancialAccount
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE id = ?', $this->accountsTable), [$id]);
        return $row ? $this->hydrateAccount($row) : null;
    }

    public function findAccountByCode(string $code): ?FinancialAccount
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE code = ?', $this->accountsTable), [strtoupper(trim($code))]);
        return $row ? $this->hydrateAccount($row) : null;
    }

    /**
     * @return array<FinancialAccount>
     */
    public function listAccounts(bool $onlyActive = false, ?string $currencyCode = null): array
    {
        $sql = sprintf('SELECT * FROM %s', $this->accountsTable);
        $conditions = [];
        $params = [];

        if ($onlyActive) {
            $conditions[] = 'is_active = 1';
        }

        if ($currencyCode !== null) {
            $conditions[] = 'currency_code = ?';
            $params[] = strtoupper(trim($currencyCode));
        }

        if (!empty($conditions)) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY is_default DESC, name ASC';

        $rows = $this->db->select($sql, $params);
        return array_map([$this, 'hydrateAccount'], $rows);
    }

    public function getDefaultAccount(string $currencyCode, ?string $type = null): ?FinancialAccount
    {
        $sql = sprintf('SELECT * FROM %s WHERE currency_code = ? AND is_default = 1', $this->accountsTable);
        $params = [strtoupper(trim($currencyCode))];

        if ($type !== null) {
            $sql .= ' AND account_type = ?';
            $params[] = $type;
        }

        $sql .= ' LIMIT 1';
        $row = $this->db->selectOne($sql, $params);

        if ($row === null) {
            // Fallback to any active account of that currency
            $fbSql = sprintf('SELECT * FROM %s WHERE currency_code = ? AND is_active = 1 LIMIT 1', $this->accountsTable);
            $row = $this->db->selectOne($fbSql, [strtoupper(trim($currencyCode))]);
        }

        return $row ? $this->hydrateAccount($row) : null;
    }

    public function nextTransactionNumber(): string
    {
        $date = date('Ymd');

        $num = $this->db->nextSequence($this->sequencesTable, 'date_prefix', $date);
        $formattedNum = str_pad((string)$num, 6, '0', STR_PAD_LEFT);

        return "TXN-{$date}-{$formattedNum}";
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateAccount(array $row): FinancialAccount
    {
        $meta = !empty($row['metadata_json']) ? json_decode((string)$row['metadata_json'], true) : [];

        return new FinancialAccount(
            id: (int)$row['id'],
            code: (string)$row['code'],
            name: (string)$row['name'],
            accountType: (string)$row['account_type'],
            currencyCode: (string)$row['currency_code'],
            accountNumber: $row['account_number'] !== null ? (string)$row['account_number'] : null,
            bankName: $row['bank_name'] !== null ? (string)$row['bank_name'] : null,
            branchName: $row['branch_name'] !== null ? (string)$row['branch_name'] : null,
            isActive: (bool)$row['is_active'],
            isDefault: (bool)$row['is_default'],
            metadata: is_array($meta) ? $meta : [],
            createdAt: (string)$row['created_at']
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateTransaction(array $row): AccountTransaction
    {
        $meta = !empty($row['metadata_json']) ? json_decode((string)$row['metadata_json'], true) : [];

        return new AccountTransaction(
            id: (int)$row['id'],
            entryNumber: (string)$row['entry_number'],
            accountId: (int)$row['account_id'],
            type: (string)$row['type'],
            amountMinor: (int)$row['amount_minor'],
            balanceAfterMinor: (int)$row['balance_after_minor'],
            currencyCode: (string)$row['currency_code'],
            source: (string)$row['source'],
            description: (string)$row['description'],
            referenceId: $row['reference_id'] !== null ? (int)$row['reference_id'] : null,
            transactionDate: (string)$row['transaction_date'],
            metadata: is_array($meta) ? $meta : [],
            createdAt: (string)$row['created_at']
        );
    }
}
