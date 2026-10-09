<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Whmcs\Financial;

use Coleza\Domain\Migration\Adoption\ProviderIdentityResolver;
use Coleza\Domain\Migration\Staging\DatabaseStagingRepository;
use Coleza\Domain\Migration\Staging\StagingPipelineService;
use Coleza\Domain\Migration\Staging\StagingRecordStatus;
use Coleza\Domain\Migration\Whmcs\WhmcsReadOnlyConnector;
use Coleza\Foundation\Database\Connection;
use RuntimeException;

/**
 * Migrates WHMCS invoices, payments, allocations, and credit balances into Coleza
 * with exact cent-for-cent source-to-target financial ledger zero-diff reconciliation.
 */
final class WhmcsFinancialMigrator
{
    private WhmcsFinancialExtractor $extractor;

    /**
     * @var array<string, int> [whmcs_invoice_id => target_invoice_id]
     */
    private array $invoiceMap = [];

    public function __construct(
        private Connection $targetDb,
        private WhmcsReadOnlyConnector $whmcs,
        private DatabaseStagingRepository $stagingRepo,
        private StagingPipelineService $stagingPipeline,
        private ProviderIdentityResolver $identityResolver,
        ?WhmcsFinancialExtractor $extractor = null
    ) {
        $this->extractor = $extractor ?? new WhmcsFinancialExtractor($whmcs);
    }

    public function registerInvoiceMapping(string $sourceInvoiceId, int $targetInvoiceId): void
    {
        $this->invoiceMap[(string) $sourceInvoiceId] = $targetInvoiceId;
    }

    public function getInvoiceMapping(string $sourceInvoiceId): ?int
    {
        return $this->invoiceMap[(string) $sourceInvoiceId] ?? null;
    }

    /**
     * @return array<string, int>
     */
    public function getInvoiceMap(): array
    {
        return $this->invoiceMap;
    }

    public function ensureFinancialTables(): void
    {
        $driver = $this->targetDb->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        // Invoices table
        $sqlInvoices = sprintf(
            'CREATE TABLE IF NOT EXISTS invoices (
                id %s,
                invoice_number VARCHAR(50) NOT NULL UNIQUE,
                user_id INT NOT NULL,
                organization_id INT NULL,
                order_id INT NULL,
                status VARCHAR(50) NOT NULL DEFAULT "unpaid",
                currency_code VARCHAR(3) NOT NULL,
                subtotal_minor INT NOT NULL DEFAULT 0,
                tax_total_minor INT NOT NULL DEFAULT 0,
                total_minor INT NOT NULL DEFAULT 0,
                paid_amount_minor INT NOT NULL DEFAULT 0,
                issue_date VARCHAR(20) NOT NULL,
                due_date VARCHAR(20) NOT NULL,
                paid_at TIMESTAMP NULL,
                currency_snapshot_json TEXT NULL,
                tax_snapshot_json TEXT NULL,
                notes TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $autoInc
        );
        $this->targetDb->statement($sqlInvoices);

        // Invoice items table
        $sqlItems = sprintf(
            'CREATE TABLE IF NOT EXISTS invoice_items (
                id %s,
                invoice_id INT NOT NULL,
                description VARCHAR(255) NOT NULL,
                quantity INT NOT NULL DEFAULT 1,
                unit_amount_minor INT NOT NULL DEFAULT 0,
                subtotal_minor INT NOT NULL DEFAULT 0,
                tax_amount_minor INT NOT NULL DEFAULT 0,
                total_minor INT NOT NULL DEFAULT 0,
                order_item_id INT NULL,
                service_id INT NULL,
                tax_snapshot_json TEXT NULL,
                metadata_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $autoInc
        );
        $this->targetDb->statement($sqlItems);

        // Payments table
        $sqlPayments = sprintf(
            'CREATE TABLE IF NOT EXISTS payments (
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
                status VARCHAR(50) NOT NULL DEFAULT "completed",
                transaction_reference VARCHAR(255) NULL,
                proof_document_url VARCHAR(255) NULL,
                notes TEXT NULL,
                paid_at TIMESTAMP NULL,
                metadata_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $autoInc
        );
        $this->targetDb->statement($sqlPayments);

        // Payment Allocations table
        $sqlAllocations = sprintf(
            'CREATE TABLE IF NOT EXISTS payment_allocations (
                id %s,
                payment_id INT NOT NULL,
                invoice_id INT NOT NULL,
                invoice_item_id INT NULL,
                amount_minor INT NOT NULL,
                allocated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                metadata_json TEXT NULL
            )',
            $autoInc
        );
        $this->targetDb->statement($sqlAllocations);

