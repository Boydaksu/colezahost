<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Dashboards;

use JsonSerializable;

final class OperationsDashboardData implements JsonSerializable
{
    public function __construct(
        private readonly string $asOfDate,
        private readonly int $activeHostingServices,
        private readonly int $suspendedServices,
        private readonly int $terminatedServices,
        private readonly int $domainsUnderManagement,
        private readonly int $domainsExpiringNext30Days,
        private readonly int $openAbuseCasesCount,
        private readonly float $hypervisorCapacityUsedPercent = 0.0
    ) {
    }

    public function getAsOfDate(): string
    {
        return $this->asOfDate;
    }

    public function getActiveHostingServices(): int
    {
        return $this->activeHostingServices;
    }

    public function getSuspendedServices(): int
    {
        return $this->suspendedServices;
    }

    public function getTerminatedServices(): int
    {
        return $this->terminatedServices;
    }

    public function getDomainsUnderManagement(): int
    {
        return $this->domainsUnderManagement;
    }

    public function getDomainsExpiringNext30Days(): int
    {
        return $this->domainsExpiringNext30Days;
    }

    public function getOpenAbuseCasesCount(): int
    {
        return $this->openAbuseCasesCount;
    }

    public function getHypervisorCapacityUsedPercent(): float
    {
        return $this->hypervisorCapacityUsedPercent;
    }

    public function toArray(): array
    {
        return [
            'type' => DashboardType::OPERATIONS->value,
            'as_of_date' => $this->asOfDate,
            'active_hosting_services' => $this->activeHostingServices,
            'suspended_services' => $this->suspendedServices,
            'terminated_services' => $this->terminatedServices,
            'domains_under_management' => $this->domainsUnderManagement,
            'domains_expiring_next_30_days' => $this->domainsExpiringNext30Days,
            'open_abuse_cases_count' => $this->openAbuseCasesCount,
            'hypervisor_capacity_used_percent' => $this->hypervisorCapacityUsedPercent,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
