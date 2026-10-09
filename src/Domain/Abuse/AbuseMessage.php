<?php

declare(strict_types=1);

namespace Coleza\Domain\Abuse;

use DateTimeImmutable;

final class AbuseMessage
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $caseId,
        private readonly string $authorType,
        private readonly ?int $authorId,
        private readonly string $message,
        private readonly bool $isInternal = false,
        private readonly ?DateTimeImmutable $createdAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCaseId(): int
    {
        return $this->caseId;
    }

    public function getAuthorType(): string
    {
        return $this->authorType;
    }

    public function getAuthorId(): ?int
    {
        return $this->authorId;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function isInternal(): bool
    {
        return $this->isInternal;
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
            'case_id' => $this->caseId,
            'author_type' => $this->authorType,
            'author_id' => $this->authorId,
            'message' => $this->message,
            'is_internal' => $this->isInternal,
            'created_at' => $this->getCreatedAt()->format('Y-m-d H:i:s'),
        ];
    }
}
