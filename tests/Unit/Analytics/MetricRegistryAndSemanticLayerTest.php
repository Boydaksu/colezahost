<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Analytics;

use Coleza\Domain\Analytics\Metrics\AggregationType;
use Coleza\Domain\Analytics\Metrics\MetricCategory;
use Coleza\Domain\Analytics\Metrics\MetricDataType;
use Coleza\Domain\Analytics\Metrics\MetricDefinition;
use Coleza\Domain\Analytics\Metrics\MetricRegistry;
use Coleza\Domain\Analytics\Metrics\TimeGrain;
use Coleza\Domain\Analytics\Semantic\SemanticLayerService;
use Coleza\Foundation\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

final class MetricRegistryAndSemanticLayerTest extends TestCase
{
    private MetricRegistry $registry;
    private SemanticLayerService $semanticService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new MetricRegistry(registerDefaults: true);
        $this->semanticService = new SemanticLayerService($this->registry);
    }

    public function testDefaultMetricsLoadedInRegistry(): void
    {
        $this->assertGreaterThanOrEqual(15, $this->registry->count());

        $this->assertTrue($this->registry->has('invoiced_gross'));
        $this->assertTrue($this->registry->has('invoiced_net'));
        $this->assertTrue($this->registry->has('invoiced_tax'));
        $this->assertTrue($this->registry->has('outstanding_receivables'));

        $this->assertTrue($this->registry->has('cash_inflow'));
        $this->assertTrue($this->registry->has('cash_refunded'));
        $this->assertTrue($this->registry->has('net_cash_flow'));

        $this->assertTrue($this->registry->has('mrr'));
        $this->assertTrue($this->registry->has('arr'));
        $this->assertTrue($this->registry->has('new_mrr'));
        $this->assertTrue($this->registry->has('churned_mrr'));
        $this->assertTrue($this->registry->has('active_subscriptions_count'));

        $this->assertTrue($this->registry->has('tickets_opened_count'));
        $this->assertTrue($this->registry->has('sla_compliance_rate'));
    }

    /**
     * Enforces the architectural decision in 00-overview/DECISION_REGISTER.md:
     * "Analytics Metric Registry/Semantic Layer; revenue/cash/invoice ayrımı."
     */
    public function testRevenueCashInvoiceSeparationSemantics(): void
    {
        // 1. Invoice Domain / Billed Claims
        $invoiced = $this->registry->get('invoiced_gross');
        $this->assertNotNull($invoiced);
        $this->assertSame(MetricCategory::INVOICE, $invoiced->getCategory());
        $this->assertSame('billing', $invoiced->getSourceDomain());
        $this->assertSame(AggregationType::SUM, $invoiced->getAggregationType());

        // 2. Cash Domain / Real Liquidity Settlements
        $cash = $this->registry->get('cash_inflow');
        $this->assertNotNull($cash);
        $this->assertSame(MetricCategory::CASH, $cash->getCategory());
        $this->assertSame('finance', $cash->getSourceDomain());
        $this->assertContains('gateway', $cash->getAllowedDimensions());

        // 3. Subscription Domain / Normalized MRR Run-Rate
        $mrr = $this->registry->get('mrr');
        $this->assertNotNull($mrr);
        $this->assertSame(MetricCategory::SUBSCRIPTION, $mrr->getCategory());
        $this->assertSame('services', $mrr->getSourceDomain());
        $this->assertSame(AggregationType::SNAPSHOT, $mrr->getAggregationType());

        // Ensure each belongs to distinct categories
        $this->assertNotSame($invoiced->getCategory(), $cash->getCategory());
        $this->assertNotSame($invoiced->getCategory(), $mrr->getCategory());
        $this->assertNotSame($cash->getCategory(), $mrr->getCategory());
    }

    public function testCustomMetricRegistration(): void
    {
        $customMetric = new MetricDefinition(
            key: 'server_cpu_utilization_avg',
            name: 'Average Server CPU Utilization',
            description: 'Average CPU utilization across all active hypervisors.',
            category: MetricCategory::OPERATIONS,
            dataType: MetricDataType::PERCENTAGE,
            aggregationType: AggregationType::AVERAGE,
            sourceDomain: 'provisioning',
            formula: 'AVG(server_metrics.cpu_percent)',
            grain: TimeGrain::HOURLY,
            allowedDimensions: ['server_id', 'datacenter'],
            unit: '%'
        );

        $this->registry->register($customMetric);

        $this->assertTrue($this->registry->has('server_cpu_utilization_avg'));
        $retrieved = $this->registry->get('server_cpu_utilization_avg');
        $this->assertSame('Average Server CPU Utilization', $retrieved->getName());
        $this->assertSame(MetricCategory::OPERATIONS, $retrieved->getCategory());

        $operationsMetrics = $this->registry->getByCategory(MetricCategory::OPERATIONS);
        $this->assertArrayHasKey('server_cpu_utilization_avg', $operationsMetrics);
    }

    public function testQueryValidationWithAllowedAndForbiddenDimensions(): void
    {
        // Valid query with allowed dimensions
        $this->registry->validateQuery('invoiced_gross', ['currency', 'country']);

        // Unknown metric throws ValidationException
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Unknown metric "non_existent_metric"');
        $this->registry->validateQuery('non_existent_metric', ['currency']);
    }

    public function testQueryValidationRejectsInvalidDimensions(): void
    {
        // 'invoiced_gross' does not allow 'hypervisor_id'
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Dimensions [hypervisor_id] are not permitted for metric "invoiced_gross"');
        $this->registry->validateQuery('invoiced_gross', ['hypervisor_id']);
    }

    public function testSemanticLayerValueFormatting(): void
    {
        // Currency formatting
        $this->assertSame('$1,250.50', $this->semanticService->formatValue('invoiced_gross', 1250.50, 'USD'));
        $this->assertSame('€500.00', $this->semanticService->formatValue('invoiced_gross', 500, 'EUR'));
        $this->assertSame('₺4,999.90', $this->semanticService->formatValue('cash_inflow', 4999.90, 'TRY'));

        // Percentage formatting
        $this->assertSame('98.75%', $this->semanticService->formatValue('sla_compliance_rate', 98.75));

        // Count formatting
        $this->assertSame('1,420', $this->semanticService->formatValue('invoices_issued_count', 1420));

        // Duration formatting
        $this->assertSame('45.0 minutes', $this->semanticService->formatValue('first_response_time_avg', 45));
    }

    public function testDerivedMetricCalculations(): void
    {
        // Net Cash Flow = Cash Inflow - Cash Refunded
        $netCash = $this->semanticService->computeDerivedMetric(
            'net_cash_flow',
            ['cash_inflow' => 15000.0, 'cash_refunded' => 1200.0],
            'USD'
        );

        $this->assertSame('net_cash_flow', $netCash->getMetricKey());
        $this->assertSame(13800.0, $netCash->getValue());
        $this->assertSame('$13,800.00', $netCash->getFormattedValue());

        // ARR = MRR * 12
        $arr = $this->semanticService->computeDerivedMetric(
            'arr',
            ['mrr' => 2500.0],
            'USD'
        );
        $this->assertSame('arr', $arr->getMetricKey());
        $this->assertSame(30000.0, $arr->getValue());
        $this->assertSame('$30,000.00', $arr->getFormattedValue());
    }

    public function testSemanticCatalogIntrospection(): void
    {
        $catalog = $this->semanticService->getSemanticCatalog();

        $this->assertArrayHasKey('invoice', $catalog);
        $this->assertArrayHasKey('cash', $catalog);
        $this->assertArrayHasKey('subscription', $catalog);
        $this->assertArrayHasKey('support', $catalog);

        $this->assertGreaterThanOrEqual(4, $catalog['invoice']['count']);
        $this->assertGreaterThanOrEqual(3, $catalog['cash']['count']);
        $this->assertGreaterThanOrEqual(5, $catalog['subscription']['count']);

        // Test JSON serialization of catalog
        $json = json_encode($catalog, JSON_THROW_ON_ERROR);
        $this->assertJson($json);
    }
}
