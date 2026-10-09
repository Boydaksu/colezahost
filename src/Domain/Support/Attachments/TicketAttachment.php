<?php

declare(strict_types=1);

namespace Coleza\Domain\Support\Attachments;

use DateTimeImmutable;

final class TicketAttachment
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly int $id,
        private readonly int $ticketId,
        private readonly int $userId,
        private readonly string $originalFilename,
        private readonly string $storageKey,
        private readonly int $fileSizeBytes,
        private readonly string $mimeType,
        private readonly string $sha256Hash,
        private readonly bool $isPrivate = false,
        private readonly ?int $messageId = null,
        private readonly array $metadata = [],
        private readonly ?DateTimeImmutable $createdAt = null
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getTicketId(): int
    {
        return $this->ticketId;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getOriginalFilename(): string
    {
        return $this->originalFilename;
    }

    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    public function getFileSizeBytes(): int
    {
        return $this->fileSizeBytes;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getSha256Hash(): string
    {
        return $this->sha256Hash;
    }

    public function isPrivate(): bool
    {
        return $this->isPrivate;
    }

    public function getMessageId(): ?int
    {
        return $this->messageId;
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

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'ticket_id' => $this->ticketId,
            'message_id' => $this->messageId,
            'user_id' => $this->userId,
            'original_filename' => $this->originalFilename,
            'storage_key' => $this->storageKey,
            'file_size_bytes' => $this->fileSizeBytes,
            'mime_type' => $this->mimeType,
            'sha256_hash' => $this->sha256Hash,
            'is_private' => $this->isPrivate,
            'metadata' => $this->metadata,
            'created_at' => $this->createdAt?->format(DateTimeImmutable::ATOM),
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

        return new self(
            id: (int) $data['id'],
            ticketId: (int) $data['ticket_id'],
            userId: (int) $data['user_id'],
            originalFilename: (string) $data['original_filename'],
            storageKey: (string) $data['storage_key'],
            fileSizeBytes: (int) $data['file_size_bytes'],
            mimeType: (string) $data['mime_type'],
            sha256Hash: (string) $data['sha256_hash'],
            isPrivate: (bool) ($data['is_private'] ?? false),
            messageId: isset($data['message_id']) && $data['message_id'] !== null ? (int) $data['message_id'] : null,
            metadata: $metadata,
            createdAt: $createdAt
        );
    }
}
