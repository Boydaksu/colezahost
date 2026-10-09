<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Subscription;

enum MrrMovementType: string
{
    case NEW = 'new';
    case EXPANSION = 'expansion';
    case CONTRACTION = 'contraction';
    case CHURN = 'churn';
    case REACTIVATION = 'reactivation';
}
