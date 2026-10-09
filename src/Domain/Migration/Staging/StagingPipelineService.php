<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Staging;

use Coleza\Domain\Migration\Mapping\MappingEngineInterface;
use Coleza\Domain\Migration\Validation\CanonicalValidationEngine;

final class StagingPipelineService
{
    public function __construct(
        private StagingRepositoryInterface $repository,
        private MappingEngineInterface $mappingEngine,
        private CanonicalValidationEngine $validationEngine
    ) {
    }

    /**
     * Ingests a raw source record into the staging store.
     *
     * @param array<string, mixed> $rawPayload
     */
    public function stageRawRecord(
        string $batchId,
        string $sourceSystem,
        string $sourceEntityType,
        string $sourceEntityId,
        array $rawPayload
    ): StagingRecord {
        $record = StagingRecord::createNew(
            batchId: $batchId,
            sourceSystem: $sourceSystem,
            sourceEntityType: $sourceEntityType,
            sourceEntityId: $sourceEntityId,
            rawPayload: $rawPayload
        );

        $this->repository->save($record);
        return $record;
    }

    /**
     * Ingests a collection of raw source records into the staging store.
     *
     * @param list<array{id?: string|int, client_id?: string|int, [key: string]: mixed}> $records
     * @param callable(array<string, mixed>): string $idExtractor
     */
    public function stageBatch(
        string $batchId,
        string $sourceSystem,
        string $sourceEntityType,
        array $records,
        ?callable $idExtractor = null
    ): int {
        $count = 0;
        foreach ($records as $raw) {
            $sourceId = $idExtractor !== null
                ? $idExtractor($raw)
                : (string) ($raw['id'] ?? $raw['client_id'] ?? $raw['userid'] ?? $count + 1);

            $this->stageRawRecord(
                batchId: $batchId,
                sourceSystem: $sourceSystem,
                sourceEntityType: $sourceEntityType,
                sourceEntityId: $sourceId,
                rawPayload: $raw
            );
            $count++;
        }

        return $count;
    }

    /**
     * Executes mapping into canonical DTO and subsequent validation for a single staging record.
     * If validation fails, records are quarantined with exact errors (no silent drop!).
     */
    public function transformAndValidate(StagingRecord $record): StagingRecord
    {
        try {
            $canonicalDto = $this->mappingEngine->map(
                sourceSystem: $record->getSourceSystem(),
                entityType: $record->getSourceEntityType(),
                rawPayload: $record->getRawPayload()
            );

            $valResult = $this->validationEngine->validate($canonicalDto);

            if ($valResult->isValid()) {
                $record->markTransformed($canonicalDto->getEntityType(), $canonicalDto->toArray());
                $record->markValidated();
            } else {
                $record->markTransformed($canonicalDto->getEntityType(), $canonicalDto->toArray());
                $record->markQuarantined(
                    reason: 'Canonical validation failure.',
                    errors: $valResult->getErrors()
                );
            }
        } catch (\Throwable $e) {
            $record->markFailed('Mapping exception: ' . $e->getMessage());
        }

        $this->repository->save($record);
        return $record;
    }

    /**
     * Processes all STAGED records in a batch, mapping and validating each,
     * and returns the resulting Zero Silent Loss accounting report.
     */
    public function processBatchStaging(string $batchId): StagingAccountingReport
    {
        $stagedRecords = $this->repository->getBatchRecords($batchId, StagingRecordStatus::STAGED);

        foreach ($stagedRecords as $record) {
            $this->transformAndValidate($record);
        }

        return $this->repository->generateAccountingReport($batchId);
    }
}
