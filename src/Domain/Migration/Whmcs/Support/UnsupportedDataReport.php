<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Whmcs\Support;

use JsonSerializable;

/**
 * Audit report verifying the Constitution §11 & §12 Zero Silent Field Loss invariant.
 * Confirms that all unmapped source properties, obsolete modules, or custom fields
 * are explicitly preserved in target metadata without silent dropping.
 */
final class UnsupportedDataReport implements JsonSerializable
{
    /**
     * @param array<string, array{entities_count: int, mapped_fields: int, preserved_unsupported_fields: int, dropped_fields: int, sample_preserved_keys: list<string>}> $byEntityType
     */
    public function __construct(
        private string $batchId,
        private int $totalEntitiesInspected,
        private int $totalMappedFields,
        private int $totalPreservedUnsupportedFields,
        private int $totalDroppedFields,
        private array $byEntityType = []
    ) {
    }

    public function getBatchId(): string
    {
        return $this->batchId;
    }

    public function getTotalEntitiesInspected(): int
    {
        return $this->totalEntitiesInspected;
    }

    public function getTotalMappedFields(): int
    {
        return $this->totalMappedFields;
    }

    public function getTotalPreservedUnsupportedFields(): int
    {
        return $this->totalPreservedUnsupportedFields;
    }

    public function getTotalDroppedFields(): int
    {
        return $this->totalDroppedFields;
    }

    public function getTotalAccountedFields(): int
    {
        return $this->totalMappedFields + $this->totalPreservedUnsupportedFields;
    }

    /**
     * @return array<string, array{entities_count: int, mapped_fields: int, preserved_unsupported_fields: int, dropped_fields: int, sample_preserved_keys: list<string>}>
     */
    public function getByEntityType(): array
    {
        return $this->byEntityType;
    }

    /**
     * Invariant: Zero silent data loss requires exactly 0 dropped fields.
     */
    public function isZeroLossAchieved(): bool
    {
        return $this->totalDroppedFields === 0;
    }

    public function isZeroSilentLossAchieved(): bool
    {
        return $this->isZeroLossAchieved();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'batch_id' => $this->batchId,
            'zero_loss_achieved' => $this->isZeroLossAchieved(),
            'total_entities_inspected' => $this->totalEntitiesInspected,
            'total_mapped_fields' => $this->totalMappedFields,
            'total_preserved_unsupported_fields' => $this->totalPreservedUnsupportedFields,
            'total_dropped_fields' => $this->totalDroppedFields,
            'by_entity_type' => $this->byEntityType,
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
