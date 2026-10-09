<?php

declare(strict_types=1);

namespace Coleza\Domain\Support\Context;

use DateTimeImmutable;

final class CommandExecutionResult
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $action,
        public readonly bool $success,
        public readonly string $message,
        public readonly array $data = [],
        public readonly ?DateTimeImmutable $executedAt = null
    ) {
    }

    public static function successful(string $action, string $message, array $data = []): self
    {
        return new self(
            action: $action,
            success: true,
            message: $message,
            data: $data,
            executedAt: new DateTimeImmutable()
        );
    }

    public static function failed(string $action, string $message, array $data = []): self
    {
        return new self(
            action: $action,
            success: false,
            message: $message,
            data: $data,
            executedAt: new DateTimeImmutable()
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'success' => $this->success,
            'message' => $this->message,
            'data' => $this->data,
            'executed_at' => ($this->executedAt ?? new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
        ];
    }
}
