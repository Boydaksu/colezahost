<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Metrics;

use JsonSerializable;

/**
 * Formal, unambiguous Semantic Layer definition of a metric.
 */
final class MetricDefinition implements JsonSerializable
{
    /**
     * @param array<string> $allowedDimensions
     */
    public function __construct(
        private readonly string $key,
        private readonly string $name,
        private readonly string $description,
        private readonly MetricCategory $category,
        private readonly MetricDataType $dataType,
        private readonly AggregationType $aggregationType,
        private readonly string $sourceDomain,
        private readonly string $formula,
        private readonly TimeGrain $grain,
        private readonly array $allowedDimensions = [],
        private readonly bool $isDerived = false,
        private readonly ?string $unit = null
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getCategory(): MetricCategory
    {
        return $this->category;
    }

    public function getDataType(): MetricDataType
    {
        return $this->dataType;
    }

    public function getAggregationType(): AggregationType
    {
        return $this->aggregationType;
    }

    public function getSourceDomain(): string
    {
        return $this->sourceDomain;
    }

    public function getFormula(): string
    {
        return $this->formula;
    }

    public function getGrain(): TimeGrain
    {
        return $this->grain;
    }

    /**
     * @return array<string>
     */
    public function getAllowedDimensions(): array
    {
        return $this->allowedDimensions;
    }

    public function isDerived(): bool
    {
        return $this->isDerived;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    public function supportsDimension(string $dimension): bool
    {
        return in_array($dimension, $this->allowedDimensions, true);
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category->value,
            'data_type' => $this->dataType->value,
            'aggregation_type' => $this->aggregationType->value,
            'source_domain' => $this->sourceDomain,
            'formula' => $this->formula,
            'grain' => $this->grain->value,
            'allowed_dimensions' => $this->allowedDimensions,
            'is_derived' => $this->isDerived,
            'unit' => $this->unit,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
