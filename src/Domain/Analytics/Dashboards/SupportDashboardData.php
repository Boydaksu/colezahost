<?php

declare(strict_types=1);

namespace Coleza\Domain\Analytics\Dashboards;

use JsonSerializable;

final class SupportDashboardData implements JsonSerializable
{
    /**
     * @param array<string, int> $departmentBreakdown
     * @param array<string, int> $priorityBreakdown
     */
    public function __construct(
        private readonly string $startDate,
        private readonly string $endDate,
        private readonly int $ticketsOpenedCount,
        private readonly int $ticketsResolvedCount,
        private readonly int $openTicketsBacklog,
        private readonly float $firstResponseTimeAvgMinutes,
        private readonly float $slaComplianceRatePercent,
        private readonly array $departmentBreakdown = [],
        private readonly array $priorityBreakdown = []
    ) {
    }

    public function getStartDate(): string
    {
        return $this->startDate;
    }

    public function getEndDate(): string
    {
        return $this->endDate;
    }

    public function getTicketsOpenedCount(): int
    {
        return $this->ticketsOpenedCount;
    }

    public function getTicketsResolvedCount(): int
    {
        return $this->ticketsResolvedCount;
    }

    public function getOpenTicketsBacklog(): int
    {
        return $this->openTicketsBacklog;
    }

    public function getFirstResponseTimeAvgMinutes(): float
    {
        return $this->firstResponseTimeAvgMinutes;
    }

    public function getSlaComplianceRatePercent(): float
    {
        return $this->slaComplianceRatePercent;
    }

    public function getDepartmentBreakdown(): array
    {
        return $this->departmentBreakdown;
    }

    public function getPriorityBreakdown(): array
    {
        return $this->priorityBreakdown;
    }

    public function toArray(): array
    {
        return [
            'type' => DashboardType::SUPPORT->value,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'tickets_opened_count' => $this->ticketsOpenedCount,
            'tickets_resolved_count' => $this->ticketsResolvedCount,
            'open_tickets_backlog' => $this->openTicketsBacklog,
            'first_response_time_avg_minutes' => $this->firstResponseTimeAvgMinutes,
            'sla_compliance_rate_percent' => $this->slaComplianceRatePercent,
            'department_breakdown' => $this->departmentBreakdown,
            'priority_breakdown' => $this->priorityBreakdown,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
