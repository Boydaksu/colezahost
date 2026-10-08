<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Engine;

use Coleza\Domain\Automation\Approval\ApprovalRequirement;
use Coleza\Domain\Automation\Branching\IfElseBranch;
use Coleza\Domain\Automation\Execution\ExecutionMode;
use Coleza\Domain\Automation\Triggers\TriggerContext;
use Coleza\Domain\Automation\Triggers\TriggerInterface;

final class AutomationRule
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly string $id,
        private string $name,
        private readonly TriggerInterface $trigger,
        private readonly IfElseBranch $branch,
        private bool $enabled = true,
        private int $priority = 100,
        private string $description = '',
        private array $metadata = [],
        private int $version = 1,
        private ExecutionMode $executionMode = ExecutionMode::ACTIVE,
        private ?ApprovalRequirement $approvalRequirement = null,
        private int $delaySeconds = 0
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function enable(): self
    {
        $this->enabled = true;
        return $this;
    }

    public function disable(): self
    {
        $this->enabled = false;
        return $this;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): self
    {
        $this->priority = $priority;
        return $this;
    }

    public function getTrigger(): TriggerInterface
    {
        return $this->trigger;
    }

    public function getBranch(): IfElseBranch
    {
        return $this->branch;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(int $version): self
    {
        $this->version = $version;
        return $this;
    }

    public function getExecutionMode(): ExecutionMode
    {
        return $this->executionMode;
    }

    public function setExecutionMode(ExecutionMode $mode): self
    {
        $this->executionMode = $mode;
        return $this;
    }

    public function getApprovalRequirement(): ?ApprovalRequirement
    {
        return $this->approvalRequirement;
    }

    public function setApprovalRequirement(?ApprovalRequirement $requirement): self
    {
        $this->approvalRequirement = $requirement;
        return $this;
    }

    public function requiresApproval(): bool
    {
        return $this->approvalRequirement !== null;
    }

    public function getDelaySeconds(): int
    {
        return $this->delaySeconds;
    }

    public function setDelaySeconds(int $delaySeconds): self
    {
        $this->delaySeconds = max(0, $delaySeconds);
        return $this;
    }

    public function hasDelay(): bool
    {
        return $this->delaySeconds > 0;
    }

    public function matchesTrigger(TriggerContext $context): bool
    {
        return $this->trigger->matches($context);
    }
}
