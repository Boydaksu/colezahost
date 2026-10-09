<?php

declare(strict_types=1);

namespace Coleza\Domain\Support\Attachments;

use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use DateTimeImmutable;
use RuntimeException;

final class AttachmentService
{
    private string $attachmentsTable = 'support_ticket_attachments';

    public function __construct(
        private readonly Connection $db,
        private readonly AttachmentStorageInterface $storage,
        private readonly ?AttachmentSecurityPolicy $policy = null
    ) {
    }

    public function getSecurityPolicy(): AttachmentSecurityPolicy
    {
        return $this->policy ?? new AttachmentSecurityPolicy();
    }

    public function ensureTables(): void
    {
        $driver = $this->db->getDriverName();
        $autoInc = match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'INT AUTO_INCREMENT PRIMARY KEY',
        };

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id %s,
                ticket_id INT NOT NULL,
                message_id INT NULL,
                user_id INT NOT NULL,
                original_filename VARCHAR(255) NOT NULL,
                storage_key VARCHAR(255) NOT NULL UNIQUE,
                file_size_bytes INT NOT NULL,
                mime_type VARCHAR(100) NOT NULL,
                sha256_hash VARCHAR(64) NOT NULL,
                is_private TINYINT(1) NOT NULL DEFAULT 0,
                metadata_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            $this->attachmentsTable,
            $autoInc
        );
        $this->db->statement($sql);
    }

    /**
     * Validate, encrypt/store, and record an attachment for a support ticket.
     *
     * @param array<string, mixed> $metadata
     * @throws ValidationException
     */
    public function attachFile(
        int $ticketId,
        int $userId,
        string $filename,
        string $content,
        ?int $messageId = null,
        bool $isPrivate = false,
        array $metadata = []
    ): TicketAttachment {
        if ($ticketId <= 0) {
            throw new ValidationException(
                ['ticket_id' => 'Valid ticket ID is required.'],
                'Invalid ticket ID'
            );
        }

        if ($userId <= 0) {
            throw new ValidationException(
                ['user_id' => 'Valid uploader user ID is required.'],
                'Invalid uploader user ID'
            );
        }

        $policy = $this->getSecurityPolicy();
        $policy->validate($filename, $content);

        $sanitizedFilename = $policy->sanitizeFilename($filename);
        $fileSize = strlen($content);
        $mimeType = $policy->detectMimeType($sanitizedFilename);
        $sha256 = hash('sha256', $content);

        $storageKey = sprintf(
            'tickets/%d/%s_%s_%s',
            $ticketId,
            date('Ymd'),
            bin2hex(random_bytes(6)),
            $sanitizedFilename
        );

        $stored = $this->storage->put($storageKey, $content);
        if (!$stored) {
            throw new RuntimeException("Failed to persist attachment to storage key '{$storageKey}'.");
        }

        $now = new DateTimeImmutable();
        $nowStr = $now->format('Y-m-d H:i:s');

        $insertedId = $this->db->insert(
            $this->attachmentsTable,
            [
                'ticket_id' => $ticketId,
                'message_id' => $messageId,
                'user_id' => $userId,
                'original_filename' => $sanitizedFilename,
                'storage_key' => $storageKey,
                'file_size_bytes' => $fileSize,
                'mime_type' => $mimeType,
                'sha256_hash' => $sha256,
                'is_private' => $isPrivate ? 1 : 0,
                'metadata_json' => json_encode($metadata),
                'created_at' => $nowStr,
            ]
        );

        $id = (int) $insertedId;

        return new TicketAttachment(
            id: $id,
            ticketId: $ticketId,
            userId: $userId,
            originalFilename: $sanitizedFilename,
            storageKey: $storageKey,
            fileSizeBytes: $fileSize,
            mimeType: $mimeType,
            sha256Hash: $sha256,
            isPrivate: $isPrivate,
            messageId: $messageId,
            metadata: $metadata,
            createdAt: $now
        );
    }

    public function getAttachment(int $id): ?TicketAttachment
    {
        $row = $this->db->selectOne(
            sprintf('SELECT * FROM %s WHERE id = :id', $this->attachmentsTable),
            ['id' => $id]
        );

        if ($row === null) {
            return null;
        }

        return TicketAttachment::fromArray($row);
    }

    public function requireAttachment(int $id): TicketAttachment
    {
        $att = $this->getAttachment($id);
        if ($att === null) {
            throw new ValidationException(
                ['attachment_id' => "Attachment with ID {$id} not found."],
                'Attachment not found'
            );
        }

        return $att;
    }

    /**
     * @return array<int, TicketAttachment>
     */
    public function getTicketAttachments(int $ticketId, bool $includePrivate = false): array
    {
        $sql = sprintf(
            'SELECT * FROM %s WHERE ticket_id = :ticket_id %s ORDER BY id ASC',
            $this->attachmentsTable,
            $includePrivate ? '' : 'AND is_private = 0'
        );

        $rows = $this->db->select($sql, ['ticket_id' => $ticketId]);

        return array_map(fn (array $r) => TicketAttachment::fromArray($r), $rows);
    }

    /**
     * @return array<int, TicketAttachment>
     */
    public function getMessageAttachments(int $messageId, bool $includePrivate = false): array
    {
        $sql = sprintf(
            'SELECT * FROM %s WHERE message_id = :message_id %s ORDER BY id ASC',
            $this->attachmentsTable,
            $includePrivate ? '' : 'AND is_private = 0'
        );

        $rows = $this->db->select($sql, ['message_id' => $messageId]);

        return array_map(fn (array $r) => TicketAttachment::fromArray($r), $rows);
    }

    /**
     * Read and verify attachment content against stored SHA-256 integrity checksum.
     *
     * @throws RuntimeException
     */
    public function readContent(int $id): string
    {
        $attachment = $this->requireAttachment($id);

        $content = $this->storage->get($attachment->getStorageKey());
        if ($content === null) {
            throw new RuntimeException("Attachment payload for ID {$id} missing from storage.");
        }

        $calculatedHash = hash('sha256', $content);
        if (!hash_equals($attachment->getSha256Hash(), $calculatedHash)) {
            throw new RuntimeException("Attachment integrity check failed for ID {$id}: SHA256 checksum mismatch.");
        }

        return $content;
    }

    public function deleteAttachment(int $id, int $actorUserId, bool $isStaff = false): bool
    {
        $attachment = $this->requireAttachment($id);

        if (!$isStaff && $attachment->getUserId() !== $actorUserId) {
            throw new ValidationException(
                ['authorization' => 'You are not authorized to delete this attachment.'],
                'Unauthorized attachment deletion'
            );
        }

        $this->storage->delete($attachment->getStorageKey());
        $affected = $this->db->delete($this->attachmentsTable, 'id = :where_id', ['where_id' => $id]);

        return $affected > 0;
    }

    public function canAccess(TicketAttachment $attachment, int $userId, bool $isStaff, int $ticketOwnerUserId): bool
    {
        if ($isStaff) {
            return true;
        }

        if ($attachment->isPrivate()) {
            return false;
        }

        return $userId === $ticketOwnerUserId || $userId === $attachment->getUserId();
    }
}
