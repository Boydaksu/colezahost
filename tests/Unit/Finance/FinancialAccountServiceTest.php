<?php

declare(strict_types=1);

namespace Tests\Unit\Finance;

use Coleza\Domain\Finance\Accounts\AccountTransaction;
use Coleza\Domain\Finance\Accounts\FinancialAccount;
use Coleza\Domain\Finance\Accounts\FinancialAccountService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class FinancialAccountServiceTest extends TestCase
{
    private Connection $db;
    private FinancialAccountService $service;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($pdo, 'sqlite');

        $this->service = new FinancialAccountService($this->db);
        $this->service->ensureTables();
    }

    public function testCreateFinancialAccountsWithDefaults(): void
    {
        // 1. Create Ziraat Bank TRY account
        $ziraat = $this->service->createAccount([
            'code' => 'BANK-ZIRAAT-TRY',
            'name' => 'Ziraat Bankası Ticari TRY',
            'account_type' => FinancialAccount::TYPE_BANK,
            'currency_code' => 'TRY',
            'account_number' => 'TR120001000000000000111111',
            'bank_name' => 'Ziraat Bankası',
            'is_default' => true,
        ]);

        $this->assertNotNull($ziraat->getId());
        $this->assertSame('BANK-ZIRAAT-TRY', $ziraat->getCode());
        $this->assertSame('TRY', $ziraat->getCurrencyCode());
        $this->assertSame(FinancialAccount::TYPE_BANK, $ziraat->getAccountType());
        $this->assertTrue($ziraat->isDefault());
        $this->assertTrue($ziraat->isActive());

        // 2. Create iyzico Gateway TRY account
        $iyzico = $this->service->createAccount([
            'code' => 'GW-IYZICO-TRY',
            'name' => 'iyzico Sanal POS TRY',
            'account_type' => FinancialAccount::TYPE_GATEWAY,
            'currency_code' => 'TRY',
            'is_default' => true,
        ]);
        $this->assertSame(FinancialAccount::TYPE_GATEWAY, $iyzico->getAccountType());

        // 3. Create second TRY bank account and set as default -> previous default unset
        $isBank = $this->service->createAccount([
            'code' => 'BANK-IS-TRY',
            'name' => 'İş Bankası TRY',
            'account_type' => FinancialAccount::TYPE_BANK,
            'currency_code' => 'TRY',
            'is_default' => true,
        ]);
        $this->assertTrue($isBank->isDefault());

        $refreshedZiraat = $this->service->findAccountById($ziraat->getId());
        $this->assertFalse($refreshedZiraat->isDefault());

        // 4. Default query resolution
        $defaultTryBank = $this->service->getDefaultAccount('TRY', FinancialAccount::TYPE_BANK);
        $this->assertSame($isBank->getId(), $defaultTryBank->getId());

        // 5. Duplicate code throws ValidationException
        $this->expectException(ValidationException::class);
        $this->service->createAccount([
            'code' => 'BANK-IS-TRY',
            'name' => 'Duplicate Code Test',
            'currency_code' => 'TRY',
        ]);
    }

    public function testRecordTransactionsAndBalanceDerivation(): void
    {
        $account = $this->service->createAccount([
            'code' => 'CASH-MAIN-TRY',
            'name' => 'Merkez Kasa',
            'account_type' => FinancialAccount::TYPE_CASH,
            'currency_code' => 'TRY',
        ]);

        $this->assertSame(0, $this->service->getBalance($account->getId()));

        // Inflow 1: 500.00 TRY (50000 minor)
        $txn1 = $this->service->recordTransaction([
            'account_id' => $account->getId(),
            'type' => AccountTransaction::TYPE_CREDIT,
            'amount_minor' => 50000,
            'source' => AccountTransaction::SOURCE_PAYMENT,
            'description' => 'Direct cash payment for invoice',
        ]);

        $this->assertNotNull($txn1->getId());
        $this->assertStringStartsWith('TXN-', $txn1->getEntryNumber());
        $this->assertTrue($txn1->isCredit());
        $this->assertSame(50000, $txn1->getBalanceAfterMinor());
        $this->assertSame(50000, $this->service->getBalance($account->getId()));

        // Inflow 2: 250.00 TRY (25000 minor)
        $txn2 = $this->service->recordTransaction([
            'account_id' => $account->getId(),
            'type' => AccountTransaction::TYPE_CREDIT,
            'amount_minor' => 25000,
            'source' => AccountTransaction::SOURCE_ADJUSTMENT,
            'description' => 'Cash drawer count adjustment',
        ]);
        $this->assertSame(75000, $txn2->getBalanceAfterMinor());
        $this->assertSame(75000, $this->service->getBalance($account->getId()));

        // Outflow 1: 300.00 TRY (30000 minor)
        $txn3 = $this->service->recordTransaction([
            'account_id' => $account->getId(),
            'type' => AccountTransaction::TYPE_DEBIT,
            'amount_minor' => 30000,
            'source' => AccountTransaction::SOURCE_EXPENSE,
            'description' => 'Office supplies cash expense',
        ]);
        $this->assertTrue($txn3->isDebit());
        $this->assertSame(45000, $txn3->getBalanceAfterMinor());
        $this->assertSame(45000, $this->service->getBalance($account->getId()));

        // Overdraft attempt (balance is 45000, attempting to debit 50000)
        $this->expectException(ValidationException::class);
        $this->service->recordTransaction([
            'account_id' => $account->getId(),
            'type' => AccountTransaction::TYPE_DEBIT,
            'amount_minor' => 50000,
            'source' => AccountTransaction::SOURCE_EXPENSE,
            'description' => 'Excessive expense without overdraft',
        ]);
    }

    public function testInterAccountTransfers(): void
    {
        // Gateway account with 1000.00 TRY (100000 minor)
        $gwAccount = $this->service->createAccount([
            'code' => 'GW-STRIPE-TRY',
            'name' => 'Stripe Gateway TRY',
            'account_type' => FinancialAccount::TYPE_GATEWAY,
            'currency_code' => 'TRY',
        ]);
        $this->service->recordTransaction([
            'account_id' => $gwAccount->getId(),
            'type' => AccountTransaction::TYPE_CREDIT,
            'amount_minor' => 100000,
            'source' => AccountTransaction::SOURCE_PAYMENT,
            'description' => 'Customer card settlements',
        ]);

        // Commercial bank account with 0.00 TRY
        $bankAccount = $this->service->createAccount([
            'code' => 'BANK-VAKIF-TRY',
            'name' => 'VakıfBank TRY',
            'account_type' => FinancialAccount::TYPE_BANK,
            'currency_code' => 'TRY',
        ]);

        // Payout transfer of 800.00 TRY (80000 minor) from Gateway to Bank
        $transferResult = $this->service->transferBetweenAccounts(
            fromAccountId: $gwAccount->getId(),
            toAccountId: $bankAccount->getId(),
            amountMinor: 80000,
            description: 'Weekly gateway payout settlement'
        );

        $this->assertArrayHasKey('outflow', $transferResult);
        $this->assertArrayHasKey('inflow', $transferResult);

        $outflow = $transferResult['outflow'];
        $this->assertSame($gwAccount->getId(), $outflow->getAccountId());
        $this->assertTrue($outflow->isDebit());
        $this->assertSame(80000, $outflow->getAmountMinor());
        $this->assertSame(20000, $this->service->getBalance($gwAccount->getId()));

        $inflow = $transferResult['inflow'];
        $this->assertSame($bankAccount->getId(), $inflow->getAccountId());
        $this->assertTrue($inflow->isCredit());
        $this->assertSame(80000, $inflow->getAmountMinor());
        $this->assertSame(80000, $this->service->getBalance($bankAccount->getId()));
    }

    public function testAuditedLedgerView(): void
    {
        $account = $this->service->createAccount([
            'code' => 'BANK-AUDIT-USD',
            'name' => 'USD Operating Bank',
            'account_type' => FinancialAccount::TYPE_BANK,
            'currency_code' => 'USD',
        ]);

        // Txn 1: Inflow $1000 (100000 minor)
        $this->service->recordTransaction([
            'account_id' => $account->getId(),
            'type' => AccountTransaction::TYPE_CREDIT,
            'amount_minor' => 100000,
            'source' => AccountTransaction::SOURCE_PAYMENT,
            'description' => 'Wire from client',
        ]);

        // Txn 2: Outflow $200 (20000 minor)
        $this->service->recordTransaction([
            'account_id' => $account->getId(),
            'type' => AccountTransaction::TYPE_DEBIT,
            'amount_minor' => 20000,
            'source' => AccountTransaction::SOURCE_EXPENSE,
            'description' => 'Server provider invoice payment',
        ]);

        $ledgerView = $this->service->getLedgerView($account->getId());

        $this->assertSame(0, $ledgerView['opening_balance_minor']);
        $this->assertSame(100000, $ledgerView['total_credit_minor']);
        $this->assertSame(20000, $ledgerView['total_debit_minor']);
        $this->assertSame(80000, $ledgerView['closing_balance_minor']);
        $this->assertCount(2, $ledgerView['transactions']);
    }

    public function testCurrencyMismatchRejection(): void
    {
        $tryAccount = $this->service->createAccount([
            'code' => 'BANK-MISMATCH-TRY',
            'name' => 'TRY Account',
            'currency_code' => 'TRY',
        ]);

        $this->expectException(ValidationException::class);
        $this->service->recordTransaction([
            'account_id' => $tryAccount->getId(),
            'type' => AccountTransaction::TYPE_CREDIT,
            'amount_minor' => 5000,
            'currency_code' => 'USD', // Mismatch!
            'description' => 'Illegal USD into TRY account',
        ]);
    }
}
