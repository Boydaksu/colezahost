<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Metrics;

use Coleza\Foundation\Exceptions\ValidationException;

/**
 * Metric Registry enforcing the Semantic Layer:
 * - Authoritative catalog of all business metrics.
 * - Strict separation between Invoice (claims), Cash (liquidity), and Subscription/MRR (run-rate).
 * - Dimension compatibility validation.
 */
final class MetricRegistry
{
    /** @var array<string, MetricDefinition> */
    private array $definitions = [];

    public function __construct(bool $registerDefaults = true)
    {
        if ($registerDefaults) {
            $this->registerDefaultMetrics();
        }
    }

    public function register(MetricDefinition $definition): void
    {
        $this->definitions[$definition->getKey()] = $definition;
    }

    public function get(string $key): ?MetricDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    /**
     * @return array<string, MetricDefinition>
     */
    public function getByCategory(MetricCategory $category): array
    {
        return array_filter(
            $this->definitions,
            fn(MetricDefinition $d) => $d->getCategory() === $category
        );
    }

    /**
     * @return array<string, MetricDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    public function count(): int
    {
        return count($this->definitions);
    }

    /**
     * Validates that the requested metric exists and that requested dimensions are valid.
     *
     * @param string $metricKey
     * @param array<string> $dimensions
     * @throws ValidationException
     */
    public function validateQuery(string $metricKey, array $dimensions = []): void
    {
        $definition = $this->get($metricKey);
        if ($definition === null) {
            throw new ValidationException(
                ['metric' => [sprintf('Unknown metric "%s". Not registered in Metric Registry.', $metricKey)]],
                sprintf('Unknown metric "%s".', $metricKey)
            );
        }

        $invalidDimensions = [];
        foreach ($dimensions as $dim) {
            if (!$definition->supportsDimension($dim)) {
                $invalidDimensions[] = $dim;
            }
        }

        if (!empty($invalidDimensions)) {
            $msg = sprintf('Dimensions [%s] are not permitted for metric "%s". Allowed: [%s].', implode(', ', $invalidDimensions), $metricKey, implode(', ', $definition->getAllowedDimensions()));
            throw new ValidationException(
                ['dimensions' => [$msg]],
                $msg
            );
        }
    }

