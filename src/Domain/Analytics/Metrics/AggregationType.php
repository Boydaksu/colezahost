<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Metrics;

enum AggregationType: string
{
    case SUM = 'sum';
    case AVERAGE = 'average';
    case COUNT = 'count';
    case SNAPSHOT = 'snapshot';
    case RATE = 'rate';
}
