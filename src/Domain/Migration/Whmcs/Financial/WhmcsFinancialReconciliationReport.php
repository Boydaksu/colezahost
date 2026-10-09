<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Whmcs\Financial;

use Coleza\Domain\Migration\Staging\StagingAccountingReport;
use JsonSerializable;

/**
 * Encapsulates the zero-diff financial ledger reconciliation report
 * and Zero Silent Data Loss certification for WHMCS financial migration.
 */
final class WhmcsFinancialReconciliationReport implements JsonSerializable
{
    /**
     * @param array{source: array<string, float>, target: array<string, float>, diff: array<string, float>, is_reconciled: bool, count_staged: int, count_migrated: int, count_quarantined: int} $invoiceReconciliation
     * @param array{source: array<string, float>, target: array<string, float>, diff: array<string, float>, is_reconciled: bool, count_staged: int, count_migrated: int, count_quarantined: int} $paymentReconciliation
     * @param array{source: array<string, float>, target: array<string, float>, diff: array<string, float>, is_reconciled: bool, count_staged: int, count_migrated: int, count_quarantined: int} $creditReconciliation
     */
    public function __construct(
        private string $batchId,
        private array $invoiceReconciliation,
        private array $paymentReconciliation,
        private array $creditReconciliation,
        private StagingAccountingReport $stagingReport
    ) {
    }

    public function getBatchId(): string
    {
        return $this->batchId;
    }

    /**
     * @return array{source: array<string, float>, target: array<string, float>, diff: array<string, float>, is_reconciled: bool, count_staged: int, count_migrated: int, count_quarantined: int}
     */
    public function getInvoiceReconciliation(): array
    {
        return $this->invoiceReconciliation;
    }

    /**
     * @return array{source: array<string, float>, target: array<string, float>, diff: array<string, float>, is_reconciled: bool, count_staged: int, count_migrated: int, count_quarantined: int}
     */
    public function getPaymentReconciliation(): array
    {
        return $this->paymentReconciliation;
    }

    /**
     * @return array{source: array<string, float>, target: array<string, float>, diff: array<string, float>, is_reconciled: bool, count_staged: int, count_migrated: int, count_quarantined: int}
     */
    public function getCreditReconciliation(): array
    {
        return $this->creditReconciliation;
    }

    public function getStagingReport(): StagingAccountingReport
    {
        return $this->stagingReport;
    }

    /**
     * Returns true only if invoices, payments, and credit ledgers all exhibit 0.00 diff.
     */
    public function isFinancialReconciled(): bool
    {
        return $this->invoiceReconciliation['is_reconciled']
            && $this->paymentReconciliation['is_reconciled']
            && $this->creditReconciliation['is_reconciled'];
    }

    public function isZeroSilentLossAchieved(): bool
    {
        return $this->stagingReport->isZeroSilentLossAchieved();
    }

    public function isFullyTerminal(): bool
    {
        return $this->stagingReport->isFullyTerminal();
    }

    /**
     * Fully certified when both zero-diff financial reconciliation and zero silent loss are PASS.
     */
    public function isCertified(): bool
    {
        return $this->isFinancialReconciled()
            && $this->isZeroSilentLossAchieved()
            && $this->isFullyTerminal();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'batch_id' => $this->batchId,
            'is_certified' => $this->isCertified(),
            'financial_reconciled' => $this->isFinancialReconciled(),
            'zero_silent_loss_achieved' => $this->isZeroSilentLossAchieved(),
            'fully_terminal' => $this->isFullyTerminal(),
            'unaccounted_diff' => $this->stagingReport->getUnaccountedDiff(),
            'invoices' => $this->invoiceReconciliation,
            'payments' => $this->paymentReconciliation,
            'credits' => $this->creditReconciliation,
            'staging_accounting' => $this->stagingReport->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
