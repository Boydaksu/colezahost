<?php

declare(strict_types=1);

namespace Coleza\Domain\Finance\Expenses;

use Coleza\Domain\Finance\Accounts\AccountTransaction;
use Coleza\Domain\Finance\Accounts\FinancialAccountService;
use Coleza\Domain\Pricing\Entities\PriceCycle;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use RuntimeException;

final class ExpenseService
{
    private string $categoriesTable = 'finance_categories';
    private string $vendorsTable = 'finance_vendors';
    private string $expensesTable = 'finance_expenses';
    private string $recurringExpensesTable = 'finance_recurring_expenses';
    private string $sequencesTable = 'finance_expense_sequences';

    public function __construct(
        private Connection $db,
        private ?FinancialAccountService $accountService = null
    ) {
    }

    public function ensureTables(): void
    {
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        // Categories
        $this->db->statement(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                code VARCHAR(50) NOT NULL UNIQUE,
                name VARCHAR(100) NOT NULL,
                type VARCHAR(20) NOT NULL DEFAULT "expense",
                description TEXT NULL,
                is_tax_deductible TINYINT(1) NOT NULL DEFAULT 1,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                metadata_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->categoriesTable,
            $autoInc
        ));

        // Vendors
        $this->db->statement(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                code VARCHAR(50) NOT NULL UNIQUE,
                name VARCHAR(100) NOT NULL,
                contact_email VARCHAR(100) NULL,
                contact_phone VARCHAR(50) NULL,
                website VARCHAR(255) NULL,
                tax_number VARCHAR(100) NULL,
                address TEXT NULL,
                country_code VARCHAR(3) NULL,
                notes TEXT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                metadata_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->vendorsTable,
            $autoInc
        ));

        // Expenses
        $this->db->statement(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                expense_number VARCHAR(50) NOT NULL UNIQUE,
                category_id INT NOT NULL,
                vendor_id INT NULL,
                financial_account_id INT NOT NULL,
                amount_minor INT NOT NULL,
                tax_amount_minor INT NOT NULL DEFAULT 0,
                total_minor INT NOT NULL,
                currency_code VARCHAR(3) NOT NULL,
                payment_date VARCHAR(20) NOT NULL,
                receipt_reference VARCHAR(100) NULL,
                receipt_file_url VARCHAR(255) NULL,
                notes TEXT NULL,
                is_recurring TINYINT(1) NOT NULL DEFAULT 0,
                recurring_expense_id INT NULL,
                metadata_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->expensesTable,
            $autoInc
        ));

        // Recurring Expenses
        $this->db->statement(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                title VARCHAR(100) NOT NULL,
                category_id INT NOT NULL,
                vendor_id INT NULL,
                financial_account_id INT NOT NULL,
                cycle VARCHAR(30) NOT NULL,
                amount_minor INT NOT NULL,
                tax_amount_minor INT NOT NULL DEFAULT 0,
                currency_code VARCHAR(3) NOT NULL,
                next_due_date VARCHAR(20) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                notes TEXT NULL,
                metadata_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->recurringExpensesTable,
            $autoInc
        ));

        // Sequences
        $this->db->statement(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                date_prefix VARCHAR(8) PRIMARY KEY,
                last_number INT NOT NULL DEFAULT 0
            )',
            $this->sequencesTable
        ));
    }

    // ==========================================
    // Categories
    // ==========================================

    /**
     * @param array<string, mixed> $data
     */
    public function createCategory(array $data): FinanceCategory
    {
        $code = strtoupper(trim((string)($data['code'] ?? '')));
        if ($code === '') {
            throw new ValidationException(['code' => 'Category code is required.'], 'Code missing');
        }

        $existing = $this->findCategoryByCode($code);
        if ($existing !== null) {
            throw new ValidationException(['code' => "Category code {$code} already exists."], 'Duplicate category code');
        }

        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException(['name' => 'Category name is required.'], 'Name missing');
        }

        $type = (string)($data['type'] ?? FinanceCategory::TYPE_EXPENSE);
        $desc = isset($data['description']) ? (string)$data['description'] : null;
        $taxDeductible = (bool)($data['is_tax_deductible'] ?? true);
        $isActive = (bool)($data['is_active'] ?? true);
        $meta = (array)($data['metadata'] ?? []);

        $sql = sprintf(
            'INSERT INTO %s (code, name, type, description, is_tax_deductible, is_active, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            $this->categoriesTable
        );

        $this->db->statement($sql, [
            $code,
            $name,
            $type,
            $desc,
            $taxDeductible ? 1 : 0,
            $isActive ? 1 : 0,
            json_encode($meta),
        ]);

        $id = (int)$this->db->getPdo()->lastInsertId();

        return new FinanceCategory(
            id: $id,
            code: $code,
            name: $name,
            type: $type,
            description: $desc,
            isTaxDeductible: $taxDeductible,
            isActive: $isActive,
            metadata: $meta,
            createdAt: date('Y-m-d H:i:s')
        );
    }

    public function findCategoryById(int $id): ?FinanceCategory
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE id = ?', $this->categoriesTable), [$id]);
        return $row ? $this->hydrateCategory($row) : null;
    }

    public function findCategoryByCode(string $code): ?FinanceCategory
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE code = ?', $this->categoriesTable), [strtoupper(trim($code))]);
        return $row ? $this->hydrateCategory($row) : null;
    }

    /**
     * @return array<FinanceCategory>
     */
    public function listCategories(?string $type = null): array
    {
        $sql = sprintf('SELECT * FROM %s', $this->categoriesTable);
        $params = [];
        if ($type !== null) {
            $sql .= ' WHERE type = ?';
            $params[] = $type;
        }
        $sql .= ' ORDER BY name ASC';
        $rows = $this->db->select($sql, $params);
        return array_map([$this, 'hydrateCategory'], $rows);
    }

    // ==========================================
    // Vendors
    // ==========================================

    /**
     * @param array<string, mixed> $data
     */
    public function createVendor(array $data): Vendor
    {
        $code = strtoupper(trim((string)($data['code'] ?? '')));
        if ($code === '') {
            throw new ValidationException(['code' => 'Vendor code is required.'], 'Code missing');
        }

        $existing = $this->findVendorByCode($code);
        if ($existing !== null) {
            throw new ValidationException(['code' => "Vendor code {$code} already exists."], 'Duplicate vendor code');
        }

        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException(['name' => 'Vendor name is required.'], 'Name missing');
        }

        $email = isset($data['contact_email']) ? (string)$data['contact_email'] : null;
        $phone = isset($data['contact_phone']) ? (string)$data['contact_phone'] : null;
        $website = isset($data['website']) ? (string)$data['website'] : null;
        $taxNumber = isset($data['tax_number']) ? (string)$data['tax_number'] : null;
        $address = isset($data['address']) ? (string)$data['address'] : null;
        $country = isset($data['country_code']) ? strtoupper(trim((string)$data['country_code'])) : null;
        $notes = isset($data['notes']) ? (string)$data['notes'] : null;
        $isActive = (bool)($data['is_active'] ?? true);
        $meta = (array)($data['metadata'] ?? []);

        $sql = sprintf(
            'INSERT INTO %s (code, name, contact_email, contact_phone, website, tax_number, address, country_code, notes, is_active, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $this->vendorsTable
        );

        $this->db->statement($sql, [
            $code,
            $name,
            $email,
            $phone,
            $website,
            $taxNumber,
            $address,
            $country,
            $notes,
            $isActive ? 1 : 0,
            json_encode($meta),
        ]);

        $id = (int)$this->db->getPdo()->lastInsertId();

        return new Vendor(
            id: $id,
            code: $code,
            name: $name,
            contactEmail: $email,
            contactPhone: $phone,
            website: $website,
            taxNumber: $taxNumber,
            address: $address,
            countryCode: $country,
            notes: $notes,
            isActive: $isActive,
            metadata: $meta,
            createdAt: date('Y-m-d H:i:s')
        );
    }

    public function findVendorById(int $id): ?Vendor
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE id = ?', $this->vendorsTable), [$id]);
        return $row ? $this->hydrateVendor($row) : null;
    }

    public function findVendorByCode(string $code): ?Vendor
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE code = ?', $this->vendorsTable), [strtoupper(trim($code))]);
        return $row ? $this->hydrateVendor($row) : null;
    }

    /**
     * @return array<Vendor>
     */
    public function listVendors(bool $onlyActive = true): array
    {
        $sql = sprintf('SELECT * FROM %s', $this->vendorsTable);
        if ($onlyActive) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY name ASC';
        $rows = $this->db->select($sql);
        return array_map([$this, 'hydrateVendor'], $rows);
    }

    // ==========================================
    // Expenses
    // ==========================================

    /**
     * Record an expenditure and optionally debit the financial account ledger.
     *
     * @param array<string, mixed> $data
     */
    public function recordExpense(array $data, bool $debitAccount = true): Expense
    {
        $categoryId = (int)($data['category_id'] ?? 0);
        $category = $this->findCategoryById($categoryId);
        if ($category === null) {
            throw new ValidationException(['category_id' => 'Valid category is required.'], 'Invalid category');
        }

        $accountId = (int)($data['financial_account_id'] ?? 0);
        $amountMinor = (int)($data['amount_minor'] ?? 0);
        if ($amountMinor <= 0) {
            throw new ValidationException(['amount_minor' => 'Expense amount must be greater than zero.'], 'Invalid amount');
        }

        $taxMinor = max(0, (int)($data['tax_amount_minor'] ?? 0));
        $totalMinor = $amountMinor + $taxMinor;
        $currency = strtoupper(trim((string)($data['currency_code'] ?? 'USD')));
        $vendorId = isset($data['vendor_id']) && $data['vendor_id'] !== null ? (int)$data['vendor_id'] : null;
        $paymentDate = (string)($data['payment_date'] ?? date('Y-m-d'));
        $receiptRef = isset($data['receipt_reference']) ? (string)$data['receipt_reference'] : null;
        $receiptUrl = isset($data['receipt_file_url']) ? (string)$data['receipt_file_url'] : null;
        $notes = isset($data['notes']) ? (string)$data['notes'] : null;
        $isRecurring = (bool)($data['is_recurring'] ?? false);
        $recId = isset($data['recurring_expense_id']) && $data['recurring_expense_id'] !== null ? (int)$data['recurring_expense_id'] : null;
        $meta = (array)($data['metadata'] ?? []);

        $expenseNumber = $this->nextExpenseNumber();

        // If account service is provided and debit requested, verify account and debit
        if ($debitAccount && $this->accountService !== null && $accountId > 0) {
            $this->accountService->recordTransaction([
                'account_id' => $accountId,
                'type' => AccountTransaction::TYPE_DEBIT,
                'amount_minor' => $totalMinor,
                'currency_code' => $currency,
                'source' => AccountTransaction::SOURCE_EXPENSE,
                'description' => "Expense #{$expenseNumber} ({$category->getName()})",
            ]);
        }

        $sql = sprintf(
            'INSERT INTO %s (expense_number, category_id, vendor_id, financial_account_id, amount_minor, tax_amount_minor, total_minor, currency_code, payment_date, receipt_reference, receipt_file_url, notes, is_recurring, recurring_expense_id, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $this->expensesTable
        );

        $this->db->statement($sql, [
            $expenseNumber,
            $categoryId,
            $vendorId,
            $accountId,
            $amountMinor,
            $taxMinor,
            $totalMinor,
            $currency,
            $paymentDate,
            $receiptRef,
            $receiptUrl,
            $notes,
            $isRecurring ? 1 : 0,
            $recId,
            json_encode($meta),
        ]);

        $id = (int)$this->db->getPdo()->lastInsertId();

        return new Expense(
            id: $id,
            expenseNumber: $expenseNumber,
            categoryId: $categoryId,
            vendorId: $vendorId,
            financialAccountId: $accountId,
            amountMinor: $amountMinor,
            taxAmountMinor: $taxMinor,
            totalMinor: $totalMinor,
            currencyCode: $currency,
            paymentDate: $paymentDate,
            receiptReference: $receiptRef,
            receiptFileUrl: $receiptUrl,
            notes: $notes,
            isRecurring: $isRecurring,
            recurringExpenseId: $recId,
            metadata: $meta,
            createdAt: date('Y-m-d H:i:s')
        );
    }

    public function findExpenseById(int $id): ?Expense
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE id = ?', $this->expensesTable), [$id]);
        return $row ? $this->hydrateExpense($row) : null;
    }

    public function findExpenseByNumber(string $expenseNumber): ?Expense
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE expense_number = ?', $this->expensesTable), [$expenseNumber]);
        return $row ? $this->hydrateExpense($row) : null;
    }

    /**
     * @return array<Expense>
     */
    public function listExpenses(?int $categoryId = null, ?int $vendorId = null): array
    {
        $sql = sprintf('SELECT * FROM %s', $this->expensesTable);
        $conditions = [];
        $params = [];

        if ($categoryId !== null) {
            $conditions[] = 'category_id = ?';
            $params[] = $categoryId;
        }

        if ($vendorId !== null) {
            $conditions[] = 'vendor_id = ?';
            $params[] = $vendorId;
        }

        if (!empty($conditions)) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY payment_date DESC, id DESC';
        $rows = $this->db->select($sql, $params);
        return array_map([$this, 'hydrateExpense'], $rows);
    }

    // ==========================================
    // Recurring Expenses
    // ==========================================

    /**
     * @param array<string, mixed> $data
     */
    public function createRecurringExpense(array $data): RecurringExpense
    {
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            throw new ValidationException(['title' => 'Title is required.'], 'Title missing');
        }

        $categoryId = (int)($data['category_id'] ?? 0);
        $category = $this->findCategoryById($categoryId);
        if ($category === null) {
            throw new ValidationException(['category_id' => 'Valid category is required.'], 'Invalid category');
        }

        $vendorId = isset($data['vendor_id']) && $data['vendor_id'] !== null ? (int)$data['vendor_id'] : null;
        $accountId = (int)($data['financial_account_id'] ?? 0);
        $cycle = (string)($data['cycle'] ?? PriceCycle::MONTHLY);
        $amountMinor = (int)($data['amount_minor'] ?? 0);
        if ($amountMinor <= 0) {
            throw new ValidationException(['amount_minor' => 'Amount must be greater than zero.'], 'Invalid amount');
        }

        $taxMinor = max(0, (int)($data['tax_amount_minor'] ?? 0));
        $currency = strtoupper(trim((string)($data['currency_code'] ?? 'USD')));
        $nextDueDate = (string)($data['next_due_date'] ?? date('Y-m-d'));
        $isActive = (bool)($data['is_active'] ?? true);
        $notes = isset($data['notes']) ? (string)$data['notes'] : null;
        $meta = (array)($data['metadata'] ?? []);

        $sql = sprintf(
            'INSERT INTO %s (title, category_id, vendor_id, financial_account_id, cycle, amount_minor, tax_amount_minor, currency_code, next_due_date, is_active, notes, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $this->recurringExpensesTable
        );

        $this->db->statement($sql, [
            $title,
            $categoryId,
            $vendorId,
            $accountId,
            $cycle,
            $amountMinor,
            $taxMinor,
            $currency,
            $nextDueDate,
            $isActive ? 1 : 0,
            $notes,
            json_encode($meta),
        ]);

        $id = (int)$this->db->getPdo()->lastInsertId();

        return new RecurringExpense(
            id: $id,
            title: $title,
            categoryId: $categoryId,
            vendorId: $vendorId,
            financialAccountId: $accountId,
            cycle: $cycle,
            amountMinor: $amountMinor,
            taxAmountMinor: $taxMinor,
            currencyCode: $currency,
            nextDueDate: $nextDueDate,
            isActive: $isActive,
            notes: $notes,
            metadata: $meta,
            createdAt: date('Y-m-d H:i:s')
        );
    }

    public function findRecurringExpenseById(int $id): ?RecurringExpense
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE id = ?', $this->recurringExpensesTable), [$id]);
        return $row ? $this->hydrateRecurringExpense($row) : null;
    }

    /**
     * @return array<RecurringExpense>
     */
    public function listRecurringExpenses(bool $onlyActive = true): array
    {
        $sql = sprintf('SELECT * FROM %s', $this->recurringExpensesTable);
        if ($onlyActive) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY next_due_date ASC';
        $rows = $this->db->select($sql);
        return array_map([$this, 'hydrateRecurringExpense'], $rows);
    }

    /**
     * Scan active recurring expenses due as of given date, generate actual expense records, and advance next due dates.
     *
     * @return array<Expense>
     */
    public function processDueRecurringExpenses(string $asOfDate): array
    {
        $sql = sprintf(
            'SELECT * FROM %s WHERE is_active = 1 AND next_due_date <= ? ORDER BY next_due_date ASC',
            $this->recurringExpensesTable
        );
        $rows = $this->db->select($sql, [$asOfDate]);

        $generated = [];
        foreach ($rows as $row) {
            $rec = $this->hydrateRecurringExpense($row);

            $expense = $this->recordExpense([
                'category_id' => $rec->getCategoryId(),
                'vendor_id' => $rec->getVendorId(),
                'financial_account_id' => $rec->getFinancialAccountId(),
                'amount_minor' => $rec->getAmountMinor(),
                'tax_amount_minor' => $rec->getTaxAmountMinor(),
                'currency_code' => $rec->getCurrencyCode(),
                'payment_date' => $asOfDate,
                'notes' => "Automated recurring cost: {$rec->getTitle()}",
                'is_recurring' => true,
                'recurring_expense_id' => $rec->getId(),
            ]);

            $generated[] = $expense;

            // Advance next due date
            $nextDue = $rec->computeNextDueDate();
            $this->db->statement(
                sprintf('UPDATE %s SET next_due_date = ? WHERE id = ?', $this->recurringExpensesTable),
                [$nextDue, $rec->getId()]
            );
        }

        return $generated;
    }

    public function nextExpenseNumber(): string
    {
        $date = date('Ymd');

        $this->db->statement(
            sprintf(
                'INSERT INTO %s (date_prefix, last_number) VALUES (?, 1)
                 ON CONFLICT(date_prefix) DO UPDATE SET last_number = last_number + 1',
                $this->sequencesTable
            ),
            [$date]
        );

        $row = $this->db->selectOne(
            sprintf('SELECT last_number FROM %s WHERE date_prefix = ?', $this->sequencesTable),
            [$date]
        );

        $num = $row ? (int)$row['last_number'] : 1;
        $formattedNum = str_pad((string)$num, 6, '0', STR_PAD_LEFT);

        return "EXP-{$date}-{$formattedNum}";
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateCategory(array $row): FinanceCategory
    {
        $meta = !empty($row['metadata_json']) ? json_decode((string)$row['metadata_json'], true) : [];
        return new FinanceCategory(
            id: (int)$row['id'],
            code: (string)$row['code'],
            name: (string)$row['name'],
            type: (string)$row['type'],
            description: $row['description'] !== null ? (string)$row['description'] : null,
            isTaxDeductible: (bool)$row['is_tax_deductible'],
            isActive: (bool)$row['is_active'],
            metadata: is_array($meta) ? $meta : [],
            createdAt: (string)$row['created_at']
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateVendor(array $row): Vendor
    {
        $meta = !empty($row['metadata_json']) ? json_decode((string)$row['metadata_json'], true) : [];
        return new Vendor(
            id: (int)$row['id'],
            code: (string)$row['code'],
            name: (string)$row['name'],
            contactEmail: $row['contact_email'] !== null ? (string)$row['contact_email'] : null,
            contactPhone: $row['contact_phone'] !== null ? (string)$row['contact_phone'] : null,
            website: $row['website'] !== null ? (string)$row['website'] : null,
            taxNumber: $row['tax_number'] !== null ? (string)$row['tax_number'] : null,
            address: $row['address'] !== null ? (string)$row['address'] : null,
            countryCode: $row['country_code'] !== null ? (string)$row['country_code'] : null,
            notes: $row['notes'] !== null ? (string)$row['notes'] : null,
            isActive: (bool)$row['is_active'],
            metadata: is_array($meta) ? $meta : [],
            createdAt: (string)$row['created_at']
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateExpense(array $row): Expense
    {
        $meta = !empty($row['metadata_json']) ? json_decode((string)$row['metadata_json'], true) : [];
        return new Expense(
            id: (int)$row['id'],
            expenseNumber: (string)$row['expense_number'],
            categoryId: (int)$row['category_id'],
            vendorId: $row['vendor_id'] !== null ? (int)$row['vendor_id'] : null,
            financialAccountId: (int)$row['financial_account_id'],
            amountMinor: (int)$row['amount_minor'],
            taxAmountMinor: (int)$row['tax_amount_minor'],
            totalMinor: (int)$row['total_minor'],
            currencyCode: (string)$row['currency_code'],
            paymentDate: (string)$row['payment_date'],
            receiptReference: $row['receipt_reference'] !== null ? (string)$row['receipt_reference'] : null,
            receiptFileUrl: $row['receipt_file_url'] !== null ? (string)$row['receipt_file_url'] : null,
            notes: $row['notes'] !== null ? (string)$row['notes'] : null,
            isRecurring: (bool)$row['is_recurring'],
            recurringExpenseId: $row['recurring_expense_id'] !== null ? (int)$row['recurring_expense_id'] : null,
            metadata: is_array($meta) ? $meta : [],
            createdAt: (string)$row['created_at']
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateRecurringExpense(array $row): RecurringExpense
    {
        $meta = !empty($row['metadata_json']) ? json_decode((string)$row['metadata_json'], true) : [];
        return new RecurringExpense(
            id: (int)$row['id'],
            title: (string)$row['title'],
            categoryId: (int)$row['category_id'],
            vendorId: $row['vendor_id'] !== null ? (int)$row['vendor_id'] : null,
            financialAccountId: (int)$row['financial_account_id'],
            cycle: (string)$row['cycle'],
            amountMinor: (int)$row['amount_minor'],
            taxAmountMinor: (int)$row['tax_amount_minor'],
            currencyCode: (string)$row['currency_code'],
            nextDueDate: (string)$row['next_due_date'],
            isActive: (bool)$row['is_active'],
            notes: $row['notes'] !== null ? (string)$row['notes'] : null,
            metadata: is_array($meta) ? $meta : [],
            createdAt: (string)$row['created_at']
        );
    }
}
