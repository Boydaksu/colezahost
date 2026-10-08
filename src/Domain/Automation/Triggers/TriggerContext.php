<?php

declare(strict_types=1);

namespace Coleza\Domain\Automation\Triggers;

use DateTimeImmutable;

final class TriggerContext
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private readonly string $eventName,
        private array $payload = [],
        private readonly ?DateTimeImmutable $occurredAt = null,
        private readonly ?string $correlationId = null
    ) {
    }

    public function getEventName(): string
    {
        return $this->eventName;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt ?? new DateTimeImmutable();
    }

    public function getCorrelationId(): string
    {
        return $this->correlationId ?? bin2hex(random_bytes(8));
    }

    /**
     * Retrieve a value using dot notation (e.g. "service.billing.amount").
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if ($key === '') {
            return $this->payload;
        }

        $segments = explode('.', $key);
        $current = $this->payload;

        foreach ($segments as $segment) {
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];
            } elseif (is_object($current)) {
                if (isset($current->{$segment})) {
                    $current = $current->{$segment};
                } elseif (method_exists($current, 'get' . ucfirst($segment))) {
                    $current = $current->{'get' . ucfirst($segment)}();
                } elseif (method_exists($current, $segment)) {
                    $current = $current->{$segment}();
                } else {
                    return $default;
                }
            } else {
                return $default;
            }
        }

        return $current;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Mutate/set a value in the payload (supports dot notation).
     */
    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $target = &$this->payload;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $target[$segment] = $value;
            } else {
                if (!isset($target[$segment]) || !is_array($target[$segment])) {
                    $target[$segment] = [];
                }
                $target = &$target[$segment];
            }
        }
    }

    public function with(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->set($key, $value);
        return $clone;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->payload;
    }
}
