<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Csv;

use Coleza\Domain\Migration\Staging\StagingPipelineService;

final class CsvImportService
{
    public function __construct(
        private StagingPipelineService $stagingPipeline,
        private ?LocaleAwareCsvParser $parser = null
    ) {
        $this->parser = $parser ?? new LocaleAwareCsvParser();
    }

    /**
     * Ingests, normalizes, stages, and validates a CSV dataset under the specified migration batch.
     */
    public function importCsvString(
        string $csvContent,
        CsvMappingProfile $profile,
        string $batchId,
        ?CsvLocaleConfig $config = null
    ): CsvImportResult {
        $cfg = $config ?? CsvLocaleConfig::autoDetect();
        $parseResult = $this->parser->parseString($csvContent, $cfg);

        $entityType = $profile->getEntityType();
        $rows = $parseResult['rows'];
        $parseErrors = $parseResult['errors'];

        // 1. Ingest successfully parsed rows
        $stagedCount = 0;
        foreach ($rows as $rowIndex => $rawRow) {
            $lineNumber = (int) ($rawRow['_line_number'] ?? ($rowIndex + 2));
            unset($rawRow['_line_number']);

            $mappedPayload = $this->mapAndNormalizeRow($rawRow, $profile, $cfg);

            // Determine source entity ID
            $sourceId = (string) ($mappedPayload['id'] ?? $mappedPayload['client_id'] ?? $mappedPayload['service_id'] ?? "CSV-ROW-{$lineNumber}");

            $this->stagingPipeline->stageRawRecord(
                batchId: $batchId,
                sourceSystem: 'csv',
                sourceEntityType: $entityType,
                sourceEntityId: $sourceId,
                rawPayload: $mappedPayload
            );
            $stagedCount++;
        }

        // 2. Quarantine malformed CSV lines that could not be parsed as valid columns
        // Zero Silent Loss: even corrupted source lines are staged and recorded
        foreach ($parseErrors as $err) {
            $errLine = (int) ($err['line'] ?? 0);
            $errMsg = (string) ($err['message'] ?? 'Malformed CSV line');

            $this->stagingPipeline->stageQuarantinedRecord(
                batchId: $batchId,
                sourceSystem: 'csv',
                sourceEntityType: $entityType,
                sourceEntityId: "CSV-CORRUPTED-L{$errLine}",
                rawPayload: ['raw_error_line' => $errLine, 'parse_error' => $errMsg],
                reason: "CSV Syntax Error on line {$errLine}: {$errMsg}"
            );
        }

        // 3. Process staging pipeline (mapping to canonical DTO + validation)
        $accountingReport = $this->stagingPipeline->processBatchStaging($batchId);

        return new CsvImportResult(
            batchId: $batchId,
            entityType: $entityType,
            totalRowsParsed: count($rows),
            stagedCount: $stagedCount,
            quarantinedCount: $accountingReport->getQuarantinedCount(),
            parseErrors: $parseErrors,
            accountingReport: $accountingReport
        );
    }

    /**
     * @param array<string, mixed> $rawRow
     * @return array<string, mixed>
     */
    private function mapAndNormalizeRow(
        array $rawRow,
        CsvMappingProfile $profile,
        CsvLocaleConfig $config
    ): array {
        $result = $profile->getDefaultValues();
        $unmappedFields = [];

        foreach ($rawRow as $columnHeader => $value) {
            $canonicalField = $profile->mapHeader($columnHeader);

            if ($canonicalField !== null) {
                $normalizedValue = $value;

                // Localized number parsing
                if (in_array($canonicalField, $profile->getNumberFields(), true)) {
                    $parsedNum = $this->parser->parseLocalizedNumber(
                        (string) $value,
                        $config->getDecimalSeparator(),
                        $config->getThousandsSeparator()
                    );
                    $normalizedValue = $parsedNum ?? 0.0;
                }

                // Localized date parsing
                if (in_array($canonicalField, $profile->getDateFields(), true)) {
                    $parsedDate = $this->parser->parseLocalizedDate(
                        (string) $value,
                        $config->getDateFormat()
                    );
                    $normalizedValue = $parsedDate ?? $value;
                }

                $result[$canonicalField] = $normalizedValue;
            } else {
                $unmappedFields[$columnHeader] = $value;
            }
        }

        if (!empty($unmappedFields)) {
            $result['unmapped_source_columns'] = $unmappedFields;
        }

        return $result;
    }
}
