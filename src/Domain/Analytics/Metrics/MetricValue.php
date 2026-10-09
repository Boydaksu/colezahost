<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Metrics;

use JsonSerializable;

/**
 * Concrete evaluated value of a metric for a specific period and slice dimensions.
 */
final class MetricValue implements JsonSerializable
{
    /**
     * @param array<string, mixed> $dimensions
     */
    public function __construct(
        private readonly string $metricKey,
        private readonly float|int $value,
        private readonly ?string $currency = null,
        private readonly ?string $periodStart = null,
        private readonly ?string $periodEnd = null,
        private readonly array $dimensions = [],
        private readonly ?string $calculatedAt = null,
        private readonly bool $isSnapshot = false,
        private readonly ?string $formattedValue = null
    ) {
    }

    public function getMetricKey(): string
    {
        return $this->metricKey;
    }

    public function getValue(): float|int
    {
        return $this->value;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function getPeriodStart(): ?string
    {
        return $this->periodStart;
    }

    public function getPeriodEnd(): ?string
    {
        return $this->periodEnd;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDimensions(): array
    {
        return $this->dimensions;
    }

    public function getCalculatedAt(): string
    {
        return $this->calculatedAt ?? date('Y-m-d H:i:s');
    }

    public function isSnapshot(): bool
    {
        return $this->isSnapshot;
    }

    public function getFormattedValue(): ?string
    {
        return $this->formattedValue;
    }

    public function toArray(): array
    {
        return [
            'metric_key' => $this->metricKey,
            'value' => $this->value,
            'currency' => $this->currency,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'dimensions' => $this->dimensions,
            'calculated_at' => $this->getCalculatedAt(),
            'is_snapshot' => $this->isSnapshot,
            'formatted_value' => $this->formattedValue,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