    /**
     * Registers canonical system metrics enforcing revenue/cash/invoice separation.
     */
    private function registerDefaultMetrics(): void
    {
        // -------------------------------------------------------------
        // 1. INVOICE METRICS (Accounting Claims / Billed Volume)
        // -------------------------------------------------------------
        $this->register(new MetricDefinition(
            key: 'invoiced_gross',
            name: 'Invoiced Gross Volume',
            description: 'Total monetary face value of all invoices issued during the period, inclusive of taxes and discounts.',
            category: MetricCategory::INVOICE,
            dataType: MetricDataType::CURRENCY,
            aggregationType: AggregationType::SUM,
            sourceDomain: 'billing',
            formula: 'SUM(invoices.total_amount) WHERE status IN (unpaid, paid, partial)',
            grain: TimeGrain::DAILY,
            allowedDimensions: ['currency', 'customer_id', 'country', 'status'],
            unit: 'currency'
        ));

        $this->register(new MetricDefinition(
            key: 'invoiced_net',
            name: 'Invoiced Net Revenue Claim',
            description: 'Total value of invoices issued excluding sales tax and VAT obligations.',
            category: MetricCategory::INVOICE,
            dataType: MetricDataType::CURRENCY,
            aggregationType: AggregationType::SUM,
            sourceDomain: 'billing',
            formula: 'SUM(invoices.subtotal_amount) WHERE status IN (unpaid, paid, partial)',
            grain: TimeGrain::DAILY,
            allowedDimensions: ['currency', 'customer_id', 'country'],
            unit: 'currency'
        ));

        $this->register(new MetricDefinition(
            key: 'invoiced_tax',
            name: 'Invoiced Tax Billed',
            description: 'Total sales tax / VAT billed on customer invoices during the period.',
            category: MetricCategory::INVOICE,
            dataType: MetricDataType::CURRENCY,
            aggregationType: AggregationType::SUM,
            sourceDomain: 'billing',
            formula: 'SUM(invoices.tax_amount)',
            grain: TimeGrain::DAILY,
            allowedDimensions: ['currency', 'country', 'tax_rate'],
            unit: 'currency'
        ));

        $this->register(new MetricDefinition(
            key: 'invoices_issued_count',
            name: 'Invoices Issued Count',
            description: 'Total number of final invoices generated and delivered.',
            category: MetricCategory::INVOICE,
            dataType: MetricDataType::COUNT,
            aggregationType: AggregationType::COUNT,
            sourceDomain: 'billing',
            formula: 'COUNT(invoices.id)',
            grain: TimeGrain::DAILY,
            allowedDimensions: ['currency', 'status'],
            unit: 'invoices'
        ));

        $this->register(new MetricDefinition(
            key: 'outstanding_receivables',
            name: 'Outstanding Receivables',
            description: 'Total monetary amount of unpaid invoices past due or awaiting payment settlement.',
            category: MetricCategory::INVOICE,
            dataType: MetricDataType::CURRENCY,
            aggregationType: AggregationType::SNAPSHOT,
            sourceDomain: 'billing',
            formula: 'SUM(invoices.total_amount - invoices.amount_paid) WHERE status IN (unpaid, partial)',
            grain: TimeGrain::POINT_IN_TIME,
            allowedDimensions: ['currency', 'aging_bucket', 'customer_id'],
            unit: 'currency'
        ));

        // -------------------------------------------------------------
        // 2. CASH METRICS (Real Bank & Gateway Liquidity Flows)
        // -------------------------------------------------------------
        $this->register(new MetricDefinition(
            key: 'cash_inflow',
            name: 'Cash Collected (Inflow)',
            description: 'Actual monetary funds successfully captured across payment gateways and bank transfers during the period.',
            category: MetricCategory::CASH,
            dataType: MetricDataType::CURRENCY,
            aggregationType: AggregationType::SUM,
            sourceDomain: 'finance',
            formula: 'SUM(payments.amount) WHERE status = "captured" AND type = "charge"',
            grain: TimeGrain::DAILY,
            allowedDimensions: ['currency', 'gateway', 'payment_method', 'country'],
            unit: 'currency'
        ));

        $this->register(new MetricDefinition(
            key: 'cash_refunded',
            name: 'Cash Refunded (Outflow)',
            description: 'Actual money refunded back to customers via payment gateways or manual disbursement.',
            category: MetricCategory::CASH,
            dataType: MetricDataType::CURRENCY,
            aggregationType: AggregationType::SUM,
            sourceDomain: 'finance',
            formula: 'SUM(refunds.amount) WHERE status = "completed"',
            grain: TimeGrain::DAILY,
            allowedDimensions: ['currency', 'gateway', 'reason'],
            unit: 'currency'
        ));

        $this->register(new MetricDefinition(
            key: 'net_cash_flow',
            name: 'Net Cash Flow',
            description: 'Net liquidity movement computed as cash collected minus cash refunded.',
            category: MetricCategory::CASH,
            dataType: MetricDataType::CURRENCY,
            aggregationType: AggregationType::SUM,
            sourceDomain: 'finance',
            formula: 'cash_inflow - cash_refunded',
            grain: TimeGrain::DAILY,
            allowedDimensions: ['currency', 'gateway'],
            isDerived: true,
            unit: 'currency'
        ));

        // -------------------------------------------------------------
        // 3. SUBSCRIPTION & REVENUE METRICS (Normalized Run-Rates)
        // -------------------------------------------------------------
        $this->register(new MetricDefinition(
            key: 'mrr',
            name: 'Monthly Recurring Revenue (MRR)',
            description: 'Normalized monthly subscription value of all active hosting and recurring service contracts.',
            category: MetricCategory::SUBSCRIPTION,
            dataType: MetricDataType::CURRENCY,
            aggregationType: AggregationType::SNAPSHOT,
            sourceDomain: 'services',
            formula: 'SUM(services.recurring_amount * (30.0 / services.billing_cycle_days)) WHERE status = "active"',
            grain: TimeGrain::POINT_IN_TIME,
            allowedDimensions: ['currency', 'product_id', 'server_pool', 'billing_cycle'],
            unit: 'currency/month'
        ));

        $this->register(new MetricDefinition(
            key: 'arr',
            name: 'Annual Run Rate (ARR)',
            description: 'Annualized recurring revenue based on current MRR (MRR * 12).',
            category: MetricCategory::SUBSCRIPTION,
            dataType: MetricDataType::CURRENCY,
            aggregationType: AggregationType::SNAPSHOT,
            sourceDomain: 'services',
            formula: 'mrr * 12',
            grain: TimeGrain::POINT_IN_TIME,
            allowedDimensions: ['currency', 'product_id'],
            isDerived: true,
            unit: 'currency/year'
        ));

        $this->register(new MetricDefinition(
            key: 'new_mrr',
            name: 'New MRR',
            description: 'Monthly recurring revenue added from new customer acquisitions in the period.',
            category: MetricCategory::SUBSCRIPTION,
            dataType: MetricDataType::CURRENCY,
            aggregationType: AggregationType::SUM,
            sourceDomain: 'services',
            formula: 'SUM(new_services.mrr)',
            grain: TimeGrain::MONTHLY,
            allowedDimensions: ['currency', 'product_id', 'acquisition_channel'],
            unit: 'currency/month'
        ));

        $this->register(new MetricDefinition(
            key: 'expansion_mrr',
            name: 'Expansion MRR',
            description: 'Additional MRR gained from existing customers upgrading plans or adding resources.',
            category: MetricCategory::SUBSCRIPTION,
            dataType: MetricDataType::CURRENCY,
            aggregationType: AggregationType::SUM,
            sourceDomain: 'services',
            formula: 'SUM(upgrades.mrr_delta)',
            grain: TimeGrain::MONTHLY,
            allowedDimensions: ['currency', 'product_id'],
            unit: 'currency/month'
        ));

        $this->register(new MetricDefinition(
            key: 'contraction_mrr',
            name: 'Contraction MRR',
            description: 'MRR reduced due to existing customers downgrading plans or removing addons.',
            category: MetricCategory::SUBSCRIPTION,
            dataType: MetricDataType::CURRENCY,
            aggregationType: AggregationType::SUM,
            sourceDomain: 'services',
            formula: 'SUM(downgrades.mrr_delta)',
            grain: TimeGrain::MONTHLY,
            allowedDimensions: ['currency', 'product_id'],
            unit: 'currency/month'
        ));

        $this->register(new MetricDefinition(
            key: 'churned_mrr',
            name: 'Churned MRR',
            description: 'MRR lost due to service cancellations and non-renewals in the period.',
            category: MetricCategory::SUBSCRIPTION,
            dataType: MetricDataType::CURRENCY,
            aggregationType: AggregationType::SUM,
            sourceDomain: 'services',
            formula: 'SUM(cancelled_services.mrr)',
            grain: TimeGrain::MONTHLY,
            allowedDimensions: ['currency', 'product_id', 'cancellation_reason'],
            unit: 'currency/month'
        ));

        $this->register(new MetricDefinition(
            key: 'active_subscriptions_count',
            name: 'Active Subscriptions Count',
            description: 'Total number of active, billable recurring service instances.',
            category: MetricCategory::SUBSCRIPTION,
            dataType: MetricDataType::COUNT,
            aggregationType: AggregationType::SNAPSHOT,
            sourceDomain: 'services',
            formula: 'COUNT(services.id) WHERE status = "active"',
            grain: TimeGrain::POINT_IN_TIME,
            allowedDimensions: ['product_id', 'server_pool', 'billing_cycle'],
            unit: 'services'
        ));

        // -------------------------------------------------------------
        // 4. SUPPORT & OPERATIONS METRICS
        // -------------------------------------------------------------
        $this->register(new MetricDefinition(
            key: 'tickets_opened_count',
            name: 'Support Tickets Opened',
            description: 'Count of new support tickets submitted during the period.',
            category: MetricCategory::SUPPORT,
            dataType: MetricDataType::COUNT,
            aggregationType: AggregationType::COUNT,
            sourceDomain: 'support',
            formula: 'COUNT(tickets.id)',
            grain: TimeGrain::DAILY,
            allowedDimensions: ['department_id', 'priority'],
            unit: 'tickets'
        ));

        $this->register(new MetricDefinition(
            key: 'first_response_time_avg',
            name: 'Average First Response Time',
            description: 'Average duration in minutes from ticket creation until first staff reply.',
            category: MetricCategory::SUPPORT,
            dataType: MetricDataType::DURATION,
            aggregationType: AggregationType::AVERAGE,
            sourceDomain: 'support',
            formula: 'AVG(tickets.first_response_minutes)',
            grain: TimeGrain::DAILY,
            allowedDimensions: ['department_id', 'priority'],
            unit: 'minutes'
        ));

        $this->register(new MetricDefinition(
            key: 'sla_compliance_rate',
            name: 'SLA Compliance Rate',
            description: 'Percentage of support tickets resolved within their contracted SLA timeline.',
            category: MetricCategory::SUPPORT,
            dataType: MetricDataType::PERCENTAGE,
            aggregationType: AggregationType::RATE,
            sourceDomain: 'support',
            formula: '(COUNT(compliant_tickets) / COUNT(total_sla_tickets)) * 100',
            grain: TimeGrain::MONTHLY,
            allowedDimensions: ['department_id', 'priority'],
            unit: '%'
        ));
    }
}
