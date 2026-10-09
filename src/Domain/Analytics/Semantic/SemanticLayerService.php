<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Semantic;

use Coleza\Domain\Analytics\Metrics\MetricCategory;
use Coleza\Domain\Analytics\Metrics\MetricDataType;
use Coleza\Domain\Analytics\Metrics\MetricDefinition;
use Coleza\Domain\Analytics\Metrics\MetricRegistry;
use Coleza\Domain\Analytics\Metrics\MetricValue;
use Coleza\Foundation\Exceptions\ValidationException;

/**
 * Semantic Layer Service providing canonical formatting, catalog introspection,
 * derived metric computation, and query boundary validation.
 */
final class SemanticLayerService
{
    public function __construct(
        private readonly MetricRegistry $registry
    ) {
    }

    public function getRegistry(): MetricRegistry
    {
        return $this->registry;
    }

    /**
     * Formats a raw numeric value according to its MetricDefinition.
     */
    public function formatValue(string $metricKey, float|int $value, ?string $currency = 'USD'): string
    {
        $definition = $this->registry->get($metricKey);
        if ($definition === null) {
            return (string) $value;
        }

        return match ($definition->getDataType()) {
            MetricDataType::CURRENCY => $this->formatCurrency($value, $currency ?? 'USD'),
            MetricDataType::PERCENTAGE => number_format((float) $value, 2) . '%',
            MetricDataType::COUNT => number_format((float) $value, 0),
            MetricDataType::RATIO => number_format((float) $value, 3),
            MetricDataType::DURATION => number_format((float) $value, 1) . ' ' . ($definition->getUnit() ?? 'min'),
        };
    }

    /**
     * Builds a structured MetricValue object with canonical formatting.
     *
     * @param array<string, mixed> $dimensions
     */
    public function createMetricValue(
        string $metricKey,
        float|int $value,
        ?string $currency = 'USD',
        ?string $periodStart = null,
        ?string $periodEnd = null,
        array $dimensions = []
    ): MetricValue {
        $this->registry->validateQuery($metricKey, array_keys($dimensions));

        $definition = $this->registry->get($metricKey);
        $formatted = $this->formatValue($metricKey, $value, $currency);

        return new MetricValue(
            metricKey: $metricKey,
            value: $value,
            currency: $currency,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            dimensions: $dimensions,
            calculatedAt: date('Y-m-d H:i:s'),
            isSnapshot: $definition?->getAggregationType()->value === 'snapshot',
            formattedValue: $formatted
        );
    }

    /**
     * Computes derived metrics from component values.
     *
     * @param string $metricKey e.g. 'net_cash_flow', 'arr', 'net_mrr_growth'
     * @param array<string, float|int> $components Map of component metricKey => value
     * @throws ValidationException
     */
    public function computeDerivedMetric(string $metricKey, array $components, ?string $currency = 'USD'): MetricValue
    {
        $definition = $this->registry->get($metricKey);
        if ($definition === null || !$definition->isDerived()) {
            throw new ValidationException(
                ['metric' => [sprintf('Metric "%s" is not a recognized derived metric.', $metricKey)]],
                'Invalid derived metric.'
            );
        }

        $calculatedValue = match ($metricKey) {
            'net_cash_flow' => (float) ($components['cash_inflow'] ?? 0.0) - (float) ($components['cash_refunded'] ?? 0.0),
            'arr' => (float) ($components['mrr'] ?? 0.0) * 12.0,
            default => throw new ValidationException(['metric' => ["No calculation formula implemented for derived metric {$metricKey}."]], 'Unsupported derived metric.')
        };

        return $this->createMetricValue(
            metricKey: $metricKey,
            value: $calculatedValue,
            currency: $currency
        );
    }

    /**
     * Returns a machine-readable catalog of all semantic definitions
     * grouped by category, suitable for UI dashboard builders and BI consumers.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getSemanticCatalog(): array
    {
        $catalog = [];
        foreach (MetricCategory::cases() as $category) {
            $metrics = $this->registry->getByCategory($category);
            $catalog[$category->value] = [
                'category_name' => ucfirst($category->value),
                'count' => count($metrics),
                'metrics' => array_map(fn(MetricDefinition $d) => $d->toArray(), array_values($metrics)),
            ];
        }

        return $catalog;
    }

    private function formatCurrency(float|int $amount, string $currency): string
    {
        $formattedNum = number_format((float) $amount, 2);
        return match (strtoupper($currency)) {
            'USD' => '$' . $formattedNum,
            'EUR' => '€' . $formattedNum,
            'GBP' => '£' . $formattedNum,
            'TRY' => '₺' . $formattedNum,
            default => $formattedNum . ' ' . $currency,
        };
    }
}
