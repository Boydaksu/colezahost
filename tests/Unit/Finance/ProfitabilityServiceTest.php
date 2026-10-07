<?php

declare(strict_types=1);

namespace Tests\Unit\Finance;

use Coleza\Domain\Commerce\Payments\PaymentService;
use Coleza\Domain\Finance\Accounts\AccountTransaction;
use Coleza\Domain\Finance\Accounts\FinancialAccount;
use Coleza\Domain\Finance\Accounts\FinancialAccountService;
use Coleza\Domain\Finance\Expenses\ExpenseService;
use Coleza\Domain\Finance\Expenses\FinanceCategory;
use Coleza\Domain\Finance\Profitability\GatewaySettlement;
use Coleza\Domain\Finance\Profitability\ProfitabilityReport;
use Coleza\Domain\Finance\Profitability\ProfitabilityService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class ProfitabilityServiceTest extends TestCase
{
    private Connection $db;
    private FinancialAccountService $accountService;
    private ExpenseService $expenseService;
    private PaymentService $paymentService;
    private ProfitabilityService $profitabilityService;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->accountService = new FinancialAccountService($this->db);
        $this->accountService->ensureTables();

        $this->expenseService = new ExpenseService($this->db, $this->accountService);
        $this->expenseService->ensureTables();

        $this->paymentService = new PaymentService($this->db);
        $this->paymentService->ensureTables();

        $this->profitabilityService = new ProfitabilityService($this->db, $this->accountService);
        $this->profitabilityService->ensureTables();
    }

    public function testRecordSettlementBatchWithAccountTransfers(): void
    {
        $gatewayAcc = $this->accountService->createAccount([
            'code' => 'GW-IYZICO-TRY',
            'name' => 'iyzico Gateway TRY',
            'account_type' => FinancialAccount::TYPE_GATEWAY,
            'currency_code' => 'TRY',
        ]);
        // Initial processed volume in gateway account: 50,000.00 TRY (5000000 minor)
        $this->accountService->recordTransaction([
            'account_id' => $gatewayAcc->getId(),
            'type' => AccountTransaction::TYPE_CREDIT,
            'amount_minor' => 5000000,
            'source' => AccountTransaction::SOURCE_PAYMENT,
            'description' => 'Card transactions volume',
        ]);

        $bankAcc = $this->accountService->createAccount([
            'code' => 'BANK-ZIRAAT-TRY',
            'name' => 'Ziraat Bankası TRY',
            'account_type' => FinancialAccount::TYPE_BANK,
            'currency_code' => 'TRY',
        ]);

        // Payout Batch: 30,000 TRY gross - 900 TRY fee = 29,100 TRY net
        $settlement = $this->profitabilityService->recordSettlementBatch([
            'gateway_code' => 'IYZICO',
            'source_account_id' => $gatewayAcc->getId(),
            'destination_account_id' => $bankAcc->getId(),
            'gross_amount_minor' => 3000000,
            'fee_amount_minor' => 90000,
            'currency_code' => 'TRY',
            'settlement_date' => '2026-10-06',
            'transaction_count' => 12,
            'payout_reference' => 'IYZ-BATCH-20261006',
        ]);

        $this->assertNotNull($settlement->getId());
        $this->assertStringStartsWith('SET-', $settlement->getSettlementNumber());
        $this->assertSame(3000000, $settlement->getGrossAmountMinor());
        $this->assertSame(90000, $settlement->getFeeAmountMinor());
        $this->assertSame(2910000, $settlement->getNetAmountMinor());
        $this->assertSame('IYZICO', $settlement->getGatewayCode());
        $this->assertTrue($settlement->isCompleted());

        // Gateway balance reduced from 5,000,000 to 2,000,000 minor
        $this->assertSame(2000000, $this->accountService->getBalance($gatewayAcc->getId()));

        // Bank balance increased from 0 to 2,910,000 minor
        $this->assertSame(2910000, $this->accountService->getBalance($bankAcc->getId()));
    }

    public function testProfitabilityReportCalculation(): void
    {
        $today = date('Y-m-d');

        // 1. Seed Customer Payments (Gross Revenue: 100,000 TRY / 10000000 minor, Fee: 2,500 TRY / 250000 minor)
        $this->paymentService->recordPayment([
            'user_id' => 1,
            'amount_minor' => 10000000,
            'fee_minor' => 250000,
            'currency_code' => 'TRY',
            'payment_method' => 'card',
            'status' => 'completed',
        ]);

        // 2. Seed Operating Expenses (35,000 TRY / 3500000 minor)
        $cat = $this->expenseService->createCategory([
            'code' => 'DATACENTER',
            'name' => 'Datacenter Server Racks',
        ]);
        $bank = $this->accountService->createAccount([
            'code' => 'BANK-PAY-TRY',
            'name' => 'Paying Bank',
            'currency_code' => 'TRY',
        ]);
        $this->expenseService->recordExpense([
            'category_id' => $cat->getId(),
            'financial_account_id' => $bank->getId(),
            'amount_minor' => 3500000,
            'currency_code' => 'TRY',
            'payment_date' => $today,
        ], debitAccount: false);

        // 3. Generate Profitability Report
        $report = $this->profitabilityService->generateProfitabilityReport(
            startDate: $today,
            endDate: $today,
            currencyCode: 'TRY'
        );

        $this->assertSame($today, $report->getPeriodStart());
        $this->assertSame('TRY', $report->getCurrencyCode());
        $this->assertSame(10000000, $report->getGrossRevenueMinor());
        $this->assertSame(250000, $report->getGatewayFeesMinor());
        $this->assertSame(9750000, $report->getNetRevenueMinor());
        $this->assertSame(3500000, $report->getTotalExpensesMinor());
        $this->assertSame(6250000, $report->getGrossProfitMinor());
        // Margin: 6250000 / 9750000 * 100 = 64.1%
        $this->assertSame(64.1, $report->getProfitMarginPercent());
        $this->assertTrue($report->isProfitable());
    }

    public function testSettlementValidationErrors(): void
    {
        $acc1 = $this->accountService->createAccount(['code' => 'A1', 'name' => 'Acc 1', 'currency_code' => 'USD']);
        $acc2 = $this->accountService->createAccount(['code' => 'A2', 'name' => 'Acc 2', 'currency_code' => 'USD']);

        // 1. Fee greater than gross throws ValidationException
        $this->expectException(ValidationException::class);
        $this->profitabilityService->recordSettlementBatch([
            'source_account_id' => $acc1->getId(),
            'destination_account_id' => $acc2->getId(),
            'gross_amount_minor' => 1000,
            'fee_amount_minor' => 2000,
        ]);
    }
}
