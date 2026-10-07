<?php

declare(strict_types=1);

namespace Tests\Unit\Finance;

use Coleza\Domain\Finance\Accounts\AccountTransaction;
use Coleza\Domain\Finance\Accounts\FinancialAccount;
use Coleza\Domain\Finance\Accounts\FinancialAccountService;
use Coleza\Domain\Finance\Expenses\Expense;
use Coleza\Domain\Finance\Expenses\ExpenseService;
use Coleza\Domain\Finance\Expenses\FinanceCategory;
use Coleza\Domain\Finance\Expenses\RecurringExpense;
use Coleza\Domain\Finance\Expenses\Vendor;
use Coleza\Domain\Pricing\Entities\PriceCycle;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class ExpenseServiceTest extends TestCase
{
    private Connection $db;
    private FinancialAccountService $accountService;
    private ExpenseService $expenseService;
    private FinancialAccount $bankAccount;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->accountService = new FinancialAccountService($this->db);
        $this->accountService->ensureTables();

        $this->expenseService = new ExpenseService($this->db, $this->accountService);
        $this->expenseService->ensureTables();

        // Seed a bank account with 10,000.00 EUR (1000000 minor units)
        $this->bankAccount = $this->accountService->createAccount([
            'code' => 'BANK-EUR-OPS',
            'name' => 'EUR Operating Account',
            'account_type' => FinancialAccount::TYPE_BANK,
            'currency_code' => 'EUR',
            'is_default' => true,
        ]);
        $this->accountService->recordTransaction([
            'account_id' => $this->bankAccount->getId(),
            'type' => AccountTransaction::TYPE_CREDIT,
            'amount_minor' => 1000000,
            'source' => AccountTransaction::SOURCE_ADJUSTMENT,
            'description' => 'Initial operating capital',
        ]);
    }

    public function testCreateCategoriesAndVendors(): void
    {
        // 1. Categories
        $cat1 = $this->expenseService->createCategory([
            'code' => 'SERVER_INFRA',
            'name' => 'Server & Datacenter Infrastructure',
            'type' => FinanceCategory::TYPE_EXPENSE,
            'description' => 'Dedicated servers and colocation',
        ]);

        $this->assertNotNull($cat1->getId());
        $this->assertSame('SERVER_INFRA', $cat1->getCode());
        $this->assertTrue($cat1->isExpense());
        $this->assertTrue($cat1->isTaxDeductible());

        $cat2 = $this->expenseService->createCategory([
            'code' => 'HOSTING_REV',
            'name' => 'Hosting Revenue',
            'type' => FinanceCategory::TYPE_INCOME,
        ]);
        $this->assertTrue($cat2->isIncome());

        $this->assertCount(2, $this->expenseService->listCategories());
        $this->assertCount(1, $this->expenseService->listCategories(FinanceCategory::TYPE_EXPENSE));

        // 2. Vendors
        $vendor = $this->expenseService->createVendor([
            'code' => 'HETZNER',
            'name' => 'Hetzner Online GmbH',
            'contact_email' => 'support@hetzner.com',
            'website' => 'https://hetzner.com',
            'country_code' => 'DE',
            'tax_number' => 'DE812873733',
        ]);

        $this->assertNotNull($vendor->getId());
        $this->assertSame('HETZNER', $vendor->getCode());
        $this->assertSame('Hetzner Online GmbH', $vendor->getName());
        $this->assertSame('DE', $vendor->getCountryCode());
        $this->assertTrue($vendor->isActive());

        // Duplicate code throws ValidationException
        $this->expectException(ValidationException::class);
        $this->expenseService->createVendor([
            'code' => 'HETZNER',
            'name' => 'Duplicate Hetzner',
        ]);
    }

    public function testRecordExpenseWithAutomaticAccountDebit(): void
    {
        $category = $this->expenseService->createCategory([
            'code' => 'HARDWARE',
            'name' => 'Hardware Purchase',
        ]);
        $vendor = $this->expenseService->createVendor([
            'code' => 'DELL',
            'name' => 'Dell Technologies',
        ]);

        $initialBalance = $this->accountService->getBalance($this->bankAccount->getId());
        $this->assertSame(1000000, $initialBalance);

        // Expense: 200.00 EUR net + 38.00 EUR VAT = 238.00 EUR total (23800 minor)
        $expense = $this->expenseService->recordExpense([
            'category_id' => $category->getId(),
            'vendor_id' => $vendor->getId(),
            'financial_account_id' => $this->bankAccount->getId(),
            'amount_minor' => 20000,
            'tax_amount_minor' => 3800,
            'currency_code' => 'EUR',
            'payment_date' => '2026-10-05',
            'receipt_reference' => 'INV-DELL-9921',
            'notes' => '10Gbps SFP+ network cards',
        ]);

        $this->assertNotNull($expense->getId());
        $this->assertStringStartsWith('EXP-', $expense->getExpenseNumber());
        $this->assertSame(20000, $expense->getAmountMinor());
        $this->assertSame(3800, $expense->getTaxAmountMinor());
        $this->assertSame(23800, $expense->getTotalMinor());
        $this->assertSame('EUR', $expense->getCurrencyCode());

        // Verify account balance was automatically debited
        $newBalance = $this->accountService->getBalance($this->bankAccount->getId());
        $this->assertSame(1000000 - 23800, $newBalance);
        $this->assertSame(976200, $newBalance);

        // Check expense query
        $found = $this->expenseService->findExpenseById($expense->getId());
        $this->assertNotNull($found);
        $this->assertSame($expense->getExpenseNumber(), $found->getExpenseNumber());
    }

    public function testRecurringExpenseGenerationAndDueAdvancement(): void
    {
        $category = $this->expenseService->createCategory([
            'code' => 'LICENSES',
            'name' => 'Software Licenses',
        ]);
        $vendor = $this->expenseService->createVendor([
            'code' => 'CPANEL',
            'name' => 'cPanel, L.L.C.',
        ]);

        // Monthly cPanel license recurring expense: 45.00 EUR / month, due 2026-10-10
        $recExpense = $this->expenseService->createRecurringExpense([
            'title' => 'cPanel Premier Cloud License',
            'category_id' => $category->getId(),
            'vendor_id' => $vendor->getId(),
            'financial_account_id' => $this->bankAccount->getId(),
            'cycle' => PriceCycle::MONTHLY,
            'amount_minor' => 4500,
            'currency_code' => 'EUR',
            'next_due_date' => '2026-10-10',
        ]);

        $this->assertNotNull($recExpense->getId());
        $this->assertSame('2026-10-10', $recExpense->getNextDueDate());
        $this->assertTrue($recExpense->isActive());

        // Process batch as of 2026-10-11 (due date has arrived)
        $generated = $this->expenseService->processDueRecurringExpenses('2026-10-11');
        $this->assertCount(1, $generated);
        $expense = $generated[0];

        $this->assertSame(4500, $expense->getAmountMinor());
        $this->assertTrue($expense->isRecurring());
        $this->assertSame($recExpense->getId(), $expense->getRecurringExpenseId());

        // Check that recurring expense next due date advanced by 1 month to 2026-11-10
        $updatedRec = $this->expenseService->findRecurringExpenseById($recExpense->getId());
        $this->assertSame('2026-11-10', $updatedRec->getNextDueDate());

        // Processing again as of 2026-10-11 yields 0 expenses (idempotent/already processed)
        $generatedAgain = $this->expenseService->processDueRecurringExpenses('2026-10-11');
        $this->assertCount(0, $generatedAgain);
    }

    public function testExpenseValidationErrors(): void
    {
        $category = $this->expenseService->createCategory([
            'code' => 'MISC',
            'name' => 'Miscellaneous',
        ]);

        // Zero amount throws ValidationException
        $this->expectException(ValidationException::class);
        $this->expenseService->recordExpense([
            'category_id' => $category->getId(),
            'financial_account_id' => $this->bankAccount->getId(),
            'amount_minor' => 0,
        ]);
    }
}
