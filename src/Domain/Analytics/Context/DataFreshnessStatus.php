<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Context;

enum DataFreshnessStatus: string
{
    case REALTIME = 'realtime';           // Lag <= 60 seconds
    case NEAR_REALTIME = 'near_realtime'; // Lag <= 900 seconds (15 min)
    case STALE = 'stale';                 // Lag > 900 seconds
    case REBUILDING = 'rebuilding';       // Backfill in progress
}
