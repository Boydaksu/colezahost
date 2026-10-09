<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Dashboards;

enum DashboardType: string
{
    case OWNER = 'owner';
    case FINANCE = 'finance';
    case SALES = 'sales';
    case OPERATIONS = 'operations';
    case SUPPORT = 'support';
}
