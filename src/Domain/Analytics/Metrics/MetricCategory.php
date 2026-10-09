<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Metrics;

/**
 * Metric Category taxonomy enforcing strict separation of
 * Invoice (billing obligations), Cash (bank/gateway liquidity),
 * and Subscription/MRR (normalized run-rate) semantics.
 */
enum MetricCategory: string
{
    case INVOICE = 'invoice';
    case CASH = 'cash';
    case REVENUE = 'revenue';
    case SUBSCRIPTION = 'subscription';
    case OPERATIONS = 'operations';
    case SUPPORT = 'support';
}
