<?php

declare(strict_types=1);

namespace Coleza\Domain\Health;

final class SystemHealthReport
{
    /**
     * @param HealthStatus $overallStatus
     * @param array<string, ComponentHealthResult> $components
     * @param string $checkedAt
     * @param array<string, mixed> $systemInfo
     */
    public function __construct(
        private HealthStatus $overallStatus,
        private array $components = [],
        private ?string $checkedAt = null,
        private array $systemInfo = []
    ) {
        $this->checkedAt = $checkedAt ?? date('c');
    }

    public function getOverallStatus(): HealthStatus
    {
        return $this->overallStatus;
    }

    /**
     * @return array<string, ComponentHealthResult>
     */
    public function getComponents(): array
    {
        return $this->components;
    }

    public function getComponent(string $name): ?ComponentHealthResult
    {
        return $this->components[$name] ?? null;
    }

    public function getCheckedAt(): string
    {
        return (string) $this->checkedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSystemInfo(): array
    {
        return $this->systemInfo;
    }

    public function isPassing(): bool
    {
        return $this->overallStatus->isPassing();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $componentsArray = [];
        foreach ($this->components as $key => $comp) {
            $componentsArray[$key] = $comp->toArray();
        }

        return [
            'overall_status' => $this->overallStatus->value,
            'is_passing' => $this->isPassing(),
            'checked_at' => $this->checkedAt,
            'components' => $componentsArray,
            'system_info' => $this->systemInfo,
        ];
    }
}
