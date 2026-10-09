<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Metrics;

enum MetricDataType: string
{
    case CURRENCY = 'currency';
    case PERCENTAGE = 'percentage';
    case COUNT = 'count';
    case RATIO = 'ratio';
    case DURATION = 'duration';
}
