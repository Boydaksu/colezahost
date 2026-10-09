<?php

declare(strict_types=1);

namespace Coleza\Domain\Support\CannedResponses;

use DateTimeImmutable;

final class CannedResponse
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly int $id,
        private readonly string $title,
        private readonly string $content,
        private readonly ?string $shortcut = null,
        private readonly ?int $departmentId = null,
        private readonly bool $isActive = true,
        private readonly array $metadata = [],
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getShortcut(): ?string
    {
        return $this->shortcut;
    }

    public function getDepartmentId(): ?int
    {
        return $this->departmentId;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Render response content with variable replacements.
     *
     * @param array<string, string|int|float> $variables
     */
    public function render(array $variables = []): string
    {
        $rendered = $this->content;
        foreach ($variables as $key => $value) {
            $rendered = str_replace(
                ['{{ ' . $key . ' }}', '{{' . $key . '}}'],
                (string) $value,
                $rendered
            );
        }

        return $rendered;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'content' => $this->content,
            'shortcut' => $this->shortcut,
            'department_id' => $this->departmentId,
            'is_active' => $this->isActive,
            'metadata' => $this->metadata,
            'created_at' => $this->createdAt?->format(DateTimeImmutable::ATOM),
            'updated_at' => $this->updatedAt?->format(DateTimeImmutable::ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $metadata = [];
        if (isset($data['metadata_json']) && is_string($data['metadata_json'])) {
            $decoded = json_decode($data['metadata_json'], true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        } elseif (isset($data['metadata']) && is_array($data['metadata'])) {
            $metadata = $data['metadata'];
        }

        $createdAt = !empty($data['created_at']) && is_string($data['created_at'])
            ? new DateTimeImmutable($data['created_at'])
            : null;

        $updatedAt = !empty($data['updated_at']) && is_string($data['updated_at'])
            ? new DateTimeImmutable($data['updated_at'])
            : null;

        return new self(
            id: (int) $data['id'],
            title: (string) $data['title'],
            content: (string) $data['content'],
            shortcut: isset($data['shortcut']) && $data['shortcut'] !== '' ? (string) $data['shortcut'] : null,
            departmentId: isset($data['department_id']) && $data['department_id'] !== null ? (int) $data['department_id'] : null,
            isActive: (bool) ($data['is_active'] ?? true),
            metadata: $metadata,
            createdAt: $createdAt,
            updatedAt: $updatedAt
        );
    }
}
