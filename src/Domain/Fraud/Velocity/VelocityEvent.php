<?php

declare(strict_types=1);

namespace Coleza\Domain\Fraud\Velocity;

use DateTimeImmutable;

final class VelocityEvent
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly ?int $id,
        private readonly string $eventType,
        private readonly string $identifierType,
        private readonly string $identifierValue,
        private readonly array $metadata = [],
        private readonly ?DateTimeImmutable $createdAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getIdentifierType(): string
    {
        return $this->identifierType;
    }

    public function getIdentifierValue(): string
    {
        return $this->identifierValue;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt ?? new DateTimeImmutable();
    }
}
