<?php

declare(strict_types=1);

namespace Coleza\Domain\Domains;

use DateTimeImmutable;

final class DomainTimelineEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private readonly ?int $id,
        private readonly int $domainId,
        private readonly string $eventType,
        private readonly string $description,
        private readonly array $payload = [],
        private readonly string $actorType = 'system',
        private readonly ?int $actorId = null,
        private readonly ?DateTimeImmutable $createdAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getActorType(): string
    {
        return $this->actorType;
    }

    public function getActorId(): ?int
    {
        return $this->actorId;
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
            'id' => $this->id,
            'domain_id' => $this->domainId,
            'event_type' => $this->eventType,
            'description' => $this->description,
            'payload' => $this->payload,
            'actor_type' => $this->actorType,
            'actor_id' => $this->actorId,
            'created_at' => $this->getCreatedAt()->format(DateTimeImmutable::ATOM),
        ];
    }
}
