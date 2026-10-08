<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Actions;

final class ActionResult
{
    /**
     * @param array<string, mixed> $output
     */
    public function __construct(
        private readonly string $actionId,
        private readonly string $actionType,
        private readonly ActionStatus $status,
        private readonly array $output = [],
        private readonly ?string $errorMessage = null,
        private readonly float $executionTimeMs = 0.0
    ) {
    }

    public static function success(
        string $actionId,
        string $actionType,
        array $output = [],
        float $executionTimeMs = 0.0
    ): self {
        return new self($actionId, $actionType, ActionStatus::SUCCESS, $output, null, $executionTimeMs);
    }

    public static function failed(
        string $actionId,
        string $actionType,
        string $errorMessage,
        array $output = [],
        float $executionTimeMs = 0.0
    ): self {
        return new self($actionId, $actionType, ActionStatus::FAILED, $output, $errorMessage, $executionTimeMs);
    }

    public static function skipped(
        string $actionId,
        string $actionType,
        string $reason,
        float $executionTimeMs = 0.0
    ): self {
        return new self($actionId, $actionType, ActionStatus::SKIPPED, ['reason' => $reason], null, $executionTimeMs);
    }

    public function getActionId(): string
    {
        return $this->actionId;
    }

    public function getActionType(): string
    {
        return $this->actionType;
    }

    public function getStatus(): ActionStatus
    {
        return $this->status;
    }

    public function isSuccess(): bool
    {
        return $this->status === ActionStatus::SUCCESS;
    }

    public function isSuccessful(): bool
    {
        return $this->isSuccess();
    }

    public function isFailed(): bool
    {
        return $this->status === ActionStatus::FAILED;
    }

    public function isSkipped(): bool
    {
        return $this->status === ActionStatus::SKIPPED;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOutput(): array
    {
        return $this->output;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getExecutionTimeMs(): float
    {
        return $this->executionTimeMs;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'action_id' => $this->actionId,
            'action_type' => $this->actionType,
            'status' => $this->status->value,
            'output' => $this->output,
            'error_message' => $this->errorMessage,
            'execution_time_ms' => $this->executionTimeMs,
        ];
    }
}
