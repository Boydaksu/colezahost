<?php

declare(strict_types=1);

namespace Coleza\Domain\Finance\Profitability;

use Coleza\Domain\Finance\Accounts\AccountTransaction;
use Coleza\Domain\Finance\Accounts\FinancialAccountService;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use RuntimeException;

final class ProfitabilityService
{
    private string $settlementsTable = 'gateway_settlements';
    private string $sequencesTable = 'settlement_sequences';

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

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                settlement_number VARCHAR(50) NOT NULL UNIQUE,
                gateway_code VARCHAR(50) NOT NULL,
                source_account_id INT NOT NULL,
                destination_account_id INT NOT NULL,
                gross_amount_minor INT NOT NULL,
                fee_amount_minor INT NOT NULL DEFAULT 0,
                net_amount_minor INT NOT NULL,
                currency_code VARCHAR(3) NOT NULL,
                settlement_date VARCHAR(20) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT "completed",
                transaction_count INT NOT NULL DEFAULT 1,
                payout_reference VARCHAR(100) NULL,
                notes TEXT NULL,
                metadata_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->settlementsTable,
            $autoInc
        );
        $this->db->statement($sql);

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
     * Record a gateway settlement payout batch, deducting gross from gateway and depositing net into bank.
     *
     * @param array<string, mixed> $data
     */
    public function recordSettlementBatch(array $data): GatewaySettlement
    {
        $gatewayCode = strtoupper(trim((string)($data['gateway_code'] ?? 'GATEWAY')));
        $sourceAccountId = (int)($data['source_account_id'] ?? 0);
        $destAccountId = (int)($data['destination_account_id'] ?? 0);

        if ($sourceAccountId <= 0 || $destAccountId <= 0 || $sourceAccountId === $destAccountId) {
            throw new ValidationException(['accounts' => 'Valid distinct source and destination accounts are required.'], 'Invalid accounts');
        }

        $grossMinor = (int)($data['gross_amount_minor'] ?? 0);
        if ($grossMinor <= 0) {
            throw new ValidationException(['gross_amount_minor' => 'Gross amount must be greater than zero.'], 'Invalid gross amount');
        }

        $feeMinor = max(0, (int)($data['fee_amount_minor'] ?? 0));
        if ($feeMinor > $grossMinor) {
            throw new ValidationException(['fee_amount_minor' => 'Fee amount cannot exceed gross settlement amount.'], 'Excessive fee');
        }

        $netMinor = $grossMinor - $feeMinor;
        $currency = strtoupper(trim((string)($data['currency_code'] ?? 'USD')));
        $settlementDate = (string)($data['settlement_date'] ?? date('Y-m-d'));
        $status = (string)($data['status'] ?? GatewaySettlement::STATUS_COMPLETED);
        $txCount = max(1, (int)($data['transaction_count'] ?? 1));
        $payoutRef = isset($data['payout_reference']) ? (string)$data['payout_reference'] : null;
        $notes = isset($data['notes']) ? (string)$data['notes'] : null;
        $meta = (array)($data['metadata'] ?? []);

        $settlementNumber = $this->nextSettlementNumber();

        // If completed and account service is provided, execute ledger movement
        if ($status === GatewaySettlement::STATUS_COMPLETED && $this->accountService !== null) {
            // 1. Debit gross volume from source gateway account
            $this->accountService->recordTransaction([
                'account_id' => $sourceAccountId,
                'type' => AccountTransaction::TYPE_DEBIT,
                'amount_minor' => $grossMinor,
                'currency_code' => $currency,
                'source' => AccountTransaction::SOURCE_TRANSFER,
                'description' => "Gateway payout #{$settlementNumber} ({$gatewayCode} gross volume)",
            ]);

            // 2. Credit net payout to destination merchant bank account
            $this->accountService->recordTransaction([
                'account_id' => $destAccountId,
                'type' => AccountTransaction::TYPE_CREDIT,
                'amount_minor' => $netMinor,
                'currency_code' => $currency,
                'source' => AccountTransaction::SOURCE_TRANSFER,
                'description' => "Gateway payout deposit #{$settlementNumber} (net after fees)",
            ]);
        }

        $sql = sprintf(
            'INSERT INTO %s (settlement_number, gateway_code, source_account_id, destination_account_id, gross_amount_minor, fee_amount_minor, net_amount_minor, currency_code, settlement_date, status, transaction_count, payout_reference, notes, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $this->settlementsTable
        );

        $this->db->statement($sql, [
            $settlementNumber,
            $gatewayCode,
            $sourceAccountId,
            $destAccountId,
            $grossMinor,
            $feeMinor,
            $netMinor,
            $currency,
            $settlementDate,
            $status,
            $txCount,
            $payoutRef,
            $notes,
            json_encode($meta),
        ]);

        $id = (int)$this->db->getPdo()->lastInsertId();

        return new GatewaySettlement(
            id: $id,
            settlementNumber: $settlementNumber,
            gatewayCode: $gatewayCode,
            sourceAccountId: $sourceAccountId,
            destinationAccountId: $destAccountId,
            grossAmountMinor: $grossMinor,
            feeAmountMinor: $feeMinor,
            netAmountMinor: $netMinor,
            currencyCode: $currency,
            settlementDate: $settlementDate,
            status: $status,
            transactionCount: $txCount,
            payoutReference: $payoutRef,
            notes: $notes,
            metadata: $meta,
            createdAt: date('Y-m-d H:i:s')
        );
    }

    public function findSettlementById(int $id): ?GatewaySettlement
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE id = ?', $this->settlementsTable), [$id]);
        return $row ? $this->hydrateSettlement($row) : null;
    }

    public function findSettlementByNumber(string $number): ?GatewaySettlement
    {
        $row = $this->db->selectOne(sprintf('SELECT * FROM %s WHERE settlement_number = ?', $this->settlementsTable), [$number]);
        return $row ? $this->hydrateSettlement($row) : null;
    }

    /**
     * @return array<GatewaySettlement>
     */
    public function listSettlements(?string $gatewayCode = null): array
    {
        $sql = sprintf('SELECT * FROM %s', $this->settlementsTable);
        $params = [];
        if ($gatewayCode !== null) {
            $sql .= ' WHERE gateway_code = ?';
            $params[] = strtoupper(trim($gatewayCode));
        }
        $sql .= ' ORDER BY settlement_date DESC, id DESC';

        $rows = $this->db->select($sql, $params);
        return array_map([$this, 'hydrateSettlement'], $rows);
    }

    /**
     * Compute comprehensive financial profitability report across revenues, gateway costs, and operating expenses.
     */
    public function generateProfitabilityReport(string $startDate, string $endDate, string $currencyCode): ProfitabilityReport
    {
        $currency = strtoupper(trim($currencyCode));

        // 1. Calculate Gross Inflows & Payment Fees from Payments table
        $paymentRow = $this->db->selectOne(
            "SELECT COALESCE(SUM(amount_minor), 0) AS total_gross, COALESCE(SUM(fee_minor), 0) AS total_fees 
             FROM payments 
             WHERE status = 'completed' AND currency_code = ? AND date(created_at) >= ? AND date(created_at) <= ?",
            [$currency, $startDate, $endDate]
        );
        $grossRevenueMinor = $paymentRow ? (int)$paymentRow['total_gross'] : 0;
        $paymentFeesMinor = $paymentRow ? (int)$paymentRow['total_fees'] : 0;

        // 2. Additional Gateway Settlement Fees from Settlements
        $settlementRow = $this->db->selectOne(
            "SELECT COALESCE(SUM(fee_amount_minor), 0) AS total_settlement_fees 
             FROM gateway_settlements 
             WHERE status = 'completed' AND currency_code = ? AND settlement_date >= ? AND settlement_date <= ?",
            [$currency, $startDate, $endDate]
        );
        $settlementFeesMinor = $settlementRow ? (int)$settlementRow['total_settlement_fees'] : 0;
        $totalGatewayFeesMinor = $paymentFeesMinor + $settlementFeesMinor;

        $netRevenueMinor = max(0, $grossRevenueMinor - $totalGatewayFeesMinor);

        // 3. Operating Expenses from Expenses table
        $expenseRow = $this->db->selectOne(
            "SELECT COALESCE(SUM(total_minor), 0) AS total_expenses 
             FROM finance_expenses 
             WHERE currency_code = ? AND payment_date >= ? AND payment_date <= ?",
            [$currency, $startDate, $endDate]
        );
        $totalExpensesMinor = $expenseRow ? (int)$expenseRow['total_expenses'] : 0;

        // 4. Gross Profit & Profit Margin
        $grossProfitMinor = $netRevenueMinor - $totalExpensesMinor;
        $profitMargin = $netRevenueMinor > 0 ? round(($grossProfitMinor / $netRevenueMinor) * 100, 2) : 0.0;

        return new ProfitabilityReport(
            periodStart: $startDate,
            periodEnd: $endDate,
            currencyCode: $currency,
            grossRevenueMinor: $grossRevenueMinor,
            gatewayFeesMinor: $totalGatewayFeesMinor,
            netRevenueMinor: $netRevenueMinor,
            totalExpensesMinor: $totalExpensesMinor,
            grossProfitMinor: $grossProfitMinor,
            profitMarginPercent: $profitMargin
        );
    }

    public function nextSettlementNumber(): string
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

        return "SET-{$date}-{$formattedNum}";
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateSettlement(array $row): GatewaySettlement
    {
        $meta = !empty($row['metadata_json']) ? json_decode((string)$row['metadata_json'], true) : [];

        return new GatewaySettlement(
            id: (int)$row['id'],
            settlementNumber: (string)$row['settlement_number'],
            gatewayCode: (string)$row['gateway_code'],
            sourceAccountId: (int)$row['source_account_id'],
            destinationAccountId: (int)$row['destination_account_id'],
            grossAmountMinor: (int)$row['gross_amount_minor'],
            feeAmountMinor: (int)$row['fee_amount_minor'],
            netAmountMinor: (int)$row['net_amount_minor'],
            currencyCode: (string)$row['currency_code'],
            settlementDate: (string)$row['settlement_date'],
            status: (string)$row['status'],
            transactionCount: (int)$row['transaction_count'],
            payoutReference: $row['payout_reference'] !== null ? (string)$row['payout_reference'] : null,
            notes: $row['notes'] !== null ? (string)$row['notes'] : null,
            metadata: is_array($meta) ? $meta : [],
            createdAt: (string)$row['created_at']
        );
    }
}
