<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Execution;

use Coleza\Domain\Migration\Staging\DatabaseStagingRepository;
use Coleza\Domain\Migration\Staging\StagingAccountingReport;
use Coleza\Domain\Migration\Validation\CanonicalValidationEngine;
use Coleza\Domain\Migration\Whmcs\Core\WhmcsCoreEntityExtractor;
use Coleza\Domain\Migration\Whmcs\Core\WhmcsCoreEntityMigrator;
use Coleza\Domain\Migration\Whmcs\Financial\WhmcsFinancialExtractor;
use Coleza\Domain\Migration\Whmcs\Financial\WhmcsFinancialMigrator;
use Coleza\Domain\Migration\Whmcs\Support\WhmcsSupportExtractor;
use Coleza\Domain\Migration\Whmcs\Support\WhmcsSupportMigrator;
use Coleza\Foundation\Database\Connection;
use RuntimeException;

/**
 * Master migration execution orchestrator providing:
 * - Non-destructive dry-run simulations
 * - Conflict detection & configurable resolution strategies
 * - Step-by-step checkpointing and interrupted session resumption
 * - Absolute idempotency across repeated runs
 * - End-to-end Zero Silent Data Loss certification
 */
final class MigrationExecutionOrchestrator
{
    public function __construct(
        private Connection $targetDb,
        private WhmcsCoreEntityMigrator $coreMigrator,
        private WhmcsFinancialMigrator $financialMigrator,
        private WhmcsSupportMigrator $supportMigrator,
        private ConflictDetector $conflictDetector,
        private MigrationCheckpointRepository $checkpointRepo,
        private QuarantineManager $quarantineManager,
        private DatabaseStagingRepository $stagingRepo,
        private CanonicalValidationEngine $validationEngine,
        private WhmcsCoreEntityExtractor $coreExtractor,
        private WhmcsFinancialExtractor $financialExtractor,
        private WhmcsSupportExtractor $supportExtractor
    ) {
    }

    /**
     * Executes a non-destructive dry-run simulation across all migration entities.
     * Evaluates extractions, canonical validations, potential collisions, and projected totals
     * without modifying production data.
     */
    public function executeDryRun(string $batchId): DryRunReport
    {
        $this->coreMigrator->ensureTargetTables();
        $this->financialMigrator->ensureFinancialTables();
        $this->supportMigrator->ensureSupportTables();

        $inspectedByType = [];
        $projectedMigratedByType = [];
        $projectedQuarantinedByType = [];
        $conflictsDetected = [
            'users' => [],
            'domains' => [],
            'services' => [],
            'invoices' => [],
        ];

        // 1. Inspect Clients
        $clients = $this->coreExtractor->extractClients();
        $inspectedByType['clients'] = count($clients);
        $projectedMigratedByType['clients'] = 0;
        $projectedQuarantinedByType['clients'] = 0;

        foreach ($clients as $c) {
            $email = strtolower(trim((string) ($c['email'] ?? '')));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $projectedQuarantinedByType['clients']++;
                continue;
            }

            // Conflict check
            $existingId = $this->conflictDetector->detectUserEmailConflict($email);
            if ($existingId !== null) {
                $conflictsDetected['users'][] = [
                    'source_id' => $c['id'],
                    'email' => $email,
                    'existing_target_user_id' => $existingId,
                ];
            }
            $projectedMigratedByType['clients']++;
        }

        // 2. Inspect Products
        $products = $this->coreExtractor->extractProducts();
        $inspectedByType['products'] = count($products);
        $projectedMigratedByType['products'] = 0;
        $projectedQuarantinedByType['products'] = 0;

        foreach ($products as $p) {
            if (empty($p['name'])) {
                $projectedQuarantinedByType['products']++;
            } else {
                $projectedMigratedByType['products']++;
            }
        }

        // 3. Inspect Services
        $services = $this->coreExtractor->extractServices();
        $inspectedByType['services'] = count($services);
        $projectedMigratedByType['services'] = 0;
        $projectedQuarantinedByType['services'] = 0;

