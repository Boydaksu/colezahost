<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Whmcs\Support;

use Coleza\Domain\Migration\Staging\StagingAccountingReport;
use JsonSerializable;

/**
 * Result object encapsulating support migration execution,
 * Unsupported Data Audit Report, and Terminal Staging Accounting.
 */
final class WhmcsSupportMigrationResult implements JsonSerializable
{
    /**
     * @param array{total: int, migrated: int, department_ids: array<string, int>} $departmentStats
     * @param array{total: int, migrated: int, quarantined: int, ticket_ids: array<string, int>, replies_migrated: int, attachments_migrated: int} $ticketStats
     */
    public function __construct(
        private string $batchId,
        private array $departmentStats,
        private array $ticketStats,
        private UnsupportedDataReport $unsupportedDataReport,
        private StagingAccountingReport $stagingReport
    ) {
    }

    public function getBatchId(): string
    {
        return $this->batchId;
    }

    /**
     * @return array{total: int, migrated: int, department_ids: array<string, int>}
     */
    public function getDepartmentStats(): array
    {
        return $this->departmentStats;
    }

    /**
     * @return array{total: int, migrated: int, quarantined: int, ticket_ids: array<string, int>, replies_migrated: int, attachments_migrated: int}
     */
    public function getTicketStats(): array
    {
        return $this->ticketStats;
    }

    public function getUnsupportedDataReport(): UnsupportedDataReport
    {
        return $this->unsupportedDataReport;
    }

    public function getStagingReport(): StagingAccountingReport
    {
        return $this->stagingReport;
    }

    public function isZeroSilentLossAchieved(): bool
    {
        return $this->stagingReport->isZeroSilentLossAchieved();
    }

    public function isZeroFieldLossAchieved(): bool
    {
        return $this->unsupportedDataReport->isZeroLossAchieved();
    }

    public function isFullyTerminal(): bool
    {
        return $this->stagingReport->isFullyTerminal();
    }

    public function isCertified(): bool
    {
        return $this->isZeroSilentLossAchieved()
            && $this->isZeroFieldLossAchieved()
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
            'zero_silent_loss_achieved' => $this->isZeroSilentLossAchieved(),
            'zero_field_loss_achieved' => $this->isZeroFieldLossAchieved(),
            'fully_terminal' => $this->isFullyTerminal(),
            'unaccounted_diff' => $this->stagingReport->getUnaccountedDiff(),
            'departments' => $this->departmentStats,
            'tickets' => $this->ticketStats,
            'unsupported_data_audit' => $this->unsupportedDataReport->toArray(),
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
