<?php

declare(strict_types=1);

namespace Coleza\Domain\Backup;

/**
 * Manifest record for a created backup archive.
 */
final class BackupManifest
{
    /**
     * @param string $backupId Unique backup identifier (e.g. 'pre_update_20261009_153000')
     * @param string $type Backup scope ('db', 'files', 'full')
     * @param string $sourceAppVersion Application version at the time of backup
     * @param int $fileSizeBytes Archive file size in bytes
     * @param string $sha256Checksum SHA-256 hash of the complete backup archive
     * @param string $createdAt ISO 8601 creation timestamp
     * @param array<string, string> $containedFiles Relative path => file SHA256 checksum
     * @param array<string, mixed> $metadata Additional context (reason, database tables, encryption)
     */
    public function __construct(
        private string $backupId,
        private string $type,
        private string $sourceAppVersion,
        private int $fileSizeBytes,
        private string $sha256Checksum,
        private string $createdAt,
        private array $containedFiles = [],
        private array $metadata = []
    ) {
    }

    public function getBackupId(): string
    {
        return $this->backupId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getSourceAppVersion(): string
    {
        return $this->sourceAppVersion;
    }

    public function getFileSizeBytes(): int
    {
        return $this->fileSizeBytes;
    }

    public function getSha256Checksum(): string
    {
        return $this->sha256Checksum;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    /**
     * @return array<string, string>
     */
    public function getContainedFiles(): array
    {
        return $this->containedFiles;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'backup_id' => $this->backupId,
            'type' => $this->type,
            'source_app_version' => $this->sourceAppVersion,
            'file_size_bytes' => $this->fileSizeBytes,
            'sha256_checksum' => $this->sha256Checksum,
            'created_at' => $this->createdAt,
            'contained_files' => $this->containedFiles,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            backupId: (string) ($data['backup_id'] ?? ''),
            type: (string) ($data['type'] ?? 'full'),
            sourceAppVersion: (string) ($data['source_app_version'] ?? '1.0.0'),
            fileSizeBytes: (int) ($data['file_size_bytes'] ?? 0),
            sha256Checksum: (string) ($data['sha256_checksum'] ?? ''),
            createdAt: (string) ($data['created_at'] ?? date('c')),
            containedFiles: is_array($data['contained_files'] ?? null) ? $data['contained_files'] : [],
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : []
        );
    }
}
