<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Reconciliation;

enum ReconciliationStatus: string
{
    case MATCHED = 'matched';
    case DISCREPANCY_DETECTED = 'discrepancy_detected';
    case INSUFFICIENT_DATA = 'insufficient_data';
}
