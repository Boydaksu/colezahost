<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Execution;

use Coleza\Domain\Migration\Staging\DatabaseStagingRepository;
use Coleza\Domain\Migration\Staging\StagingPipelineService;
use Coleza\Domain\Migration\Staging\StagingRecord;
use Coleza\Domain\Migration\Staging\StagingRecordStatus;
use RuntimeException;

/**
 * Manages the inspection, diagnostic reporting, and remediation of quarantined migration records.
 */
final class QuarantineManager
{
    public function __construct(
        private DatabaseStagingRepository $stagingRepo,
        private StagingPipelineService $stagingPipeline
    ) {
    }

    /**
     * Retrieves all quarantined records for a batch, optionally filtered by entity type.
     *
     * @return list<StagingRecord>
     */
    public function getQuarantinedRecords(string $batchId, ?string $entityType = null): array
    {
        $all = $this->stagingRepo->getBatchRecords($batchId, StagingRecordStatus::QUARANTINED);

        if ($entityType === null) {
            return $all;
        }

        $filtered = [];
        $type = strtolower(trim($entityType));
        foreach ($all as $record) {
            if (strtolower($record->getSourceEntityType()) === $type) {
                $filtered[] = $record;
            }
        }

        return $filtered;
    }

    /**
     * Produces a diagnostic breakdown of quarantine reasons and error frequencies.
     *
     * @return array{total_quarantined: int, by_entity_type: array<string, int>, reasons: array<string, int>}
     */
    public function getQuarantineSummary(string $batchId): array
    {
        $records = $this->getQuarantinedRecords($batchId);
        $byType = [];
        $reasons = [];

        foreach ($records as $r) {
            $type = $r->getSourceEntityType();
            $byType[$type] = ($byType[$type] ?? 0) + 1;

            $reason = (string) ($r->getQuarantineReason() ?? 'Unknown error');
            $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
        }

        return [
            'total_quarantined' => count($records),
            'by_entity_type' => $byType,
            'reasons' => $reasons,
        ];
    }

    /**
     * Remediates a quarantined staging record by applying corrected field overrides
     * and re-running transformation and canonical validation.
     *
     * @param array<string, mixed> $payloadOverrides
     */
    public function remediate(int $stagingRecordId, array $payloadOverrides = []): StagingRecord
    {
        $record = $this->stagingRepo->find($stagingRecordId);
        if ($record === null) {
            throw new RuntimeException("Staging record [{$stagingRecordId}] does not exist.");
        }

        // Apply overrides to raw payload
        $updatedPayload = array_merge($record->getRawPayload(), $payloadOverrides);

        // Reset record to STAGED state
        $remediated = new StagingRecord(
            id: $record->getId(),
            batchId: $record->getBatchId(),
            sourceSystem: $record->getSourceSystem(),
            sourceEntityType: $record->getSourceEntityType(),
            sourceEntityId: $record->getSourceEntityId(),
            rawPayload: $updatedPayload,
            status: StagingRecordStatus::STAGED,
            createdAt: $record->getCreatedAt()
        );

        $this->stagingRepo->save($remediated);

        // Re-run mapping and validation
        return $this->stagingPipeline->transformAndValidate($remediated);
    }
}