        foreach ($services as $s) {
            $username = trim((string) ($s['username'] ?? ''));
            $serverId = (int) ($s['server'] ?? 1);
            if ($username !== '') {
                $conflictId = $this->conflictDetector->detectServiceConflict($serverId, $username);
                if ($conflictId !== null) {
                    $conflictsDetected['services'][] = [
                        'source_id' => $s['id'],
                        'server_id' => $serverId,
                        'username' => $username,
                        'existing_service_id' => $conflictId,
                    ];
                }
            }
            $projectedMigratedByType['services']++;
        }

        // 4. Inspect Domains
        $domains = $this->coreExtractor->extractDomains();
        $inspectedByType['domains'] = count($domains);
        $projectedMigratedByType['domains'] = 0;
        $projectedQuarantinedByType['domains'] = 0;

        foreach ($domains as $d) {
            $domainName = strtolower(trim((string) ($d['domain'] ?? '')));
            if ($domainName === '' || !str_contains($domainName, '.') || str_contains($domainName, ' ')) {
                $projectedQuarantinedByType['domains']++;
                continue;
            }

            $conflictId = $this->conflictDetector->detectDomainConflict($domainName);
            if ($conflictId !== null) {
                $conflictsDetected['domains'][] = [
                    'source_id' => $d['id'],
                    'domain' => $domainName,
                    'existing_domain_id' => $conflictId,
                ];
            }
            $projectedMigratedByType['domains']++;
        }

        // 5. Inspect Invoices
        $invoices = $this->financialExtractor->extractInvoices();
        $inspectedByType['invoices'] = count($invoices);
        $projectedMigratedByType['invoices'] = 0;
        $projectedQuarantinedByType['invoices'] = 0;

        foreach ($invoices as $inv) {
            $invNum = (string) (!empty($inv['invoicenum']) ? $inv['invoicenum'] : 'WHMCS-INV-' . $inv['id']);
            $conflictId = $this->conflictDetector->detectInvoiceNumberConflict($invNum);
            if ($conflictId !== null) {
                $conflictsDetected['invoices'][] = [
                    'source_id' => $inv['id'],
                    'invoice_number' => $invNum,
                    'existing_invoice_id' => $conflictId,
                ];
            }
            $projectedMigratedByType['invoices']++;
        }

        // 6. Inspect Payments & Credits
        $txns = $this->financialExtractor->extractTransactions();
        $inspectedByType['payments'] = count($txns);
        $projectedMigratedByType['payments'] = count($txns);
        $projectedQuarantinedByType['payments'] = 0;

        $credits = $this->financialExtractor->extractClientCredits();
        $inspectedByType['credits'] = count($credits);
        $projectedMigratedByType['credits'] = count($credits);
        $projectedQuarantinedByType['credits'] = 0;

        // 7. Projected Financial Totals
        $projectedFinancials = [
            'invoices' => $this->financialExtractor->calculateSourceInvoiceTotals(),
            'payments' => $this->financialExtractor->calculateSourcePaymentTotals(),
            'credits' => $this->financialExtractor->calculateSourceCreditTotals(),
        ];

        $totalInspected = array_sum($inspectedByType);
        $totalProjectedMigrated = array_sum($projectedMigratedByType);
        $totalProjectedQuarantined = array_sum($projectedQuarantinedByType);

