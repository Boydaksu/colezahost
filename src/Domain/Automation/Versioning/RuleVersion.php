<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Versioning;

use DateTimeImmutable;

final class RuleVersion
{
    /**
     * @param array<string, mixed> $snapshot
     */
    public function __construct(
        private readonly int $versionNumber,
        private readonly string $ruleId,
        private readonly string $name,
        private readonly string $description,
        private readonly string $triggerName,
        private readonly string $triggerType,
        private readonly int $priority,
        private readonly string $createdBy,
        private readonly string $changeSummary,
        private readonly array $snapshot = [],
        private readonly ?DateTimeImmutable $createdAt = null
    ) {
    }

    public function getVersionNumber(): int
    {
        return $this->versionNumber;
    }

    public function getRuleId(): string
    {
        return $this->ruleId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getTriggerName(): string
    {
        return $this->triggerName;
    }

    public function getTriggerType(): string
    {
        return $this->triggerType;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function getCreatedBy(): string
    {
        return $this->createdBy;
    }

    public function getChangeSummary(): string
    {
        return $this->changeSummary;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSnapshot(): array
    {
        return $this->snapshot;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt ?? new DateTimeImmutable();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version_number' => $this->versionNumber,
            'rule_id' => $this->ruleId,
            'name' => $this->name,
            'description' => $this->description,
            'trigger_name' => $this->triggerName,
            'trigger_type' => $this->triggerType,
            'priority' => $this->priority,
            'created_by' => $this->createdBy,
            'change_summary' => $this->changeSummary,
            'created_at' => $this->getCreatedAt()->format(DateTimeImmutable::ATOM),
            'snapshot' => $this->snapshot,
        ];
    }
}
