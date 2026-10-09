<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Csv;

use Coleza\Domain\Migration\Staging\StagingAccountingReport;
use JsonSerializable;

final class CsvImportResult implements JsonSerializable
{
    /**
     * @param list<array{line: int, message: string}> $parseErrors
     */
    public function __construct(
        private string $batchId,
        private string $entityType,
        private int $totalRowsParsed,
        private int $stagedCount,
        private int $quarantinedCount,
        private array $parseErrors,
        private ?StagingAccountingReport $accountingReport = null
    ) {
    }

    public function getBatchId(): string
    {
        return $this->batchId;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getTotalRowsParsed(): int
    {
        return $this->totalRowsParsed;
    }

    public function getStagedCount(): int
    {
        return $this->stagedCount;
    }

    public function getQuarantinedCount(): int
    {
        return $this->quarantinedCount;
    }

    /**
     * @return list<array{line: int, message: string}>
     */
    public function getParseErrors(): array
    {
        return $this->parseErrors;
    }

    public function getAccountingReport(): ?StagingAccountingReport
    {
        return $this->accountingReport;
    }

    public function isSuccess(): bool
    {
        return empty($this->parseErrors) && ($this->accountingReport === null || $this->accountingReport->isZeroSilentLossAchieved());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'batch_id' => $this->batchId,
            'entity_type' => $this->entityType,
            'total_rows_parsed' => $this->totalRowsParsed,
            'staged_count' => $this->stagedCount,
            'quarantined_count' => $this->quarantinedCount,
            'parse_errors' => $this->parseErrors,
            'is_success' => $this->isSuccess(),
            'accounting_report' => $this->accountingReport?->toArray(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
