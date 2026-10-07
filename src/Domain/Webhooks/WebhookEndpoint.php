<?php

declare(strict_types=1);

namespace Coleza\Domain\Webhooks;

final class WebhookEndpoint
{
    /**
     * @param array<string> $events
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private ?int $id,
        private string $targetUrl,
        private string $secret,
        private array $events = ['*'],
        private bool $isActive = true,
        private ?int $organizationId = null,
        private ?int $userId = null,
        private ?string $description = null,
        private ?string $createdAt = null,
        private ?string $updatedAt = null,
        private array $metadata = []
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTargetUrl(): string
    {
        return $this->targetUrl;
    }

    public function getSecret(): string
    {
        return $this->secret;
    }

    /**
     * @return array<string>
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getOrganizationId(): ?int
    {
        return $this->organizationId;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function matchesEvent(string $eventType): bool
    {
        if (in_array('*', $this->events, true)) {
            return true;
        }

        return in_array($eventType, $this->events, true);
    }
}