        // Credit Ledger table
        $sqlLedger = sprintf(
            'CREATE TABLE IF NOT EXISTS credit_ledger (
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
            $autoInc
        );
        $this->targetDb->statement($sqlLedger);

        $this->stagingRepo->ensureTable();
    }

    /**
     * Executes the end-to-end financial migration and reconciliation pipeline:
     * 1. Invoices & line items
     * 2. Payment transactions & allocations
     * 3. Client credit balances
     * 4. Multi-currency zero-diff reconciliation verification
     *
     * @param array<string, mixed> $options
     */
    public function migrateAndReconcile(string $batchId, array $options = []): WhmcsFinancialReconciliationReport
    {
        $this->ensureFinancialTables();

        $invoiceStats = $this->migrateInvoices($batchId, $options);
        $paymentStats = $this->migratePayments($batchId, $options);
        $creditStats = $this->migrateCredits($batchId, $options);

        // Perform multi-currency ledger reconciliation
        $invoiceRecon = $this->reconcileInvoices($invoiceStats);
        $paymentRecon = $this->reconcilePayments($paymentStats);
        $creditRecon = $this->reconcileCredits($creditStats);

        $stagingReport = $this->stagingRepo->generateAccountingReport($batchId);

        if (!$stagingReport->isZeroSilentLossAchieved()) {
            throw new RuntimeException(sprintf(
                'Zero Silent Data Loss violated in financial batch [%s]: unaccounted diff = %d',
                $batchId,
                $stagingReport->getUnaccountedDiff()
            ));
        }

        return new WhmcsFinancialReconciliationReport(
            batchId: $batchId,
            invoiceReconciliation: $invoiceRecon,
            paymentReconciliation: $paymentRecon,
            creditReconciliation: $creditRecon,
            stagingReport: $stagingReport
        );
    }

    /**
     * Migrates WHMCS invoices.
     *
     * @param array<string, mixed> $options
     * @return array{staged: int, migrated: int, quarantined: int, amounts_by_currency: array<string, float>}
     */
    public function migrateInvoices(string $batchId, array $options = []): array
    {
        $limit = isset($options['invoice_limit']) ? (int) $options['invoice_limit'] : null;
        $offset = isset($options['invoice_offset']) ? (int) $options['invoice_offset'] : null;

        $invoices = $this->extractor->extractInvoices($limit, $offset);

        $staged = 0;
        $migrated = 0;
        $quarantined = 0;
        $amountsByCurrency = [];

        foreach ($invoices as $inv) {
            $sourceId = (string) ($inv['id'] ?? '');

            // 1. Stage raw record
            $record = $this->stagingPipeline->stageRawRecord(
                batchId: $batchId,
                sourceSystem: 'whmcs',
                sourceEntityType: 'invoice',
                sourceEntityId: $sourceId,
                rawPayload: $inv
            );
            $staged++;

            // 2. Transform and validate
            $record = $this->stagingPipeline->transformAndValidate($record);

            if ($record->getStatus() === StagingRecordStatus::VALIDATED) {
                // Verify client mapping exists
                $resolvedClient = $this->identityResolver->resolveClient((string) ($inv['userid'] ?? ''));
                if ($resolvedClient === null) {
                    $record->markQuarantined(
                        reason: sprintf('Invoice [%s] references unmigrated client [%s].', $sourceId, $inv['userid'] ?? ''),
                        errors: [sprintf('Client source ID [%s] has not been resolved.', $inv['userid'] ?? '')]
                    );
                    $this->stagingRepo->save($record);
                    $quarantined++;
                    continue;
                }

                $currency = (string) ($inv['currency'] ?? 'USD');
                $subtotalMinor = (int) round(((float) ($inv['subtotal'] ?? 0.0)) * 100);
                $tax1 = (float) ($inv['tax'] ?? 0.0);
                $tax2 = (float) ($inv['tax2'] ?? 0.0);
                $taxTotalMinor = (int) round(($tax1 + $tax2) * 100);
                $totalMinor = (int) round(((float) ($inv['total'] ?? 0.0)) * 100);
                $status = strtolower(trim((string) ($inv['status'] ?? 'unpaid')));
                $issueDate = (string) ($inv['date'] ?? date('Y-m-d'));
                $dueDate = (string) ($inv['duedate'] ?? $issueDate);
                $paidAt = !empty($inv['datepaid']) && $inv['datepaid'] !== '0000-00-00 00:00:00' ? (string) $inv['datepaid'] : null;
                $invNumber = !empty($inv['invoicenum']) ? (string) $inv['invoicenum'] : 'WHMCS-INV-' . $sourceId;

                // Upsert invoice into invoices table
                $existing = $this->targetDb->selectOne('SELECT id FROM invoices WHERE invoice_number = ?', [$invNumber]);
                if ($existing !== null) {
                    $targetInvId = (int) $existing['id'];
                } else {
                    $this->targetDb->statement(
                        'INSERT INTO invoices
                        (invoice_number, user_id, status, currency_code, subtotal_minor, tax_total_minor,
                         total_minor, paid_amount_minor, issue_date, due_date, paid_at, notes)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            $invNumber,
                            $resolvedClient,
                            $status,
                            $currency,
                            $subtotalMinor,
                            $taxTotalMinor,
                            $totalMinor,
                            $status === 'paid' ? $totalMinor : 0,
                            $issueDate,
                            $dueDate,
                            $paidAt,
                            $inv['notes'] ?? null,
                        ]
                    );
                    $targetInvId = (int) $this->targetDb->getPdo()->lastInsertId();
                }

                // Insert line items
                $items = (array) ($inv['items'] ?? []);
                foreach ($items as $item) {
                    $itemAmountMinor = (int) round(((float) ($item['amount'] ?? 0.0)) * 100);
                    $this->targetDb->statement(
                        'INSERT INTO invoice_items
                        (invoice_id, description, quantity, unit_amount_minor, subtotal_minor, tax_amount_minor, total_minor)
                        VALUES (?, ?, 1, ?, ?, 0, ?)',
                        [
                            $targetInvId,
                            (string) ($item['description'] ?? 'Item'),
                            $itemAmountMinor,
                            $itemAmountMinor,
                            $itemAmountMinor,
                        ]
                    );
                }

                // Record mapping
                $this->registerInvoiceMapping($sourceId, $targetInvId);

                // Mark staging record as MIGRATED
                $record->markMigrated($targetInvId);
                $this->stagingRepo->save($record);

                $migrated++;
                $amountsByCurrency[$currency] = round(($amountsByCurrency[$currency] ?? 0.0) + ($totalMinor / 100.0), 2);
            } else {
                $quarantined++;
            }
        }

        return [
            'staged' => $staged,
            'migrated' => $migrated,
            'quarantined' => $quarantined,
            'amounts_by_currency' => $amountsByCurrency,
        ];
    }

    /**
     * Migrates WHMCS payment transactions from tblaccounts.
     *
     * @param array<string, mixed> $options
     * @return array{staged: int, migrated: int, quarantined: int, amounts_by_currency: array<string, float>}
     */
    public function migratePayments(string $batchId, array $options = []): array
    {
        $limit = isset($options['payment_limit']) ? (int) $options['payment_limit'] : null;
        $offset = isset($options['payment_offset']) ? (int) $options['payment_offset'] : null;

        $txns = $this->extractor->extractTransactions($limit, $offset);

        $staged = 0;
        $migrated = 0;
        $quarantined = 0;
        $amountsByCurrency = [];

        foreach ($txns as $t) {
            $amountIn = (float) ($t['amountin'] ?? 0.0);
            if ($amountIn <= 0.0) {
                // Not an incoming payment (e.g. fee, expense, refund)
                continue;
            }

            $sourceId = (string) ($t['id'] ?? '');

            // Stage raw record
            $record = $this->stagingPipeline->stageRawRecord(
                batchId: $batchId,
                sourceSystem: 'whmcs',
                sourceEntityType: 'payment',
                sourceEntityId: $sourceId,
                rawPayload: $t
            );
            $staged++;

            // Transform and validate
            $record = $this->stagingPipeline->transformAndValidate($record);

            if ($record->getStatus() === StagingRecordStatus::VALIDATED) {
                // Verify client mapping exists
                $resolvedClient = $this->identityResolver->resolveClient((string) ($t['userid'] ?? ''));
                if ($resolvedClient === null) {
                    $record->markQuarantined(
                        reason: sprintf('Payment [%s] references unmigrated client [%s].', $sourceId, $t['userid'] ?? ''),
                        errors: [sprintf('Client source ID [%s] has not been resolved.', $t['userid'] ?? '')]
                    );
                    $this->stagingRepo->save($record);
                    $quarantined++;
                    continue;
                }

                $amountMinor = (int) round($amountIn * 100);
                $feeMinor = (int) round(((float) ($t['fees'] ?? 0.0)) * 100);
                $netMinor = $amountMinor - $feeMinor;
                $currency = (string) ($t['currency'] ?? 'USD');
                $payNumber = 'WHMCS-PAY-' . $sourceId;
                $gateway = strtolower(trim((string) ($t['gateway'] ?? 'banktransfer')));
                $transRef = !empty($t['transid']) ? (string) $t['transid'] : null;
                $paidAt = !empty($t['date']) ? (string) $t['date'] : date('Y-m-d H:i:s');

                // Check invoice linkage
                $sourceInvId = (string) ($t['invoiceid'] ?? '');
                $targetInvId = $sourceInvId !== '' ? $this->getInvoiceMapping($sourceInvId) : null;

                // Upsert payment into payments table
                $existing = $this->targetDb->selectOne('SELECT id FROM payments WHERE payment_number = ?', [$payNumber]);
                if ($existing !== null) {
                    $targetPayId = (int) $existing['id'];
                } else {
                    $this->targetDb->statement(
                        'INSERT INTO payments
                        (payment_number, user_id, invoice_id, payment_method, amount_minor, fee_minor,
                         net_amount_minor, currency_code, status, transaction_reference, notes, paid_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, "completed", ?, ?, ?)',
                        [
                            $payNumber,
                            $resolvedClient,
                            $targetInvId,
                            $gateway,
                            $amountMinor,
                            $feeMinor,
                            $netMinor,
                            $currency,
                            $transRef,
                            $t['description'] ?? null,
                            $paidAt,
                        ]
                    );
                    $targetPayId = (int) $this->targetDb->getPdo()->lastInsertId();
                }

                // Create allocation and update invoice if invoice was resolved
                if ($targetInvId !== null) {
                    $this->targetDb->statement(
                        'INSERT INTO payment_allocations (payment_id, invoice_id, amount_minor, allocated_at)
                        VALUES (?, ?, ?, ?)',
                        [$targetPayId, $targetInvId, $amountMinor, $paidAt]
                    );

                    $this->targetDb->statement(
                        'UPDATE invoices SET paid_amount_minor = paid_amount_minor + ? WHERE id = ?',
                        [$amountMinor, $targetInvId]
                    );
                }

                // Mark staging record as MIGRATED
                $record->markMigrated($targetPayId);
                $this->stagingRepo->save($record);

                $migrated++;
                $amountsByCurrency[$currency] = round(($amountsByCurrency[$currency] ?? 0.0) + ($amountMinor / 100.0), 2);
            } else {
                $quarantined++;
            }
        }

        return [
            'staged' => $staged,
            'migrated' => $migrated,
            'quarantined' => $quarantined,
            'amounts_by_currency' => $amountsByCurrency,
        ];
    }

    /**
     * Migrates client credit balances into Coleza credit_ledger.
     *
     * @param array<string, mixed> $options
     * @return array{staged: int, migrated: int, quarantined: int, amounts_by_currency: array<string, float>}
     */
    public function migrateCredits(string $batchId, array $options = []): array
    {
        $credits = $this->extractor->extractClientCredits();

        $staged = 0;
        $migrated = 0;
        $quarantined = 0;
        $amountsByCurrency = [];

        foreach ($credits as $c) {
            $clientId = (string) $c['client_id'];
            $amount = (float) $c['credit'];
            $currency = (string) $c['currency'];

            // Stage raw record
            $record = $this->stagingPipeline->stageRawRecord(
                batchId: $batchId,
                sourceSystem: 'whmcs',
                sourceEntityType: 'credit',
                sourceEntityId: $clientId,
                rawPayload: $c
            );
            $staged++;

            // Verify client mapping exists
            $resolvedClient = $this->identityResolver->resolveClient($clientId);
            if ($resolvedClient === null) {
                $record->markQuarantined(
                    reason: sprintf('Credit balance for client [%s] cannot be migrated: client is not resolved.', $clientId),
                    errors: [sprintf('Client source ID [%s] has not been resolved.', $clientId)]
                );
                $this->stagingRepo->save($record);
                $quarantined++;
                continue;
            }

            $amountMinor = (int) round($amount * 100);
            $entryNumber = 'WHMCS-CR-' . $clientId;

            // Upsert into credit ledger
            $existing = $this->targetDb->selectOne('SELECT id FROM credit_ledger WHERE entry_number = ?', [$entryNumber]);
            if ($existing !== null) {
                $targetLedgerId = (int) $existing['id'];
            } else {
                $this->targetDb->statement(
                    'INSERT INTO credit_ledger
                    (entry_number, user_id, currency_code, type, amount_minor, balance_after_minor, reason)
                    VALUES (?, ?, ?, "deposit", ?, ?, "Migrated WHMCS credit balance")',
                    [$entryNumber, $resolvedClient, $currency, $amountMinor, $amountMinor]
                );
                $targetLedgerId = (int) $this->targetDb->getPdo()->lastInsertId();
            }

            // Mark staging record as MIGRATED
            $record->markMigrated($targetLedgerId);
            $this->stagingRepo->save($record);

            $migrated++;
            $amountsByCurrency[$currency] = round(($amountsByCurrency[$currency] ?? 0.0) + ($amountMinor / 100.0), 2);
        }

        return [
            'staged' => $staged,
            'migrated' => $migrated,
            'quarantined' => $quarantined,
            'amounts_by_currency' => $amountsByCurrency,
        ];
    }

    /**
     * @param array{staged: int, migrated: int, quarantined: int, amounts_by_currency: array<string, float>} $stats
     * @return array{source: array<string, float>, target: array<string, float>, diff: array<string, float>, is_reconciled: bool, count_staged: int, count_migrated: int, count_quarantined: int}
     */
    private function reconcileInvoices(array $stats): array
    {
        $sourceTotals = $this->extractor->calculateSourceInvoiceTotals();
        $targetTotals = $stats['amounts_by_currency'];

        return $this->buildReconciliationEntry($sourceTotals, $targetTotals, $stats);
    }

    /**
     * @param array{staged: int, migrated: int, quarantined: int, amounts_by_currency: array<string, float>} $stats
     * @return array{source: array<string, float>, target: array<string, float>, diff: array<string, float>, is_reconciled: bool, count_staged: int, count_migrated: int, count_quarantined: int}
     */
    private function reconcilePayments(array $stats): array
    {
        $sourceTotals = $this->extractor->calculateSourcePaymentTotals();
        $targetTotals = $stats['amounts_by_currency'];

        return $this->buildReconciliationEntry($sourceTotals, $targetTotals, $stats);
    }

    /**
     * @param array{staged: int, migrated: int, quarantined: int, amounts_by_currency: array<string, float>} $stats
     * @return array{source: array<string, float>, target: array<string, float>, diff: array<string, float>, is_reconciled: bool, count_staged: int, count_migrated: int, count_quarantined: int}
     */
    private function reconcileCredits(array $stats): array
    {
        $sourceTotals = $this->extractor->calculateSourceCreditTotals();
        $targetTotals = $stats['amounts_by_currency'];

        return $this->buildReconciliationEntry($sourceTotals, $targetTotals, $stats);
    }

    /**
     * @param array<string, float> $sourceTotals
     * @param array<string, float> $targetTotals
     * @param array{staged: int, migrated: int, quarantined: int} $stats
     * @return array{source: array<string, float>, target: array<string, float>, diff: array<string, float>, is_reconciled: bool, count_staged: int, count_migrated: int, count_quarantined: int}
     */
    private function buildReconciliationEntry(array $sourceTotals, array $targetTotals, array $stats): array
    {
        $allCurrencies = array_unique(array_merge(array_keys($sourceTotals), array_keys($targetTotals)));
        if (empty($allCurrencies)) {
            $allCurrencies = ['USD'];
        }

        $diffs = [];
        $isReconciled = true;

        foreach ($allCurrencies as $curr) {
            $src = $sourceTotals[$curr] ?? 0.0;
            $tgt = $targetTotals[$curr] ?? 0.0;
            $diff = round($src - $tgt, 2);

            $diffs[$curr] = $diff;
            if ($diff !== 0.0 && abs($diff) > 0.001) {
                $isReconciled = false;
            }
        }

        return [
            'source' => $sourceTotals,
            'target' => $targetTotals,
            'diff' => $diffs,
            'is_reconciled' => $isReconciled,
            'count_staged' => $stats['staged'],
            'count_migrated' => $stats['migrated'],
            'count_quarantined' => $stats['quarantined'],
        ];
    }
}
