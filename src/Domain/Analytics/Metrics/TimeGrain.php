<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Metrics;

enum TimeGrain: string
{
    case HOURLY = 'hourly';
    case DAILY = 'daily';
    case MONTHLY = 'monthly';
    case ANNUAL = 'annual';
    case POINT_IN_TIME = 'point_in_time';
}