        return new DryRunReport(
            batchId: $batchId,
            totalInspected: $totalInspected,
            totalProjectedMigrated: $totalProjectedMigrated,
            totalProjectedQuarantined: $totalProjectedQuarantined,
            inspectedByType: $inspectedByType,
            projectedMigratedByType: $projectedMigratedByType,
            projectedQuarantinedByType: $projectedQuarantinedByType,
            conflictsDetected: $conflictsDetected,
            projectedFinancialTotals: $projectedFinancials
        );
    }

    /**
     * Executes the live migration with step checkpointing, resumption support,
     * and complete terminal accounting.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function executeLive(string $batchId, array $options = []): array
    {
        $this->coreMigrator->ensureTargetTables();
        $this->financialMigrator->ensureFinancialTables();
        $this->supportMigrator->ensureSupportTables();
        $this->checkpointRepo->ensureTable();

        $checkpoint = $this->checkpointRepo->find($batchId);
        if ($checkpoint === null) {
            $checkpoint = new MigrationCheckpoint(
                batchId: $batchId,
                currentStep: 'clients',
                status: 'in_progress'
            );
            $this->checkpointRepo->save($checkpoint);
        }

        $step = $checkpoint->getCurrentStep();
        $stepResults = [];

        // Step 1: Clients & Organizations
        if ($step === 'clients') {
            $stepResults['clients'] = $this->coreMigrator->migrateClients($batchId, $options);
            $checkpoint->stepComplete('products');
            $this->checkpointRepo->save($checkpoint);
            $step = 'products';
        }

        // Step 2: Products & Groups
        if ($step === 'products') {
            $stepResults['products'] = $this->coreMigrator->migrateProducts($batchId, $options);
            $checkpoint->stepComplete('services');
            $this->checkpointRepo->save($checkpoint);
            $step = 'services';
        }

        // Step 3: Services (Hosting Accounts)
        if ($step === 'services') {
            $stepResults['services'] = $this->coreMigrator->migrateServices($batchId, $options);
            $checkpoint->stepComplete('domains');
            $this->checkpointRepo->save($checkpoint);
            $step = 'domains';
        }

        // Step 4: Domains
        if ($step === 'domains') {
            $stepResults['domains'] = $this->coreMigrator->migrateDomains($batchId, $options);
            $checkpoint->stepComplete('invoices');
            $this->checkpointRepo->save($checkpoint);
            $step = 'invoices';
        }

        // Step 5: Invoices & Items
        if ($step === 'invoices') {
            $stepResults['invoices'] = $this->financialMigrator->migrateInvoices($batchId, $options);
            $checkpoint->stepComplete('payments');
            $this->checkpointRepo->save($checkpoint);
            $step = 'payments';
        }

        // Step 6: Payments & Allocations
        if ($step === 'payments') {
            $stepResults['payments'] = $this->financialMigrator->migratePayments($batchId, $options);
            $checkpoint->stepComplete('credits');
            $this->checkpointRepo->save($checkpoint);
            $step = 'credits';
        }

        // Step 7: Credit Balances
        if ($step === 'credits') {
            $stepResults['credits'] = $this->financialMigrator->migrateCredits($batchId, $options);
            $checkpoint->stepComplete('support');
            $this->checkpointRepo->save($checkpoint);
            $step = 'support';
        }

        // Step 8: Support Departments & Tickets
        if ($step === 'support') {
            $stepResults['departments'] = $this->supportMigrator->migrateDepartments();
            $stepResults['tickets'] = $this->supportMigrator->migrateTickets($batchId, $options);
            $checkpoint->markCompleted();
            $this->checkpointRepo->save($checkpoint);
        }

        // Final Terminal Staging Accounting
        $accountingReport = $this->stagingRepo->generateAccountingReport($batchId);

        if (!$accountingReport->isZeroSilentLossAchieved()) {
            throw new RuntimeException(sprintf(
                'Zero Silent Loss violated in batch [%s]: unaccounted diff = %d',
                $batchId,
                $accountingReport->getUnaccountedDiff()
            ));
        }

        return [
            'batch_id' => $batchId,
            'status' => 'completed',
            'checkpoint' => $checkpoint->toArray(),
            'steps' => $stepResults,
            'accounting_report' => $accountingReport->toArray(),
            'zero_silent_loss_achieved' => $accountingReport->isZeroSilentLossAchieved(),
            'fully_terminal' => $accountingReport->isFullyTerminal(),
        ];
    }
}
