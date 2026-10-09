<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Whmcs\Core;

use Coleza\Domain\Migration\Staging\StagingAccountingReport;
use JsonSerializable;

/**
 * Encapsulates the execution result and Zero Silent Data Loss certification
 * of a WHMCS core entities migration batch.
 */
final class WhmcsCoreEntityMigrationResult implements JsonSerializable
{
    /**
     * @param array{total: int, migrated: int, quarantined: int, user_ids: array<string, int>} $clientStats
     * @param array{total: int, migrated: int, quarantined: int, product_ids: array<string, int>} $productStats
     * @param array{total: int, migrated: int, quarantined: int, service_ids: array<string, int>} $serviceStats
     * @param array{total: int, migrated: int, quarantined: int, domain_ids: array<string, int>} $domainStats
     */
    public function __construct(
        private string $batchId,
        private array $clientStats,
        private array $productStats,
        private array $serviceStats,
        private array $domainStats,
        private StagingAccountingReport $accountingReport
    ) {
    }

    public function getBatchId(): string
    {
        return $this->batchId;
    }

    /**
     * @return array{total: int, migrated: int, quarantined: int, user_ids: array<string, int>}
     */
    public function getClientStats(): array
    {
        return $this->clientStats;
    }

    /**
     * @return array{total: int, migrated: int, quarantined: int, product_ids: array<string, int>}
     */
    public function getProductStats(): array
    {
        return $this->productStats;
    }

    /**
     * @return array{total: int, migrated: int, quarantined: int, service_ids: array<string, int>}
     */
    public function getServiceStats(): array
    {
        return $this->serviceStats;
    }

    /**
     * @return array{total: int, migrated: int, quarantined: int, domain_ids: array<string, int>}
     */
    public function getDomainStats(): array
    {
        return $this->domainStats;
    }

    public function getAccountingReport(): StagingAccountingReport
    {
        return $this->accountingReport;
    }

    public function isZeroSilentLossAchieved(): bool
    {
        return $this->accountingReport->isZeroSilentLossAchieved();
    }

    public function isFullyTerminal(): bool
    {
        return $this->accountingReport->isFullyTerminal();
    }

    public function getTotalStaged(): int
    {
        return $this->accountingReport->getTotalStagedRecords();
    }

    public function getTotalMigrated(): int
    {
        return $this->accountingReport->getMigratedCount();
    }

    public function getTotalQuarantined(): int
    {
        return $this->accountingReport->getQuarantinedCount();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'batch_id' => $this->batchId,
            'zero_silent_loss_achieved' => $this->isZeroSilentLossAchieved(),
            'fully_terminal' => $this->isFullyTerminal(),
            'unaccounted_diff' => $this->accountingReport->getUnaccountedDiff(),
            'total_staged' => $this->getTotalStaged(),
            'total_migrated' => $this->getTotalMigrated(),
            'total_quarantined' => $this->getTotalQuarantined(),
            'clients' => $this->clientStats,
            'products' => $this->productStats,
            'services' => $this->serviceStats,
            'domains' => $this->domainStats,
            'accounting_report' => $this->accountingReport->toArray(),
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
