<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Mapping;

use Coleza\Domain\Migration\Canonical\CanonicalEntityInterface;

interface MappingEngineInterface
{
    /**
     * Maps raw source payload into a typed Canonical DTO.
     * Preserves unmapped fields into metadata to prevent silent data loss.
     *
     * @param array<string, mixed> $rawPayload
     */
    public function map(string $sourceSystem, string $entityType, array $rawPayload): CanonicalEntityInterface;

    /**
     * Registers a custom field transformer callback for a specific entity type and target field.
     */
    public function registerTransformer(string $entityType, string $field, callable $transformer): void;
}
